<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A pick list (spec 08 §3.4/§4.1). */
class PickList extends Model
{
    public const STATUSES = ['draft', 'ready', 'in_progress', 'paused', 'review', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id', 'warehouse_id', 'code', 'type', 'status', 'assigned_user_id',
        'created_by_user_id', 'priority', 'item_count', 'picked_count', 'started_at',
        'completed_at', 'cancelled_at', 'notes', 'meta',
    ];

    protected $casts = [
        'priority' => 'integer',
        'item_count' => 'integer',
        'picked_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function isPickable(): bool
    {
        return $this->status === 'in_progress';
    }

    public function items(): HasMany
    {
        return $this->hasMany(PickListItem::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'pick_list_orders');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
