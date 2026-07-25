<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scan — accepted or rejected (spec 08 §3.8). The audit + idempotency spine. */
class ScanEvent extends Model
{
    protected $fillable = [
        'uuid', 'organization_id', 'user_id', 'device_id', 'session_type', 'session_id',
        'target_type', 'target_id', 'action', 'barcode', 'barcode_raw', 'symbology', 'input_method',
        'resolved_product_id', 'resolved_product_variant_id', 'stock_location_id', 'qty', 'result',
        'reject_reason', 'was_offline', 'client_scanned_at', 'client_seq', 'received_at',
        'response', 'payload',
    ];

    protected $casts = [
        'qty' => 'integer',
        'was_offline' => 'boolean',
        'client_scanned_at' => 'datetime',
        'received_at' => 'datetime',
        'response' => 'array',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
