<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A structured address (spec 04 §3.2) — ship-to on an order, or a ship-from/return-to warehouse. */
class OrderAddress extends Model
{
    protected $fillable = [
        'organization_id', 'order_id', 'type', 'name', 'company', 'phone', 'phone_alt', 'email',
        'line1', 'line2', 'district', 'city', 'city_normalized', 'state', 'postal_code',
        'country_code', 'short_address', 'latitude', 'longitude', 'is_validated',
        'validation_source', 'validation_notes', 'raw',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_validated' => 'boolean',
        'validation_notes' => 'array',
        'raw' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
