<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The COGS ledger (spec 01 §3.3, §4.3).
 *
 * Every unit of COGS recognised, and every reversal, is a row here — this is what makes a
 * margin auditable back to the purchase it came from. Reversals are negative `qty` rows rather
 * than deletes, so history is immutable.
 */
class CostLayerConsumption extends Model
{
    use HasFactory;

    public const REASON_SALE = 'sale';
    public const REASON_REFUND_RESTOCK = 'refund_restock';
    public const REASON_REFUND_WRITEOFF = 'refund_writeoff';
    public const REASON_CORRECTION = 'correction';

    public const REASONS = [
        self::REASON_SALE,
        self::REASON_REFUND_RESTOCK,
        self::REASON_REFUND_WRITEOFF,
        self::REASON_CORRECTION,
    ];

    protected $fillable = [
        'organization_id',
        'cost_layer_id',
        'order_id',
        'order_item_id',
        'qty',
        'unit_cost_base',
        'amount_base',
        'consumed_at',
        'reason',
        'reversal_of_id',
        'consumption_key',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_cost_base' => 'decimal:4',
        'amount_base' => 'decimal:4',
        'consumed_at' => 'datetime',
    ];

    /**
     * Deterministic idempotency keys — re-running a calculation must be a no-op, never a
     * double charge. Mirrors the formats in spec 01 §3.3.
     */
    public static function saleKey(int $orderItemId, int $costLayerId): string
    {
        return "sale:{$orderItemId}:{$costLayerId}";
    }

    public static function reversalKey(int $originalConsumptionId): string
    {
        return "rev:{$originalConsumptionId}";
    }

    public static function correctionKey(int $orderItemId, int $costLayerId, string $calcVersion): string
    {
        return "corr:{$orderItemId}:{$costLayerId}:{$calcVersion}";
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function costLayer(): BelongsTo
    {
        return $this->belongsTo(CostLayer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
