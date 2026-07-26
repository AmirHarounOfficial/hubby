<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cycle-count session (spec 08 §3.7/§4.4). */
class CountSession extends Model
{
    public const STATUSES = ['draft', 'in_progress', 'submitted', 'under_review', 'approved', 'rejected', 'abandoned', 'cancelled'];

    protected $fillable = [
        'organization_id', 'warehouse_id', 'code', 'mode', 'scope_type', 'scope_ref', 'status',
        'assigned_user_id', 'created_by_user_id', 'approved_by_user_id', 'expected_snapshot_at',
        'started_at', 'submitted_at', 'approved_at', 'rejected_at', 'abandoned_at',
        'rejection_reason', 'lines_total', 'lines_counted', 'lines_variant', 'variance_units',
        'variance_value', 'applied_log_batch',
    ];

    protected $casts = [
        'scope_ref' => 'array',
        'expected_snapshot_at' => 'datetime',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'variance_units' => 'integer',
        'variance_value' => 'decimal:2',
    ];

    public function isBlind(): bool
    {
        return $this->mode === 'blind';
    }

    public function isCountable(): bool
    {
        return in_array($this->status, ['draft', 'in_progress'], true);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CountEntry::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
