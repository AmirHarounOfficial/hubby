<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An automation rule (spec 02 §3.1). */
class AutomationRule extends Model
{
    use HasFactory, SoftDeletes;

    public const TRIGGERS = [
        'order.created', 'order.updated', 'order.status_changed',
        'stock.below_threshold', 'sync.failed',
    ];

    protected $fillable = [
        'organization_id', 'name', 'description', 'trigger', 'conditions', 'actions',
        'priority', 'enabled', 'run_mode', 'stop_processing', 'version',
        'last_run_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'enabled' => 'boolean',
        'stop_processing' => 'boolean',
        'priority' => 'integer',
        'version' => 'integer',
        'last_run_at' => 'datetime',
    ];

    protected $attributes = [
        'conditions' => '{"match":"all","rules":[]}',
        'actions' => '[]',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Enabled rules for a trigger, in evaluation order (priority asc, then id asc). */
    public function scopeForTrigger($query, int $organizationId, string $trigger)
    {
        return $query->where('organization_id', $organizationId)
            ->where('trigger', $trigger)
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id');
    }

    /** The set of `field` names the rule's conditions read — the material inputs for its fingerprint. */
    public function materialFields(): array
    {
        $fields = [];
        $walk = function ($group) use (&$walk, &$fields) {
            foreach ($group['rules'] ?? [] as $rule) {
                if (isset($rule['field'])) {
                    $fields[] = $rule['field'];
                } elseif (isset($rule['rules'])) {
                    $walk($rule);
                }
            }
        };
        $walk($this->conditions ?? []);

        return array_values(array_unique($fields));
    }
}
