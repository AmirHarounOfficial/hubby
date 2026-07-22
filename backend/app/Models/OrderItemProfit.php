<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Materialized per-line profit rollup (spec 01 §3.3). */
class OrderItemProfit extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'order_id', 'order_item_id', 'store_id', 'sku',
        'product_variant_id', 'placed_on', 'quantity',
        'net_revenue_base', 'vat_base', 'cogs_base',
        'direct_fees_base', 'allocated_fees_base', 'ad_spend_base',
        'net_profit_base', 'margin_pct', 'is_estimated',
    ];

    protected $casts = [
        'placed_on' => 'date',
        'quantity' => 'integer',
        'is_estimated' => 'boolean',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
