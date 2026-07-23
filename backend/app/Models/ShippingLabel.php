<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stored label artefact (spec 04 §3.6). */
class ShippingLabel extends Model
{
    protected $fillable = [
        'shipment_id', 'shipment_package_id', 'type', 'format', 'disk', 'path', 'size_bytes',
        'checksum', 'page_count', 'carrier_label_id', 'printed_count', 'last_printed_at',
        'voided_at', 'expires_at',
    ];

    protected $casts = [
        'last_printed_at' => 'datetime',
        'voided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
