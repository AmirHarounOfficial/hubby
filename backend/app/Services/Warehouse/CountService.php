<?php

namespace App\Services\Warehouse;

use App\Exceptions\LocationScopedCountUnsupported;
use App\Jobs\PushInventoryJob;
use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle counting (spec 08 §4.4). Three rules carry this class:
 *
 * 1. **Blind by default.** expected_qty is never serialised to a non-supervisor device — not merely
 *    hidden in the UI, since an operator can read the JSON. Counting what the system expects instead
 *    of what is on the shelf defeats the entire exercise.
 *
 * 2. **Counted quantity is ABSOLUTE.** The client sends a running total with a monotonic client_seq
 *    and the highest seq wins; the server never sums. Replays are therefore idempotent by
 *    construction, independent of the uuid guard.
 *
 * 3. **Variance is computed against LIVE stock at approval, not the snapshot.** Applying a stale
 *    delta would erase sales that legitimately happened during the count. Both numbers are kept so a
 *    supervisor can see when stock moved mid-count.
 *
 * Nothing mutates stock without an explicit approval by an admin/owner.
 */
class CountService
{
    public function __construct(private readonly BarcodeResolver $resolver)
    {
    }

    /**
     * Location-scoped counts are hard-disabled while locations are advisory (§3.9): counting one bin
     * against a warehouse-wide scalar quantity yields a false variance. Allowed only when the
     * warehouse has at most one active location, where bin == warehouse.
     */
    public function assertScopeSupported(int $organizationId, string $scopeType, ?int $warehouseId = null): void
    {
        if ($scopeType !== 'location') {
            return;
        }

        $activeLocations = StockLocation::where('organization_id', $organizationId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('is_active', true)
            ->count();

        if ($activeLocations > 1) {
            throw new LocationScopedCountUnsupported();
        }
    }

    public function create(int $organizationId, array $attrs, ?int $userId = null): CountSession
    {
        $scopeType = $attrs['scope_type'] ?? 'sku_list';
        $this->assertScopeSupported($organizationId, $scopeType, $attrs['warehouse_id'] ?? null);

        return DB::transaction(function () use ($organizationId, $attrs, $userId, $scopeType) {
            $session = CountSession::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $attrs['warehouse_id'] ?? null,
                'code' => $this->nextCode($organizationId),
                'mode' => $attrs['mode'] ?? 'blind',
                'scope_type' => $scopeType,
                'scope_ref' => $attrs['scope_ref'] ?? null,
                'status' => 'in_progress',
                'assigned_user_id' => $attrs['assigned_user_id'] ?? null,
                'created_by_user_id' => $userId,
                'expected_snapshot_at' => now(),
                'started_at' => now(),
            ]);

            // Freeze expectations now so a long count is measured against a stable base.
            foreach ($this->scopedVariants($organizationId, $scopeType, $attrs['scope_ref'] ?? []) as $variant) {
                CountEntry::create([
                    'count_session_id' => $session->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product?->name,
                    'expected_qty' => (int) $variant->stock,
                    'counted_qty' => 0,
                    'status' => 'counted',
                ]);
            }

            $session->forceFill(['lines_total' => $session->entries()->count()])->save();

            return $session->fresh('entries');
        });
    }

    /**
     * Record an absolute counted quantity for an item.
     *
     * @param int|null $clientSeq monotonic per device; a lower seq than already stored is ignored
     */
    public function count(CountSession $session, string $barcode, int $countedQty, ?int $clientSeq = null, ?int $userId = null, array $opts = []): array
    {
        if (! $session->isCountable()) {
            return ['entry' => null, 'result' => 'session_closed'];
        }

        $resolved = $this->resolver->resolve((int) $session->organization_id, $barcode);
        if ($resolved->isUnknown()) {
            return ['entry' => null, 'result' => 'unknown_barcode'];
        }

        return DB::transaction(function () use ($session, $resolved, $countedQty, $clientSeq, $userId, $opts) {
            $entry = CountEntry::where('count_session_id', $session->id)
                ->when($resolved->variant,
                    fn ($q) => $q->where('product_variant_id', $resolved->variant->id),
                    fn ($q) => $q->where('product_id', $resolved->product?->id)->whereNull('product_variant_id'))
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                // An item found on the shelf that was not in scope is still real — count it.
                $entry = new CountEntry([
                    'count_session_id' => $session->id,
                    'product_id' => $resolved->product?->id,
                    'product_variant_id' => $resolved->variant?->id,
                    'sku' => $resolved->variant?->sku ?? $resolved->product?->sku,
                    'name' => $resolved->product?->name,
                    'expected_qty' => $resolved->variant?->stock ?? $resolved->product?->stock,
                    'counted_qty' => 0,
                    'status' => 'counted',
                ]);
            }

            // Highest client_seq wins — never a sum. An out-of-order replay is simply ignored.
            if ($clientSeq !== null && $entry->client_seq !== null && $clientSeq <= $entry->client_seq) {
                return ['entry' => $entry, 'result' => 'duplicate'];
            }

            $entry->counted_qty = $countedQty;
            $entry->client_seq = $clientSeq ?? $entry->client_seq;
            $entry->counted_by_user_id = $userId;
            $entry->counted_at = now();
            $entry->stock_location_id = $opts['stock_location_id'] ?? $entry->stock_location_id;
            $entry->note = $opts['note'] ?? $entry->note;
            $entry->save();

            $session->forceFill([
                'lines_counted' => $session->entries()->whereNotNull('counted_at')->count(),
            ])->save();

            return ['entry' => $entry->fresh(), 'result' => 'accepted'];
        });
    }

    /** Submit for supervisor review, with a variance preview against the frozen snapshot. */
    public function submit(CountSession $session): CountSession
    {
        if (! $session->isCountable()) {
            throw new \RuntimeException('COUNT_SESSION_NOT_OPEN');
        }

        $varianceUnits = 0;
        $varianceValue = 0.0;
        $variantLines = 0;

        foreach ($session->entries()->with('variant')->get() as $entry) {
            $preview = (int) $entry->counted_qty - (int) ($entry->expected_qty ?? 0);
            if ($preview !== 0) {
                $variantLines++;
                $varianceUnits += abs($preview);
                // Valued at PRICE, not cost — the schema has no cost field on variants, so the
                // dashboard must label this "at retail value" rather than implying true shrinkage.
                $varianceValue += $preview * (float) ($entry->variant?->price ?? 0);
            }
        }

        $session->forceFill([
            'status' => 'under_review',
            'submitted_at' => now(),
            'lines_variant' => $variantLines,
            'variance_units' => $varianceUnits,
            'variance_value' => round($varianceValue, 2),
        ])->save();

        return $session->fresh('entries');
    }

    /**
     * Approve and apply. Variance is recomputed against LIVE stock here, not the snapshot — applying
     * a stale delta would erase sales that happened during the count.
     */
    public function approve(CountSession $session, ?int $userId = null): CountSession
    {
        if ($session->status !== 'under_review') {
            throw new \RuntimeException('COUNT_SESSION_NOT_UNDER_REVIEW');
        }

        $batch = (string) Str::uuid();
        $touched = [];

        DB::transaction(function () use ($session, $userId, $batch, &$touched) {
            foreach ($session->entries()->get() as $entry) {
                $variant = $entry->product_variant_id ? ProductVariant::lockForUpdate()->find($entry->product_variant_id) : null;
                $product = (! $variant && $entry->product_id) ? Product::lockForUpdate()->find($entry->product_id) : null;

                $live = $variant?->stock ?? $product?->stock;
                if ($live === null) {
                    $entry->forceFill(['status' => 'approved'])->save();
                    continue;
                }

                $variance = (int) $entry->counted_qty - (int) $live;
                $entry->forceFill([
                    'live_qty_at_approval' => (int) $live,
                    'variance' => $variance,
                    'status' => 'approved',
                ])->save();

                if ($variance === 0) {
                    continue;
                }

                if ($variant) {
                    $variant->increment('stock', $variance);
                    $touched[] = $variant->fresh();
                } elseif ($product) {
                    $product->increment('stock', $variance);
                    foreach ($product->variants()->get() as $v) {
                        $touched[] = $v;
                    }
                }

                InventoryLog::create([
                    'product_id' => $entry->product_id,
                    'product_variant_id' => $entry->product_variant_id,
                    'change' => $variance,
                    'source' => 'Cycle Count',
                    'reason' => 'Count '.$session->code.' — counted '.$entry->counted_qty.' vs live '.$live,
                ]);
            }

            $session->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by_user_id' => $userId,
                'applied_log_batch' => $batch,
            ])->save();
        });

        foreach (collect($touched)->unique('id') as $variant) {
            PushInventoryJob::dispatch($variant);
        }

        return $session->fresh('entries');
    }

    public function reject(CountSession $session, string $reason): CountSession
    {
        if ($session->status !== 'under_review') {
            throw new \RuntimeException('COUNT_SESSION_NOT_UNDER_REVIEW');
        }

        $session->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        return $session->fresh();
    }

    /**
     * The device-facing shape. In blind mode expected_qty (and anything derived from it) is stripped
     * for non-supervisors — the field never leaves the server.
     */
    public function forDevice(CountSession $session, bool $isSupervisor): array
    {
        $session->loadMissing('entries');
        $hideExpected = $session->isBlind() && ! $isSupervisor;

        return array_merge($session->toArray(), [
            'entries' => $session->entries->map(function (CountEntry $entry) use ($hideExpected) {
                $row = $entry->toArray();
                if ($hideExpected) {
                    unset($row['expected_qty'], $row['variance'], $row['live_qty_at_approval']);
                }

                return $row;
            })->all(),
        ] + ($hideExpected ? ['variance_units' => null, 'variance_value' => null] : []));
    }

    /** @return \Illuminate\Support\Collection<int, ProductVariant> */
    private function scopedVariants(int $organizationId, string $scopeType, array $scopeRef)
    {
        $query = ProductVariant::whereHas('product', fn ($q) => $q->where('organization_id', $organizationId))
            ->with('product');

        if ($scopeType === 'sku_list' && ! empty($scopeRef['skus'])) {
            $query->whereIn('sku', $scopeRef['skus']);
        }

        return $query->get();
    }

    /** CC-YYMM-NNNN, sequential per org. */
    private function nextCode(int $organizationId): string
    {
        $seq = CountSession::where('organization_id', $organizationId)->count() + 1;

        return 'CC-'.now()->format('ym').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
