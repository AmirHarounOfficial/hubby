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
        // Automation (spec 02 §3.4)
        'tags', 'fulfillment_location', 'carrier_code', 'shipping_service', 'folder',
        'is_held', 'hold_reason', 'held_at', 'parent_order_id', 'split_index',
        'automation_state', 'internal_notes',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'placed_at' => 'datetime',
        'tags' => 'array',
        'is_held' => 'boolean',
        'held_at' => 'datetime',
        'automation_state' => 'array',
        'internal_notes' => 'array',
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
