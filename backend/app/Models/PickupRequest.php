<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A carrier pickup request (spec 04 §3.10). */
class PickupRequest extends Model
{
    protected $fillable = [
        'organization_id', 'carrier_account_id', 'carrier_code', 'reference', 'carrier_pickup_id',
        'status', 'pickup_address_id', 'pickup_date', 'ready_at', 'close_at', 'contact_name',
        'contact_phone', 'pieces', 'total_weight_kg', 'instructions', 'error_message',
        'raw_response', 'created_by_user_id',
    ];

    protected $casts = [
        'pickup_date' => 'date',
        'total_weight_kg' => 'decimal:3',
        'raw_response' => 'array',
    ];

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }
}
