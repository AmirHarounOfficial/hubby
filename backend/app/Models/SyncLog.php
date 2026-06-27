<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'store_id',
        'type',
        'status',
        'message',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
