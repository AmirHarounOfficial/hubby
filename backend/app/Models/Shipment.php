<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shipment (spec 04 §3.3) — the spine of the fulfilment lifecycle. `status` uses the normalized
 * tracking vocabulary (§4.2) plus the pre-transit states we own (draft/rated/label_purchased/...).
 */
class Shipment extends Model
{
    /** Pre-transit states Hubby owns; everything else in the vocabulary is carrier-driven. */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RATED = 'rated';
    public const STATUS_LABEL_PURCHASED = 'label_purchased';
    public const STATUS_AWAITING_PICKUP = 'awaiting_pickup';
    public const STATUS_CANCELLED = 'cancelled';

    /** Terminal statuses — polling stops and, for delivered, fulfilment completes. */
    public const FINAL_STATUSES = ['delivered', 'rto_delivered', 'cancelled', 'lost', 'damaged'];

    protected $fillable = [
        'organization_id', 'store_id', 'order_id', 'return_request_id', 'carrier_account_id',
        'carrier_code', 'service_code', 'service_name', 'direction', 'reference', 'tracking_number',
        'carrier_shipment_id', 'status', 'carrier_status_raw', 'carrier_status_code',
        'ship_from_address_id', 'ship_to_address_id', 'return_to_address_id', 'package_count',
        'total_weight_kg', 'declared_value', 'currency', 'is_cod', 'cod_amount', 'cod_currency',
        'cod_collected_amount', 'cod_collected_at', 'cod_remitted_at', 'shipping_cost',
        'shipping_cost_currency', 'charged_to_customer', 'insurance_amount', 'incoterm',
        'contents_description', 'pieces_description', 'special_instructions', 'manifest_id',
        'pickup_request_id', 'rate_id', 'label_format', 'tracking_url', 'public_tracking_slug',
        'estimated_delivery_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'last_tracked_at',
        'tracking_poll_attempts', 'pushed_to_platform_at', 'created_by_user_id', 'error_code',
        'error_message', 'raw_request', 'raw_response',
    ];

    protected $casts = [
        'total_weight_kg' => 'decimal:3',
        'declared_value' => 'decimal:2',
        'is_cod' => 'boolean',
        'cod_amount' => 'decimal:2',
        'cod_collected_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'charged_to_customer' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'cod_collected_at' => 'datetime',
        'cod_remitted_at' => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_tracked_at' => 'datetime',
        'pushed_to_platform_at' => 'datetime',
        'raw_request' => 'array',
        'raw_response' => 'array',
    ];

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

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

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    public function shipFromAddress(): BelongsTo
    {
        return $this->belongsTo(OrderAddress::class, 'ship_from_address_id');
    }

    public function shipToAddress(): BelongsTo
    {
        return $this->belongsTo(OrderAddress::class, 'ship_to_address_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }
}
