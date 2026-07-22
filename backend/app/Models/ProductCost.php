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

    /** Money is stored at 4 dp; 10^4 is the integer scaling factor for exact arithmetic. */
    private const SCALE = 10000;

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

    /**
     * Sum of the per-unit components. Kept in sync by ProductCostObserver.
     *
     * Exact fixed-point addition at 4 dp with no ext-bcmath dependency: scale each component to
     * integer minor units, sum as integers, then format back. A decimal(15,4) value scales to at
     * most ~1e15, comfortably inside a 64-bit int, so no precision is lost and nothing here
     * touches float arithmetic on the way out.
     */
    public function computeLandedUnitCost(): string
    {
        $scaled = 0;
        foreach (self::COST_COMPONENTS as $component) {
            $scaled += (int) round(((float) ($this->{$component} ?? 0)) * self::SCALE);
        }

        return self::formatScaled($scaled);
    }

    /** Render integer minor units back to a fixed 4 dp decimal string. */
    private static function formatScaled(int $scaled): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $abs = abs($scaled);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, self::SCALE), $abs % self::SCALE);
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
