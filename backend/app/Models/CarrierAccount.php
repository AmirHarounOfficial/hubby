<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A merchant's account with a shipping carrier (spec 04 §3.1).
 *
 * `credentials` is `encrypted:array` — these are more dangerous than store tokens because they can
 * create billable shipments, so they never leave the API as anything but `has_credentials`, and are
 * always hidden from serialization.
 */
class CarrierAccount extends Model
{
    protected $fillable = [
        'organization_id', 'carrier_code', 'label', 'environment', 'credentials', 'account_number',
        'settings', 'ship_from_address_id', 'supported_services', 'is_active', 'is_default',
        'cod_enabled', 'last_validated_at', 'last_error',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'supported_services' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'cod_enabled' => 'boolean',
        'last_validated_at' => 'datetime',
    ];

    protected $appends = ['has_credentials'];

    public function getHasCredentialsAttribute(): bool
    {
        return ! empty($this->getRawOriginal('credentials'));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shipFromAddress(): BelongsTo
    {
        return $this->belongsTo(OrderAddress::class, 'ship_from_address_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
