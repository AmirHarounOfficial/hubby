<?php

namespace App\Http\Controllers;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Order;
use App\Services\Automation\ActionDispatcher;
use App\Services\Automation\RuleEvaluator;
use App\Services\Automation\Subjects\OrderSubject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Automation rules management (spec 02 §5). Org-scoped but NOT behind cost.access — the rules
 * engine is ungated in every plan, and it isn't cost data.
 */
class AutomationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            AutomationRule::where('organization_id', $request->header('X-Organization-Id'))
                ->orderBy('priority')->orderBy('id')->get()
        );
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->find($request, $id));
    }

    public function store(Request $request)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $data = $this->validated($request);

        $rule = AutomationRule::create(array_merge($data, [
            'organization_id' => $organizationId,
            'version' => 1,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]));

        // fresh() so the response reflects DB defaults (enabled=false, run_mode=dry_run) even when
        // the caller didn't send them.
        return response()->json($rule->fresh(), 201);
    }

    public function update(Request $request, int $id)
    {
        $rule = $this->find($request, $id);
        $data = $this->validated($request);

        // Bump the version when the logic changes — it's part of the idempotency fingerprint, so a
        // materially edited rule re-applies to matching orders on their next trigger.
        if (json_encode($rule->conditions) !== json_encode($data['conditions'] ?? $rule->conditions)
            || json_encode($rule->actions) !== json_encode($data['actions'] ?? $rule->actions)) {
            $data['version'] = $rule->version + 1;
        }
        $data['updated_by'] = $request->user()?->id;

        $rule->update($data);

        return response()->json($rule->fresh());
    }

    public function toggle(Request $request, int $id)
    {
        $rule = $this->find($request, $id);
        $rule->update(['enabled' => ! $rule->enabled, 'updated_by' => $request->user()?->id]);

        return response()->json(['enabled' => $rule->enabled]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Rule deleted']);
    }

    /** Recent audit runs for the org (optionally for one rule). */
    public function runs(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        $runs = AutomationRun::where('organization_id', $organizationId)
            ->when($request->get('rule_id'), fn ($q, $id) => $q->where('automation_rule_id', $id))
            ->when($request->get('outcome'), fn ($q, $o) => $q->where('outcome', $o))
            ->latest('id')
            ->limit(min((int) $request->get('limit', 50), 200))
            ->get();

        return response()->json($runs);
    }

    /**
     * Dry-run a rule against one order without touching the ledger or writing runs — the rule
     * tester. Accepts an inline rule (conditions/actions) or an existing rule_id.
     */
    public function simulate(Request $request, RuleEvaluator $evaluator, ActionDispatcher $dispatcher)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer'],
            'conditions' => ['nullable', 'array'],
            'actions' => ['nullable', 'array'],
            'order_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['rule_id'])) {
            $rule = $this->find($request, (int) $data['rule_id']);
            $conditions = $rule->conditions;
            $actions = $rule->actions;
        } else {
            $conditions = $data['conditions'] ?? ['match' => 'all', 'rules' => []];
            $actions = $data['actions'] ?? [];
        }

        $order = Order::with(['items', 'store'])
            ->whereHas('store', fn ($q) => $q->where('organization_id', $organizationId))
            ->when($data['order_id'] ?? null, fn ($q, $oid) => $q->where('id', $oid))
            ->latest('id')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'No order available to simulate against.'], 422);
        }

        $subject = new OrderSubject($order);
        $facts = $subject->facts();
        $evaluation = $evaluator->evaluate($conditions, $facts);

        $actionsPreview = [];
        if ($evaluation['matched']) {
            $preview = $dispatcher->apply($actions, $subject, dryRun: true);
            $actionsPreview = collect($preview['results'])->map(fn ($r) => $r->toArray())->all();
        }

        return response()->json([
            'order_id' => $order->id,
            'order_label' => $order->external_id,
            'matched' => $evaluation['matched'],
            'unknown_field' => $evaluation['unknownField'],
            'condition_trace' => $evaluation['trace'],
            'actions_preview' => $actionsPreview,
            'facts' => $facts,
        ]);
    }

    /** Field catalogue + operators + actions + triggers, so the builder never hardcodes them. */
    public function schema()
    {
        return response()->json(\App\Services\Automation\AutomationSchema::describe());
    }

    private function find(Request $request, int $id): AutomationRule
    {
        return AutomationRule::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'trigger' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'conditions' => ['required', 'array'],
            'conditions.match' => ['required', 'in:all,any'],
            'conditions.rules' => ['present', 'array'],
            'actions' => ['required', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'enabled' => ['nullable', 'boolean'],
            'run_mode' => ['nullable', 'in:live,dry_run'],
            'stop_processing' => ['nullable', 'boolean'],
        ]);
    }
}
