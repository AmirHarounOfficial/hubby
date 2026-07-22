<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A typed fee line on an order (spec 01 §3.3).
 *
 * `amount` is SIGNED: positive = cost to the merchant, negative = credit/reimbursement.
 * Order-level when `order_item_id` is null, item-level otherwise.
 */
class OrderFee extends Model
{
    use HasFactory;

    public const TYPE_COMMISSION = 'commission';
    public const TYPE_FULFILMENT = 'fulfilment';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_ADVERTISING = 'advertising';
    public const TYPE_TAX = 'tax';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_COMMISSION,
        self::TYPE_FULFILMENT,
        self::TYPE_SHIPPING,
        self::TYPE_PAYMENT,
        self::TYPE_REFUND,
        self::TYPE_STORAGE,
        self::TYPE_ADVERTISING,
        self::TYPE_TAX,
        self::TYPE_DISCOUNT,
        self::TYPE_OTHER,
    ];

    /**
     * Types deliberately EXCLUDED from total_fees.
     *
     * VAT is handled by the VAT model and discounts are already netted out of revenue —
     * including them here double-counts, which is the likeliest arithmetic bug in this feature.
     */
    public const NON_COST_TYPES = [
        self::TYPE_TAX,
        self::TYPE_DISCOUNT,
    ];

    public const SOURCES = ['api', 'settlement', 'webhook', 'raw_data', 'rule', 'manual', 'import'];

    protected $fillable = [
        'organization_id',
        'order_id',
        'order_item_id',
        'store_id',
        'type',
        'subtype',
        'amount',
        'currency',
        'fx_rate_to_base',
        'amount_base',
        'is_estimated',
        'source',
        'external_id',
        'settlement_id',
        'posted_at',
        'raw_data',
        'fee_key',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_base' => 'decimal:4',
        'fx_rate_to_base' => 'decimal:8',
        'is_estimated' => 'boolean',
        'posted_at' => 'datetime',
        'raw_data' => 'array',
    ];

    /** Only the fee types that actually reduce profit. */
    public function scopeCostBearing($query)
    {
        return $query->whereNotIn('type', self::NON_COST_TYPES);
    }

    /**
     * Deterministic idempotency key, so re-importing a settlement never duplicates fees.
     * Format: {order_external_id}:{type}:{subtype ?? '-'}:{external_id ?? md5(amount|posted_at)}
     */
    public static function buildFeeKey(
        string $orderExternalId,
        string $type,
        ?string $subtype,
        ?string $externalId,
        string|float|int|null $amount = null,
        ?string $postedAt = null,
    ): string {
        $discriminator = $externalId !== null && $externalId !== ''
            ? $externalId
            : md5(((string) $amount).'|'.((string) $postedAt));

        return implode(':', [$orderExternalId, $type, $subtype ?: '-', $discriminator]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
