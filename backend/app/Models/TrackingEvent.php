<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One append-only tracking event (spec 04 §3.7), deduped per shipment by fingerprint. */
class TrackingEvent extends Model
{
    protected $fillable = [
        'shipment_id', 'shipment_package_id', 'status', 'raw_status', 'raw_code', 'description_en',
        'description_ar', 'location', 'city', 'country_code', 'signed_by', 'event_at', 'received_at',
        'source', 'is_exception', 'fingerprint', 'payload',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'received_at' => 'datetime',
        'is_exception' => 'boolean',
        'payload' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
