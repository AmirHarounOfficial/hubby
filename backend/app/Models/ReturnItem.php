<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A per-line return detail (spec 03 §3.3). */
class ReturnItem extends Model
{
    public const DISPOSITIONS = ['restock', 'scrap', 'quarantine', 'return_to_vendor', 'repair', 'pending'];
    public const CONDITIONS = ['new', 'opened', 'used', 'damaged', 'defective', 'wrong_item', 'missing_parts', 'unknown'];

    protected $fillable = [
        'return_request_id', 'order_item_id', 'product_id', 'product_variant_id', 'sku', 'name',
        'quantity_requested', 'quantity_approved', 'quantity_received', 'quantity_restocked', 'quantity_scrapped',
        'unit_price', 'tax_amount', 'discount_amount', 'refund_amount', 'unit_cost',
        'reason_code', 'reason_note', 'condition', 'disposition', 'inspection_note',
        'exchange_variant_id', 'inventory_log_id', 'scrap_inventory_log_id',
        'received_at', 'inspected_at', 'restocked_at',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_approved' => 'integer',
        'quantity_received' => 'integer',
        'quantity_restocked' => 'integer',
        'quantity_scrapped' => 'integer',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
        'restocked_at' => 'datetime',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
