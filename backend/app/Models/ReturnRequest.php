<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An RMA header (spec 03 §3.2). */
class ReturnRequest extends Model
{
    use HasFactory;

    public const TYPES = ['customer_return', 'rto', 'damage_claim', 'exchange'];

    // Terminal statuses can't transition further (except failed → reopen, handled by the machine).
    public const TERMINAL = ['rejected', 'cancelled', 'exchanged', 'closed', 'refunded'];

    protected $fillable = [
        'organization_id', 'store_id', 'order_id', 'rma_number', 'external_id', 'type', 'origin',
        'status', 'resolution', 'reason_code', 'reason_note', 'is_marketplace_managed',
        'refund_responsibility', 'currency', 'items_subtotal', 'tax_refund', 'shipping_refund',
        'restocking_fee', 'return_shipping_cost', 'return_shipping_paid_by', 'total_refund',
        'refunded_amount', 'customer_name', 'customer_email', 'customer_phone', 'pickup_address',
        'carrier_code', 'tracking_number', 'return_shipment_id', 'outbound_shipment_id',
        'replacement_order_id', 'portal_token', 'created_by_user_id', 'approved_by_user_id',
        'requested_at', 'approved_at', 'rejected_at', 'shipped_at', 'received_at', 'inspected_at',
        'refunded_at', 'closed_at', 'sla_due_at', 'rejected_reason', 'raw_data',
    ];

    protected $casts = [
        'is_marketplace_managed' => 'boolean',
        'items_subtotal' => 'decimal:2',
        'tax_refund' => 'decimal:2',
        'shipping_refund' => 'decimal:2',
        'restocking_fee' => 'decimal:2',
        'return_shipping_cost' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'pickup_address' => 'array',
        'raw_data' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
        'refunded_at' => 'datetime',
        'closed_at' => 'datetime',
        'sla_due_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnEvent::class);
    }
}
