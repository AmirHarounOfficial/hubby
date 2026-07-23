<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical parcel within a shipment (spec 04 §3.4). */
class ShipmentPackage extends Model
{
    protected $fillable = [
        'shipment_id', 'sequence', 'tracking_number', 'carrier_package_id', 'package_type',
        'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'volumetric_weight_kg',
        'chargeable_weight_kg', 'declared_value', 'contents_description', 'reference',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
        'volumetric_weight_kg' => 'decimal:3',
        'chargeable_weight_kg' => 'decimal:3',
        'declared_value' => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }
}
