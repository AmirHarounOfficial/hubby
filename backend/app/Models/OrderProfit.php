<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Materialized per-order profit rollup (spec 01 §3.3). */
class OrderProfit extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'order_id', 'store_id', 'placed_on', 'base_currency',
        'gross_revenue_base', 'discounts_base', 'shipping_revenue_base', 'net_revenue_base',
        'vat_base', 'cogs_base', 'total_fees_base', 'fees_by_type', 'ad_spend_base',
        'refund_revenue_base', 'refund_cogs_base', 'lost_cogs_base',
        'net_profit_base', 'margin_pct', 'is_estimated', 'estimated_share', 'missing_cost',
        'calc_version', 'computed_at',
    ];

    protected $casts = [
        'placed_on' => 'date',
        'fees_by_type' => 'array',
        'computed_at' => 'datetime',
        'is_estimated' => 'boolean',
        'missing_cost' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
