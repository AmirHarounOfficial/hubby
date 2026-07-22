<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A cost definition for a SKU (spec 01 §3.3).
 *
 * Always resolved by (organization_id, sku) — never SKU alone, because product_variants.sku is
 * currently globally unique across tenants.
 */
class ProductCost extends Model
{
    use HasFactory, SoftDeletes;

    public const METHOD_FIXED = 'fixed';
    public const METHOD_FIFO = 'fifo';
    public const METHOD_PERIOD = 'period';
    public const METHOD_BATCH = 'batch';

    public const METHODS = [
        self::METHOD_FIXED,
        self::METHOD_FIFO,
        self::METHOD_PERIOD,
        self::METHOD_BATCH,
    ];

    public const SOURCES = ['manual', 'import', 'purchase_order', 'api', 'estimated'];

    /** Per-unit landed cost components, summed into `landed_unit_cost`. */
    public const COST_COMPONENTS = [
        'unit_cost',
        'freight_cost',
        'duty_cost',
        'prep_cost',
        'other_cost',
    ];

    protected $fillable = [
        'organization_id',
        'product_variant_id',
        'product_id',
        'sku',
        'store_id',
        'method',
        'unit_cost',
        'freight_cost',
        'duty_cost',
        'prep_cost',
        'other_cost',
        'landed_unit_cost',
        'currency',
        'fx_rate_to_base',
        'landed_unit_cost_base',
        'valid_from',
        'valid_to',
        'batch_ref',
        'period_end',
        'source',
        'note',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'period_end' => 'date',
        'unit_cost' => 'decimal:4',
        'freight_cost' => 'decimal:4',
        'duty_cost' => 'decimal:4',
        'prep_cost' => 'decimal:4',
        'other_cost' => 'decimal:4',
        'landed_unit_cost' => 'decimal:4',
        'landed_unit_cost_base' => 'decimal:4',
        'fx_rate_to_base' => 'decimal:8',
    ];

    /** Sum of the per-unit components. Kept in sync by ProductCostObserver. */
    public function computeLandedUnitCost(): string
    {
        $total = '0';
        foreach (self::COST_COMPONENTS as $component) {
            $total = bcadd($total, (string) ($this->{$component} ?? 0), 4);
        }

        return $total;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
