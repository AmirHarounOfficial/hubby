<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only RMA audit row (spec 03 §3.4). */
class ReturnEvent extends Model
{
    protected $fillable = [
        'return_request_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'actor_label',
        'note', 'payload',
    ];

    protected $casts = ['payload' => 'array'];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
