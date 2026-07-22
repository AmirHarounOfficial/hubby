<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant-stated fee rule used when the marketplace doesn't report fees (spec 01 §3.3).
 */
class FeeRule extends Model
{
    use HasFactory;

    public const BASIS_PERCENT_OF_ITEM = 'percent_of_item';
    public const BASIS_PERCENT_OF_ORDER = 'percent_of_order';
    public const BASIS_FLAT_PER_ORDER = 'flat_per_order';
    public const BASIS_FLAT_PER_UNIT = 'flat_per_unit';
    public const BASIS_PER_KG = 'per_kg';

    public const BASES = [
        self::BASIS_PERCENT_OF_ITEM,
        self::BASIS_PERCENT_OF_ORDER,
        self::BASIS_FLAT_PER_ORDER,
        self::BASIS_FLAT_PER_UNIT,
        self::BASIS_PER_KG,
    ];

    /** Bases that produce one fee for the whole order rather than one per line. */
    public const ORDER_LEVEL_BASES = [
        self::BASIS_PERCENT_OF_ORDER,
        self::BASIS_FLAT_PER_ORDER,
    ];

    protected $fillable = [
        'organization_id', 'platform', 'store_id', 'category_id', 'sku',
        'type', 'subtype', 'basis', 'rate', 'min_amount', 'max_amount', 'currency',
        'effective_from', 'effective_to', 'priority', 'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'rate' => 'decimal:4',
        'min_amount' => 'decimal:4',
        'max_amount' => 'decimal:4',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Rules that could apply to an order, most specific first.
     *
     * Specificity is ordered in SQL so the caller can simply take the first match per (type,
     * subtype): SKU beats category beats store beats org-wide beats the system default
     * (organization_id null). Ties break on `priority` (lower wins), then newest effective_from.
     */
    public function scopeMatching(
        Builder $query,
        int $organizationId,
        string $platform,
        ?int $storeId,
        string $onDate,
    ): Builder {
        return $query
            ->where('is_active', true)
            ->where('platform', $platform)
            ->where(function ($q) use ($organizationId) {
                // Org-specific rules, plus system-wide defaults.
                $q->where('organization_id', $organizationId)->orWhereNull('organization_id');
            })
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id')->orWhere('store_id', $storeId);
            })
            ->whereDate('effective_from', '<=', $onDate)
            ->where(function ($q) use ($onDate) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $onDate);
            })
            ->orderByRaw('CASE WHEN sku IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN category_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN store_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN organization_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('priority')
            ->orderByDesc('effective_from');
    }

    public function isOrderLevel(): bool
    {
        return in_array($this->basis, self::ORDER_LEVEL_BASES, true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
