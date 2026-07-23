<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A persisted rate-shop result (spec 04 §3.8). */
class ShippingRate extends Model
{
    protected $fillable = [
        'organization_id', 'order_id', 'shipment_id', 'request_hash', 'carrier_account_id',
        'carrier_code', 'service_code', 'service_name', 'amount', 'currency', 'cod_fee',
        'fuel_surcharge', 'vat_amount', 'total_amount', 'transit_days_min', 'transit_days_max',
        'estimated_delivery_at', 'is_estimate', 'rank', 'is_selected', 'expires_at', 'raw',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cod_fee' => 'decimal:2',
        'fuel_surcharge' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'estimated_delivery_at' => 'datetime',
        'is_estimate' => 'boolean',
        'is_selected' => 'boolean',
        'expires_at' => 'datetime',
        'raw' => 'array',
    ];

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
