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
    ];

    protected $casts = [
        'is_master' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
