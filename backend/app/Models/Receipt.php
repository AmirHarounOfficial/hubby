<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An inbound receipt (spec 08 §3.6). */
class Receipt extends Model
{
    public const STATUSES = ['draft', 'in_progress', 'review', 'completed', 'cancelled'];

    protected $fillable = [
        'organization_id', 'warehouse_id', 'code', 'type', 'status', 'supplier_name', 'reference',
        'expected_lines', 'created_by_user_id', 'received_by_user_id', 'started_at', 'completed_at',
        'cancelled_at', 'notes',
    ];

    protected $casts = [
        'expected_lines' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** Informed receiving compares against expectations; blind receiving has none. */
    public function isInformed(): bool
    {
        return ! empty($this->expected_lines);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'in_progress', 'review'], true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
