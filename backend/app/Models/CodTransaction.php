<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The merchant-side COD ledger row (spec 06 §3.2) — one per COD order. */
class CodTransaction extends Model
{
    /** Terminal states — no further transitions. */
    public const TERMINAL = ['cancelled', 'rto_closed', 'reconciled', 'written_off'];

    protected $fillable = [
        'organization_id', 'store_id', 'order_id', 'shipment_id', 'remittance_id', 'carrier_code',
        'awb_number', 'carrier_order_ref', 'currency', 'expected_amount', 'collected_amount',
        'remitted_amount', 'carrier_cod_fee', 'carrier_shipping_fee', 'carrier_rto_fee',
        'variance_amount', 'status', 'match_type', 'match_confidence', 'dispatched_at', 'collected_at',
        'due_at', 'remitted_at', 'reconciled_at', 'attempt_count', 'rto_reason_code', 'customer_key',
        'delivery_city', 'is_disputed', 'dispute_note', 'fees_posted_at', 'metadata',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'remitted_amount' => 'decimal:2',
        'carrier_cod_fee' => 'decimal:2',
        'carrier_shipping_fee' => 'decimal:2',
        'carrier_rto_fee' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'match_confidence' => 'decimal:2',
        'dispatched_at' => 'datetime',
        'collected_at' => 'datetime',
        'due_at' => 'datetime',
        'remitted_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'is_disputed' => 'boolean',
        'fees_posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['is_overdue', 'aging_bucket'];

    /** Derived (never stored — see §4.1): collected cash the carrier still owes past its due date. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'collected' && $this->due_at !== null && $this->due_at->isPast();
    }

    /** Age of collected-but-unremitted cash, bucketed for the aging report. */
    public function getAgingBucketAttribute(): ?string
    {
        if ($this->status !== 'collected' || ! $this->collected_at) {
            return null;
        }
        $days = $this->collected_at->diffInDays(now());

        return match (true) {
            $days <= 7 => '0-7',
            $days <= 14 => '8-14',
            $days <= 30 => '15-30',
            default => '30+',
        };
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
