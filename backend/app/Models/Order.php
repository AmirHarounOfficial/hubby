<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'store_id',
        'external_id',
        'status',
        'total',
        'currency',
        'customer_name',
        'customer_email',
        'placed_at',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'placed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
