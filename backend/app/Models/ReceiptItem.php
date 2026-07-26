<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One receipt line (spec 08 §3.6). */
class ReceiptItem extends Model
{
    protected $fillable = [
        'receipt_id', 'product_id', 'product_variant_id', 'sku', 'name', 'unidentified_barcode',
        'stock_location_id', 'qty_expected', 'qty_received', 'qty_damaged', 'discrepancy',
        'discrepancy_reason', 'unit_cost',
    ];

    protected $casts = [
        'qty_expected' => 'integer',
        'qty_received' => 'integer',
        'qty_damaged' => 'integer',
        'discrepancy' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    /** Units that will actually reach sellable stock. */
    public function sellableQty(): int
    {
        return max(0, (int) $this->qty_received - (int) $this->qty_damaged);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
