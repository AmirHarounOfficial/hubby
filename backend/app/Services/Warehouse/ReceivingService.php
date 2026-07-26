<?php

namespace App\Services\Warehouse;

use App\Jobs\PushInventoryJob;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use Illuminate\Support\Facades\DB;

/**
 * Inbound receiving (spec 08 §4.3).
 *
 * The rule that shapes this class: **stock moves on completion, not per scan.** A scan only updates
 * `receipt_items.qty_received`. A half-finished receipt that someone abandons must not have leaked
 * phantom stock into the catalogue, and an operator correcting a miscount mid-session must not
 * produce two opposing stock movements. One transaction at the end, one inventory_log per line.
 *
 * Damaged units are received but never become sellable — they are counted, reported, and excluded
 * from the stock delta.
 */
class ReceivingService
{
    public function __construct(private readonly BarcodeResolver $resolver)
    {
    }

    public function create(int $organizationId, array $attrs, ?int $userId = null): Receipt
    {
        $receipt = Receipt::create([
            'organization_id' => $organizationId,
            'warehouse_id' => $attrs['warehouse_id'] ?? null,
            'code' => $this->nextCode($organizationId),
            'type' => $attrs['type'] ?? 'inbound',
            'status' => 'draft',
            'supplier_name' => $attrs['supplier_name'] ?? null,
            'reference' => $attrs['reference'] ?? null,
            'expected_lines' => $attrs['expected_lines'] ?? null,
            'created_by_user_id' => $userId,
            'notes' => $attrs['notes'] ?? null,
        ]);

        // Informed receiving: seed a line per expectation so the operator sees the full manifest.
        foreach ($attrs['expected_lines'] ?? [] as $expected) {
            $resolved = $this->resolver->resolve($organizationId, (string) ($expected['sku'] ?? ''));
            ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'product_id' => $resolved->product?->id,
                'product_variant_id' => $resolved->variant?->id,
                'sku' => $expected['sku'] ?? null,
                'name' => $resolved->variant?->sku ?? $resolved->product?->name,
                'qty_expected' => (int) ($expected['qty'] ?? 0),
                'qty_received' => 0,
            ]);
        }

        return $receipt->fresh('items');
    }

    /**
     * Record a scanned unit against a receipt. Returns the affected line.
     * `qty` comes from the barcode's pack_size (a case barcode adds 12), or an explicit override.
     */
    public function scanIn(Receipt $receipt, string $barcode, int $qty = 1, array $opts = []): ReceiptItem
    {
        if (! $receipt->isOpen()) {
            throw new \RuntimeException('RECEIPT_NOT_OPEN');
        }

        $resolved = $this->resolver->resolve((int) $receipt->organization_id, $barcode);

        return DB::transaction(function () use ($receipt, $resolved, $qty, $opts, $barcode) {
            if ($receipt->status === 'draft') {
                $receipt->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
            }

            $damaged = max(0, (int) ($opts['qty_damaged'] ?? 0));

            // An unresolvable barcode is still received — as an unidentified line that applies NO
            // stock and surfaces as a task. Refusing it would strand real goods on the dock.
            if ($resolved->isUnknown()) {
                $line = ReceiptItem::firstOrNew([
                    'receipt_id' => $receipt->id,
                    'unidentified_barcode' => $resolved->barcode,
                ]);
                $line->qty_received = (int) $line->qty_received + $qty;
                $line->qty_damaged = (int) $line->qty_damaged + $damaged;
                $line->discrepancy_reason = 'unexpected_sku';
                $line->save();

                return $line;
            }

            $line = ReceiptItem::where('receipt_id', $receipt->id)
                ->when($resolved->variant,
                    fn ($q) => $q->where('product_variant_id', $resolved->variant->id),
                    fn ($q) => $q->where('product_id', $resolved->product?->id)->whereNull('product_variant_id'))
                ->first();

            if (! $line) {
                $line = new ReceiptItem([
                    'receipt_id' => $receipt->id,
                    'product_id' => $resolved->product?->id,
                    'product_variant_id' => $resolved->variant?->id,
                    'sku' => $resolved->variant?->sku ?? $resolved->product?->sku,
                    'name' => $resolved->product?->name,
                    'qty_expected' => null,           // unexpected line
                    'qty_received' => 0,
                ]);
            }

            $line->qty_received = (int) $line->qty_received + $qty;
            $line->qty_damaged = (int) $line->qty_damaged + $damaged;
            if (! empty($opts['stock_location_id'])) {
                $line->stock_location_id = $opts['stock_location_id'];
            }
            if (isset($opts['unit_cost'])) {
                $line->unit_cost = $opts['unit_cost'];
            }
            $line->save();

            return $line->fresh();
        });
    }

    /**
     * Complete the receipt: THIS is where stock moves (§4.3).
     *
     * Informed receiving with any non-zero discrepancy routes to `review` instead — a supervisor must
     * accept before the stock lands, because a miscount silently entering the catalogue is worse than
     * a receipt waiting for a human.
     */
    public function complete(Receipt $receipt, ?int $userId = null, bool $acceptDiscrepancies = false): Receipt
    {
        if (! $receipt->isOpen()) {
            throw new \RuntimeException('RECEIPT_NOT_OPEN');
        }

        $receipt->loadMissing('items');

        // Compute discrepancies for informed lines.
        foreach ($receipt->items as $line) {
            if ($line->qty_expected !== null) {
                $line->discrepancy = (int) $line->qty_received - (int) $line->qty_expected;
                if ($line->discrepancy !== 0 && ! $line->discrepancy_reason) {
                    $line->discrepancy_reason = $line->discrepancy > 0 ? 'over' : 'short';
                }
            }
            if ($line->qty_damaged > 0 && ! $line->discrepancy_reason) {
                $line->discrepancy_reason = 'damaged';
            }
            $line->save();
        }

        $hasDiscrepancy = $receipt->items()->where(fn ($q) => $q->where('discrepancy', '!=', 0)->orWhere('qty_damaged', '>', 0))->exists();

        if ($receipt->isInformed() && $hasDiscrepancy && ! $acceptDiscrepancies && $receipt->status !== 'review') {
            $receipt->forceFill(['status' => 'review'])->save();

            return $receipt->fresh('items');
        }

        $touchedVariants = [];

        DB::transaction(function () use ($receipt, $userId, &$touchedVariants) {
            foreach ($receipt->items()->get() as $line) {
                $delta = $line->sellableQty();
                if ($delta <= 0 || (! $line->product_id && ! $line->product_variant_id)) {
                    continue; // damaged-only or unidentified lines never raise stock
                }

                if ($line->product_variant_id && ($variant = ProductVariant::find($line->product_variant_id))) {
                    $variant->increment('stock', $delta);
                    $touchedVariants[] = $variant->fresh();
                } elseif ($line->product_id && ($product = Product::find($line->product_id))) {
                    $product->increment('stock', $delta);
                    foreach ($product->variants()->get() as $v) {
                        $touchedVariants[] = $v;
                    }
                }

                InventoryLog::create([
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'change' => $delta,
                    'source' => 'Warehouse Receive',
                    'reason' => 'Receipt '.$receipt->code.($line->qty_damaged ? ' ('.$line->qty_damaged.' damaged excluded)' : ''),
                ]);
            }

            $receipt->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'received_by_user_id' => $userId ?? $receipt->received_by_user_id,
            ])->save();
        });

        // Push the new levels to every connected channel, same as a manual adjustment.
        foreach (collect($touchedVariants)->unique('id') as $variant) {
            PushInventoryJob::dispatch($variant);
        }

        return $receipt->fresh('items');
    }

    public function cancel(Receipt $receipt): Receipt
    {
        if ($receipt->status === 'completed') {
            throw new \RuntimeException('RECEIPT_ALREADY_COMPLETED');
        }
        $receipt->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        return $receipt;
    }

    /** RC-YYMM-NNNN, sequential per org. */
    private function nextCode(int $organizationId): string
    {
        $seq = Receipt::where('organization_id', $organizationId)->count() + 1;

        return 'RC-'.now()->format('ym').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
