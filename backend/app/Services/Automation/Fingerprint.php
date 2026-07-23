<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Services\Automation\Contracts\AutomationSubject;
use Illuminate\Support\Arr;

/**
 * The idempotency fingerprint (spec 02 §4.5): a rule re-applies only when something it actually
 * reads changed. Built from the rule id + version + action ids + subject key + ONLY the fact fields
 * the rule's conditions reference. So correcting a field the rule ignores doesn't re-fire it, but
 * correcting one it depends on does.
 */
class Fingerprint
{
    public static function for(AutomationRule $rule, AutomationSubject $subject, array $facts): string
    {
        return hash('sha256', json_encode([
            'rule' => $rule->id,
            'rule_version' => $rule->version,
            'action_ids' => collect($rule->actions ?? [])->pluck('id')->filter()->sort()->values()->all(),
            'subject' => $subject->key(),
            'material' => Arr::only($facts, $rule->materialFields()),
        ], JSON_THROW_ON_ERROR));
    }
}
