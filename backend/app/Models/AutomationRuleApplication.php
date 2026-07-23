<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Idempotency ledger row (spec 02 §3.3). The unique index is the dedupe mechanism. */
class AutomationRuleApplication extends Model
{
    // The table has only `applied_at` (no created_at/updated_at); let Eloquent not manage them.
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'automation_rule_id', 'subject_type', 'subject_id',
        'fingerprint', 'automation_run_id', 'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];
}
