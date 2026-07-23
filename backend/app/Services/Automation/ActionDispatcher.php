<?php

namespace App\Services\Automation;

use App\Models\Order;
use App\Services\Automation\Contracts\AutomationSubject;

/**
 * Applies a rule's actions to a subject and reports what each did (spec 02 §3.6, §4.1).
 *
 * Slice 1 implements the cheap, individually-idempotent order actions inline: tags are a set-union,
 * folder/location/carrier are last-write-wins, hold is a no-op when already held. The deferred
 * actions (notify, call_webhook, split_order) are queued in a later slice — this dispatcher records
 * them as `skipped` with a clear reason so a rule that uses one is never silently dropped.
 */
class ActionDispatcher
{
    // Actions executed outside the rule transaction, off the ingest path (spec §4.8).
    private const DEFERRED = ['notify', 'call_webhook'];

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array{
     *   results: array<int, ActionResult>, mutated: bool, terminated: bool,
     *   deferred: array<int, array<string, mixed>>, splitChildren: array<int, int>
     * }
     */
    public function apply(array $actions, AutomationSubject $subject, bool $dryRun = false): array
    {
        /** @var Order $order */
        $order = $subject->model();
        $results = [];
        $mutated = false;
        $deferred = [];
        $splitChildren = [];

        Automation::whileApplying(function () use ($actions, $order, $dryRun, &$results, &$mutated, &$deferred, &$splitChildren) {
            foreach ($actions as $i => $action) {
                $type = $action['type'] ?? 'unknown';
                $id = (string) ($action['id'] ?? $type.'-'.$i);
                $action['id'] = $id;
                $start = hrtime(true);

                if ($type === 'stop_processing') {
                    $results[] = new ActionResult($id, $type, 'applied', terminal: true);

                    continue;
                }

                // notify / call_webhook: queued by the engine after commit, never executed inline.
                if (in_array($type, self::DEFERRED, true)) {
                    $results[] = new ActionResult($id, $type, $dryRun ? 'skipped' : 'queued');
                    if (! $dryRun) {
                        $deferred[] = $action;
                    }

                    continue;
                }

                if ($type === 'split_order') {
                    if ($dryRun) {
                        $results[] = new ActionResult($id, $type, 'skipped', terminal: true);

                        continue;
                    }
                    try {
                        $children = $this->splitOrder($order, $action);
                        $splitChildren = $children;
                        $mutated = true;
                        $results[] = new ActionResult($id, $type, 'applied', result: ['children' => count($children)], mutated: true, terminal: true);
                    } catch (\Throwable $e) {
                        $results[] = new ActionResult($id, $type, 'failed', error: $e->getMessage());
                    }

                    continue;
                }

                try {
                    $changed = $dryRun ? $this->wouldChange($order, $type, $action) : $this->run($order, $type, $action);
                    $mutated = $mutated || ($changed && ! $dryRun);
                    $results[] = new ActionResult(
                        $id,
                        $type,
                        $dryRun ? 'skipped' : 'applied',
                        result: ['changed' => $changed],
                        durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
                    );

                    // set_status can additionally push to the platform — that half is deferred.
                    if ($type === 'set_status' && ! $dryRun && ! empty($action['push_to_platform'])) {
                        $deferred[] = $action;
                    }
                } catch (\Throwable $e) {
                    $results[] = new ActionResult($id, $type, 'failed', error: $e->getMessage());
                }
            }

            if ($mutated && ! $dryRun) {
                $order->save();
            }
        });

        $terminated = collect($results)->contains(fn (ActionResult $r) => $r->terminal);

        return [
            'results' => $results,
            'mutated' => $mutated,
            'terminated' => $terminated,
            'deferred' => $deferred,
            'splitChildren' => $splitChildren,
        ];
    }

    /**
     * Split an order into child orders by SKU group (spec §4.7). Local-only — nothing is pushed to
     * the channel. The parent is retained, held, and tagged so it drops out of analytics. Refuses if
     * the order was already split.
     *
     * @return array<int, int> child order ids
     */
    private function splitOrder(Order $order, array $config): array
    {
        if (Order::where('parent_order_id', $order->id)->exists()) {
            throw new \RuntimeException('already_split');
        }

        $items = $order->items()->get();
        $groups = $this->splitGroups($items, $config);

        if (count($groups) < 2) {
            throw new \RuntimeException('nothing_to_split');
        }

        $children = [];
        $index = 1;
        foreach ($groups as $groupItems) {
            $total = $groupItems->sum(fn ($it) => (float) $it->price * (int) $it->quantity);
            $child = Order::create([
                'store_id' => $order->store_id,
                'external_id' => $order->external_id.'-S'.$index,
                'status' => $order->status,
                'total' => round($total, 2),
                'currency' => $order->currency,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'placed_at' => $order->placed_at,
                'parent_order_id' => $order->id,
                'split_index' => $index,
                'raw_data' => ['_split_of' => $order->id, '_split_strategy' => $config['strategy'] ?? 'by_sku'],
            ]);
            foreach ($groupItems as $item) {
                $item->update(['order_id' => $child->id]);
            }
            $children[] = $child->id;
            $index++;
        }

        // The parent is retained (audit + reconciliation), held, and excluded from analytics.
        $tags = $order->tags ?? [];
        $order->fill([
            'is_held' => true,
            'folder' => 'Split — parent',
            'tags' => array_values(array_unique([...$tags, 'parent_of_split'])),
        ])->save();

        return $children;
    }

    /** Group the order's items per the split strategy. Only by_sku is implemented in this slice. */
    private function splitGroups($items, array $config)
    {
        // Explicit groups take precedence when provided.
        if (! empty($config['groups']) && is_array($config['groups'])) {
            return collect($config['groups'])
                ->map(fn ($g) => $items->filter(fn ($it) => in_array($it->sku, $g['skus'] ?? [], true)))
                ->filter(fn ($group) => $group->isNotEmpty())
                ->values();
        }

        // Default by_sku: one child per distinct SKU.
        return $items->groupBy('sku')->values();
    }

    /** Mutate the order in memory; return whether anything actually changed. */
    private function run(Order $order, string $type, array $config): bool
    {
        return match ($type) {
            'add_tag' => $this->addTags($order, $this->tags($config)),
            'remove_tag' => $this->removeTags($order, $this->tags($config)),
            'set_status' => $this->setField($order, 'status', $config['status'] ?? $config['value'] ?? null),
            'assign_folder' => $this->setField($order, 'folder', $config['folder'] ?? $config['value'] ?? null),
            'route_location' => $this->setField($order, 'fulfillment_location', $config['location'] ?? $config['value'] ?? null),
            'assign_carrier' => $this->assignCarrier($order, $config),
            'hold_order' => $this->hold($order, $config),
            'release_hold' => $this->release($order),
            'add_note' => $this->addNote($order, $config),
            default => throw new \RuntimeException("unknown_action:{$type}"),
        };
    }

    /** Dry-run preview: would this action change the order? No writes. */
    private function wouldChange(Order $order, string $type, array $config): bool
    {
        return match ($type) {
            'add_tag' => (bool) array_diff($this->tags($config), $order->tags ?? []),
            'remove_tag' => (bool) array_intersect($this->tags($config), $order->tags ?? []),
            'set_status' => $order->status !== ($config['status'] ?? $config['value'] ?? null),
            'assign_folder' => $order->folder !== ($config['folder'] ?? $config['value'] ?? null),
            'route_location' => $order->fulfillment_location !== ($config['location'] ?? $config['value'] ?? null),
            'assign_carrier' => $order->carrier_code !== ($config['carrier'] ?? $config['value'] ?? null),
            'hold_order' => ! $order->is_held,
            'release_hold' => (bool) $order->is_held,
            'add_note' => true,
            default => false,
        };
    }

    /** @return array<int, string> lowercase, de-duped */
    private function tags(array $config): array
    {
        $tags = $config['tags'] ?? $config['value'] ?? [];
        $tags = is_array($tags) ? $tags : [$tags];

        return array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $tags)));
    }

    private function addTags(Order $order, array $tags): bool
    {
        $current = $order->tags ?? [];
        $next = array_values(array_unique([...$current, ...$tags]));
        if ($next === $current) {
            return false;
        }
        $order->tags = $next;

        return true;
    }

    private function removeTags(Order $order, array $tags): bool
    {
        $current = $order->tags ?? [];
        $next = array_values(array_diff($current, $tags));
        if ($next === $current) {
            return false;
        }
        $order->tags = $next;

        return true;
    }

    private function setField(Order $order, string $field, $value): bool
    {
        if ($value === null || $order->{$field} === $value) {
            return false;
        }
        $order->{$field} = $value;

        return true;
    }

    private function assignCarrier(Order $order, array $config): bool
    {
        $changed = $this->setField($order, 'carrier_code', $config['carrier'] ?? $config['value'] ?? null);
        if (! empty($config['service'])) {
            $changed = $this->setField($order, 'shipping_service', $config['service']) || $changed;
        }

        return $changed;
    }

    private function hold(Order $order, array $config): bool
    {
        if ($order->is_held) {
            return false; // idempotent no-op
        }
        $order->is_held = true;
        $order->hold_reason = $config['reason'] ?? 'automation';
        $order->held_at = now();

        return true;
    }

    private function release(Order $order): bool
    {
        if (! $order->is_held) {
            return false;
        }
        $order->is_held = false;
        $order->hold_reason = null;
        $order->held_at = null;

        return true;
    }

    private function addNote(Order $order, array $config): bool
    {
        $text = trim((string) ($config['text'] ?? $config['value'] ?? ''));
        if ($text === '') {
            return false;
        }
        $notes = $order->internal_notes ?? [];
        $notes[] = ['at' => now()->toIso8601String(), 'by' => null, 'source' => 'automation', 'text' => $text];
        $order->internal_notes = $notes;

        return true;
    }
}
