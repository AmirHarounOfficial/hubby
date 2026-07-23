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
    private const DEFERRED = ['notify', 'call_webhook', 'split_order'];

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @return array{results: array<int, ActionResult>, mutated: bool, terminated: bool}
     */
    public function apply(array $actions, AutomationSubject $subject, bool $dryRun = false): array
    {
        /** @var Order $order */
        $order = $subject->model();
        $results = [];
        $mutated = false;
        $terminated = false;

        Automation::whileApplying(function () use ($actions, $order, $dryRun, &$results, &$mutated, &$terminated) {
            foreach ($actions as $i => $action) {
                $type = $action['type'] ?? 'unknown';
                $id = (string) ($action['id'] ?? $type.'-'.$i);
                $start = hrtime(true);

                if ($type === 'stop_processing') {
                    $results[] = new ActionResult($id, $type, 'applied', terminal: true);

                    continue;
                }

                if (in_array($type, self::DEFERRED, true)) {
                    // Slice 1: not yet executed. Recorded honestly rather than dropped.
                    $results[] = new ActionResult($id, $type, 'skipped', error: 'deferred_to_later_slice');

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
                } catch (\Throwable $e) {
                    $results[] = new ActionResult($id, $type, 'failed', error: $e->getMessage());
                }
            }

            if ($mutated && ! $dryRun) {
                $order->save();
            }
        });

        $terminated = collect($results)->contains(fn (ActionResult $r) => $r->terminal);

        return ['results' => $results, 'mutated' => $mutated, 'terminated' => $terminated];
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
