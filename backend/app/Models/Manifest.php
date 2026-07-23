<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An end-of-day carrier handover manifest (spec 04 §3.9). */
class Manifest extends Model
{
    protected $fillable = [
        'organization_id', 'carrier_account_id', 'carrier_code', 'reference', 'carrier_manifest_id',
        'status', 'shipment_count', 'total_weight_kg', 'manifest_date', 'submitted_at', 'confirmed_at',
        'error_message', 'raw_response', 'created_by_user_id',
    ];

    protected $casts = [
        'total_weight_kg' => 'decimal:3',
        'manifest_date' => 'date',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'raw_response' => 'array',
    ];

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
