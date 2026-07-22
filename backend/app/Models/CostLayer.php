<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A FIFO inventory layer — one receipt of stock at a known cost (spec 01 §3.3, §4.3).
 *
 * `fx_rate_to_base` is frozen at acquisition: a layer's cost must never re-rate, or a past
 * period's margin would move whenever the exchange rate does.
 */
class CostLayer extends Model
{
    use HasFactory;

    public const SOURCES = [
        'opening',
        'purchase_order',
        'manual',
        'import',
        'return_restock',
        'adjustment',
        'estimated',
    ];

    protected $fillable = [
        'organization_id',
        'product_variant_id',
        'sku',
        'store_id',
        'source',
        'source_ref',
        'acquired_at',
        'qty_received',
        'qty_remaining',
        'unit_cost',
        'currency',
        'fx_rate_to_base',
        'unit_cost_base',
        'is_estimated',
        'created_by',
    ];

    protected $casts = [
        'acquired_at' => 'datetime',
        'qty_received' => 'integer',
        'qty_remaining' => 'integer',
        'unit_cost' => 'decimal:4',
        'unit_cost_base' => 'decimal:4',
        'fx_rate_to_base' => 'decimal:8',
        'is_estimated' => 'boolean',
    ];

    /**
     * Open layers for a SKU in FIFO order.
     *
     * Ordered by (acquired_at, id) — the id tiebreak is required, since two receipts can share
     * a timestamp and FIFO must be reproducible.
     */
    public function scopeFifoQueue($query, int $organizationId, string $sku)
    {
        return $query->where('organization_id', $organizationId)
            ->where('sku', $sku)
            ->where('qty_remaining', '>', 0)
            ->orderBy('acquired_at')
            ->orderBy('id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(CostLayerConsumption::class);
    }
}
