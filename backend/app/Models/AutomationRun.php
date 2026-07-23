<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An immutable audit record of one rule evaluation over one subject (spec 02 §3.2). */
class AutomationRun extends Model
{
    protected $fillable = [
        'organization_id', 'automation_rule_id', 'rule_name', 'rule_version', 'trigger',
        'subject_type', 'subject_id', 'subject_label', 'correlation_id', 'source', 'outcome',
        'matched', 'dry_run', 'chain_depth', 'facts', 'condition_trace', 'actions_applied',
        'error', 'duration_ms',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'dry_run' => 'boolean',
        'chain_depth' => 'integer',
        'facts' => 'array',
        'condition_trace' => 'array',
        'actions_applied' => 'array',
        'duration_ms' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
