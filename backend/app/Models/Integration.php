<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    protected $fillable = [
        'store_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'shop_domain',
        'platform_id',
    ];

    /**
     * Platform credentials never belong in an API response — `Store::with('integration')`
     * is serialised straight to the dashboard, so hide them at the model level.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Tokens are encrypted at rest so a database dump or backup leaks nothing usable.
     * Encryption is transparent: reads and writes through Eloquent see plaintext.
     */
    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
