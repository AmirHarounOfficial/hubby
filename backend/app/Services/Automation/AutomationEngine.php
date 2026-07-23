<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRuleApplication;
use App\Models\AutomationRun;
use App\Services\Automation\Contracts\AutomationSubject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Runs every enabled rule for a trigger against one subject, in priority order (spec 02 §4.2).
 *
 * For each rule: evaluate its conditions against the subject's facts; on a match, claim an
 * idempotency slot (a duplicate claim → `deduped`, no re-apply) and either apply the actions or, in
 * dry-run, record what it *would* do. Every pass writes an explainable `automation_runs` row, and a
 * matching `stop_processing` rule halts the chain.
 */
class AutomationEngine
{
    public function __construct(
        private readonly RuleEvaluator $evaluator,
        private readonly ActionDispatcher $dispatcher,
    ) {
    }

    /**
     * @return array<int, AutomationRun>
     */
    public function run(AutomationSubject $subject, string $trigger, string $source = 'manual', int $chainDepth = 0): array
    {
        // A rule-caused write must never re-enter the engine (loop guard, §4.6).
        if (Automation::applying()) {
            return [];
        }

        $rules = AutomationRule::query()
            ->forTrigger($subject->organizationId(), $trigger)
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $facts = $subject->facts();
        $correlationId = (string) Str::uuid();
        $runs = [];

        foreach ($rules as $rule) {
            $started = hrtime(true);
            $evaluation = $this->evaluator->evaluate($rule->conditions ?? [], $facts);

            // Unknown field in the rule → skip, never a hard failure (§3.6).
            if ($evaluation['unknownField'] !== null) {
                $runs[] = $this->record($rule, $subject, $trigger, $source, $correlationId, $chainDepth, [
                    'outcome' => 'skipped', 'matched' => false, 'facts' => $facts,
                    'trace' => $evaluation['trace'], 'error' => 'unknown_field:'.$evaluation['unknownField'],
                    'duration' => $this->ms($started),
                ], persistSkip: true);

                continue;
            }

            if (! $evaluation['matched']) {
                $persist = config('automation.log_non_matches', false) || in_array($source, ['simulation', 'manual'], true);
                if ($persist) {
                    $runs[] = $this->record($rule, $subject, $trigger, $source, $correlationId, $chainDepth, [
                        'outcome' => 'skipped', 'matched' => false, 'facts' => $facts,
                        'trace' => $evaluation['trace'], 'duration' => $this->ms($started),
                    ], persistSkip: true);
                }

                continue;
            }

            // Matched.
            $rule->increment('matched_count');
            $fingerprint = Fingerprint::for($rule, $subject, $facts);

            if (! $this->claim($rule, $subject, $fingerprint)) {
                $runs[] = $this->record($rule, $subject, $trigger, $source, $correlationId, $chainDepth, [
                    'outcome' => 'deduped', 'matched' => true, 'facts' => $facts,
                    'trace' => $evaluation['trace'], 'duration' => $this->ms($started),
                ]);
                if ($rule->stop_processing) {
                    break;
                }

                continue;
            }

            $dryRun = $rule->run_mode === 'dry_run';
            $applied = $this->dispatcher->apply($rule->actions ?? [], $subject, $dryRun);

            $failed = collect($applied['results'])->contains(fn (ActionResult $r) => $r->status === 'failed');
            $outcome = $dryRun ? 'simulated' : ($failed ? 'partial' : 'matched');

            if (! $dryRun && ! $failed) {
                $rule->increment('applied_count');
            }
            if ($failed) {
                $rule->increment('failed_count');
            }

            $runs[] = $this->record($rule, $subject, $trigger, $source, $correlationId, $chainDepth, [
                'outcome' => $outcome, 'matched' => true, 'dry_run' => $dryRun, 'facts' => $facts,
                'trace' => $evaluation['trace'],
                'actions' => collect($applied['results'])->map(fn (ActionResult $r) => $r->toArray())->all(),
                'duration' => $this->ms($started),
            ]);

            $rule->forceFill(['last_run_at' => now()])->saveQuietly();

            if ($rule->stop_processing || $applied['terminated']) {
                break;
            }
        }

        return $runs;
    }

    /** Claim the idempotency slot. Returns false when the rule was already applied (the unique index). */
    private function claim(AutomationRule $rule, AutomationSubject $subject, string $fingerprint): bool
    {
        try {
            AutomationRuleApplication::create([
                'organization_id' => $subject->organizationId(),
                'automation_rule_id' => $rule->id,
                'subject_type' => $subject->type(),
                'subject_id' => $subject->id(),
                'fingerprint' => $fingerprint,
                'applied_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false; // already applied — the unique index is the dedupe
        }
    }

    private function record(
        AutomationRule $rule,
        AutomationSubject $subject,
        string $trigger,
        string $source,
        string $correlationId,
        int $chainDepth,
        array $data,
        bool $persistSkip = false,
    ): AutomationRun {
        return AutomationRun::create([
            'organization_id' => $subject->organizationId(),
            'automation_rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'rule_version' => $rule->version,
            'trigger' => $trigger,
            'subject_type' => $subject->type(),
            'subject_id' => $subject->id(),
            'subject_label' => $subject->label(),
            'correlation_id' => $correlationId,
            'source' => $source,
            'outcome' => $data['outcome'],
            'matched' => $data['matched'] ?? false,
            'dry_run' => $data['dry_run'] ?? false,
            'chain_depth' => $chainDepth,
            'facts' => $data['facts'] ?? null,
            'condition_trace' => $data['trace'] ?? null,
            'actions_applied' => $data['actions'] ?? null,
            'error' => $data['error'] ?? null,
            'duration_ms' => $data['duration'] ?? 0,
        ]);
    }

    private function ms(int $startedHrtime): int
    {
        return (int) ((hrtime(true) - $startedHrtime) / 1_000_000);
    }
}
