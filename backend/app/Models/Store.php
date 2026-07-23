<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'domain',
        'platform',
        'status',
        'is_master',
        'last_synced_at',
        'default_ship_from_address_id',
        'shipping_settings',
    ];

    protected $casts = [
        'is_master' => 'boolean',
        'last_synced_at' => 'datetime',
        'shipping_settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    public function defaultShipFromAddress(): BelongsTo
    {
        return $this->belongsTo(OrderAddress::class, 'default_ship_from_address_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
