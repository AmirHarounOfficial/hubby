<?php

namespace App\Services\Warehouse;

use App\Models\Order;
use App\Models\PackSession;
use App\Models\PackSessionItem;
use Illuminate\Support\Facades\DB;

/**
 * Packing verification (spec 08 §4.2).
 *
 * The whole point: verify by barcode that what goes in the box is what the customer ordered. An item
 * in the wrong box is a return, a refund and a bad review — so a wrong-item scan is a HARD block,
 * never a warning.
 *
 * Multi-box orders get one session per package_index; an order line may only be packed into one of
 * them (K5), otherwise two boxes could each claim to hold the same unit.
 */
class PackService
{
    public function __construct(private readonly BarcodeResolver $resolver)
    {
    }

    public function open(Order $order, array $attrs = [], ?int $userId = null): PackSession
    {
        $order->loadMissing('items', 'store');
        $organizationId = (int) $order->store->organization_id;

        // K3 — a shipped or cancelled order must not be packed again.
        if (in_array(strtolower((string) $order->status), ['cancelled', 'canceled', 'shipped', 'delivered'], true)) {
            throw new \RuntimeException('ORDER_NOT_PACKABLE');
        }

        return DB::transaction(function () use ($order, $organizationId, $attrs, $userId) {
            $index = (int) (PackSession::where('order_id', $order->id)->max('package_index') ?? 0) + 1;

            $session = PackSession::create([
                'organization_id' => $organizationId,
                'order_id' => $order->id,
                'pick_list_id' => $attrs['pick_list_id'] ?? null,
                'warehouse_id' => $attrs['warehouse_id'] ?? null,
                'code' => $this->nextCode($organizationId),
                'status' => 'open',
                'package_index' => $index,
                'packaging_type' => $attrs['packaging_type'] ?? null,
                'packed_by_user_id' => $userId,
            ]);

            // Required quantities are what REMAINS unpacked across the order's other sessions, so a
            // second box only ever asks for what the first one did not take.
            foreach ($order->items as $item) {
                $alreadyPacked = (int) PackSessionItem::where('order_item_id', $item->id)
                    ->whereHas('session', fn ($q) => $q->where('order_id', $order->id)->where('status', '!=', 'voided'))
                    ->sum('qty_packed');

                $remaining = max(0, (int) $item->quantity - $alreadyPacked);
                if ($remaining <= 0) {
                    continue;
                }

                $resolved = $this->resolver->resolve($organizationId, (string) $item->sku);

                PackSessionItem::create([
                    'pack_session_id' => $session->id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $resolved->variant?->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'qty_required' => $remaining,
                    'qty_packed' => 0,
                ]);
            }

            return $session->fresh('items');
        });
    }

    /**
     * Scan an item into the box.
     *
     * @return array{line:?PackSessionItem, result:string}
     */
    public function pack(PackSession $session, string $barcode, int $qty = 1): array
    {
        if (! $session->isOpen()) {
            return ['line' => null, 'result' => 'session_closed'];
        }

        $resolved = $this->resolver->resolve((int) $session->organization_id, $barcode);
        if ($resolved->isUnknown()) {
            return ['line' => null, 'result' => 'unknown_barcode'];
        }

        $sku = $resolved->variant?->sku ?? $resolved->product?->sku;

        // Match on SKU, and on variant id ONLY when the scan actually resolved to a variant —
        // orWhere('product_variant_id', null) compiles to orWhereNull(), which would match every
        // line and silently defeat the wrong-item block below.
        $line = $session->items()
            ->where(function ($q) use ($sku, $resolved) {
                $q->where('sku', $sku);
                if ($resolved->variant) {
                    $q->orWhere('product_variant_id', $resolved->variant->id);
                }
            })
            ->first();

        // K1 — the item is not in this order (or not left to pack in this box). Hard block.
        if (! $line) {
            return ['line' => null, 'result' => 'wrong_item'];
        }

        // K2 — never pack more than the order needs.
        if ($qty > $line->remaining()) {
            return ['line' => $line, 'result' => 'over_pick'];
        }

        return DB::transaction(function () use ($session, $line, $qty) {
            $line->qty_packed = (int) $line->qty_packed + $qty;
            $line->save();

            $session->status = $session->items()->whereColumn('qty_packed', '<', 'qty_required')->exists()
                ? 'verifying'
                : 'verified';
            if ($session->status === 'verified' && ! $session->verified_at) {
                $session->verified_at = now();
            }
            $session->save();

            return ['line' => $line->fresh(), 'result' => 'accepted'];
        });
    }

    /**
     * Close the session. Requires full verification — closing a partially-packed box is exactly the
     * error that produces a short shipment.
     */
    public function complete(PackSession $session, array $attrs = []): PackSession
    {
        if (! $session->isOpen()) {
            throw new \RuntimeException('PACK_SESSION_CLOSED');
        }

        $unverified = $session->items()->whereColumn('qty_packed', '<', 'qty_required')->exists();
        if ($unverified) {
            throw new \RuntimeException('PACK_SESSION_NOT_VERIFIED');
        }

        $session->forceFill(array_filter([
            'weight_grams' => $attrs['weight_grams'] ?? $session->weight_grams,
            'length_mm' => $attrs['length_mm'] ?? $session->length_mm,
            'width_mm' => $attrs['width_mm'] ?? $session->width_mm,
            'height_mm' => $attrs['height_mm'] ?? $session->height_mm,
            'packaging_type' => $attrs['packaging_type'] ?? $session->packaging_type,
        ], fn ($v) => $v !== null) + [
            'status' => 'closed',
            'completed_at' => now(),
        ])->save();

        return $session->fresh('items');
    }

    public function void(PackSession $session): PackSession
    {
        if ($session->status === 'closed') {
            throw new \RuntimeException('PACK_SESSION_CLOSED');
        }
        // Counters are discarded but the scan_events stay — the audit trail outlives the session.
        $session->forceFill(['status' => 'voided', 'voided_at' => now()])->save();

        return $session;
    }

    /** PK-YYMM-NNNN, sequential per org. */
    private function nextCode(int $organizationId): string
    {
        $seq = PackSession::where('organization_id', $organizationId)->count() + 1;

        return 'PK-'.now()->format('ym').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
