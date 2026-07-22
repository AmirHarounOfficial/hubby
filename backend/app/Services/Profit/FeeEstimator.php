<?php

namespace App\Services\Profit;

use App\Models\FeeRule;
use App\Models\Order;
use App\Models\OrderFee;
use Illuminate\Support\Facades\DB;

/**
 * Produces estimated `order_fees` from merchant-stated rules (spec 01 §6).
 *
 * Only Shopify Payments and Amazon expose true per-order fees today, and both are currently
 * blocked (a new OAuth scope; unimplemented SigV4). Everywhere else the choice is between
 * pretending fees are zero — which silently overstates profit — and applying a rule the merchant
 * states once. We do the latter and flag every resulting row `is_estimated`, so the UI can say
 * plainly which numbers are measured and which are modelled.
 *
 * Never overwrites a real fee: a rule is skipped if a non-estimated fee of the same type already
 * exists on the order, so importing a real settlement later supersedes the estimate.
 */
class FeeEstimator
{
    /** @return array<int, OrderFee> */
    public function estimate(Order $order): array
    {
        $store = $order->store;

        if (! $store || ! $store->organization_id) {
            return [];
        }

        $onDate = ($order->placed_at ?? $order->created_at)->toDateString();

        $rules = FeeRule::query()
            ->matching((int) $store->organization_id, (string) $store->platform, $store->id, $onDate)
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $items = $order->items()->get();
        $created = [];

        DB::transaction(function () use ($order, $store, $rules, $items, &$created) {
            $seen = [];

            foreach ($rules as $rule) {
                // Most specific rule per (type, subtype) wins; scopeMatching already ordered them.
                $slot = $rule->type.'|'.($rule->subtype ?? '-');
                if (isset($seen[$slot])) {
                    continue;
                }
                $seen[$slot] = true;

                if ($this->hasRealFee($order, $rule->type)) {
                    continue;
                }

                if ($rule->isOrderLevel()) {
                    $fee = $this->applyOrderLevel($order, $store, $rule, $items);
                    if ($fee) {
                        $created[] = $fee;
                    }

                    continue;
                }

                foreach ($items as $item) {
                    if ($rule->sku !== null && $rule->sku !== $item->sku) {
                        continue;
                    }

                    $fee = $this->applyItemLevel($order, $store, $rule, $item);
                    if ($fee) {
                        $created[] = $fee;
                    }
                }
            }
        });

        return $created;
    }

    /** A measured fee always beats a modelled one. */
    private function hasRealFee(Order $order, string $type): bool
    {
        return OrderFee::query()
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->where('is_estimated', false)
            ->exists();
    }

    private function applyOrderLevel(Order $order, $store, FeeRule $rule, $items): ?OrderFee
    {
        $amount = match ($rule->basis) {
            FeeRule::BASIS_PERCENT_OF_ORDER => Money::scale(
                Money::sum(...$items->map(fn ($i) => Money::multiply($i->price, (int) $i->quantity))->all()),
                ((float) $rule->rate) / 100
            ),
            FeeRule::BASIS_FLAT_PER_ORDER => Money::sum($rule->rate),
            default => null,
        };

        if ($amount === null) {
            return null;
        }

        return $this->write($order, $store, $rule, null, $this->clamp($amount, $rule));
    }

    private function applyItemLevel(Order $order, $store, FeeRule $rule, $item): ?OrderFee
    {
        $lineTotal = Money::multiply($item->price, (int) $item->quantity);

        $amount = match ($rule->basis) {
            FeeRule::BASIS_PERCENT_OF_ITEM => Money::scale($lineTotal, ((float) $rule->rate) / 100),
            FeeRule::BASIS_FLAT_PER_UNIT => Money::multiply($rule->rate, (int) $item->quantity),
            // per_kg needs a weight we don't store yet; skipped rather than guessed.
            default => null,
        };

        if ($amount === null) {
            return null;
        }

        return $this->write($order, $store, $rule, $item->id, $this->clamp($amount, $rule));
    }

    /** Apply the rule's floor and cap. */
    private function clamp(string $amount, FeeRule $rule): string
    {
        $minor = Money::toMinor($amount);

        if ($rule->min_amount !== null) {
            $minor = max($minor, Money::toMinor($rule->min_amount));
        }

        if ($rule->max_amount !== null) {
            $minor = min($minor, Money::toMinor($rule->max_amount));
        }

        return Money::fromMinor($minor);
    }

    private function write(Order $order, $store, FeeRule $rule, ?int $orderItemId, string $amount): ?OrderFee
    {
        if (Money::isZero($amount)) {
            return null;
        }

        // Deterministic per (rule, line) so re-running estimation updates rather than duplicates.
        $feeKey = OrderFee::buildFeeKey(
            (string) $order->external_id,
            $rule->type,
            $rule->subtype ?? 'rule'.$rule->id,
            'rule'.$rule->id.($orderItemId ? ':'.$orderItemId : '')
        );

        return OrderFee::updateOrCreate(
            ['store_id' => $store->id, 'fee_key' => $feeKey],
            [
                'organization_id' => $store->organization_id,
                'order_id' => $order->id,
                'order_item_id' => $orderItemId,
                'type' => $rule->type,
                'subtype' => $rule->subtype,
                'amount' => $amount,
                'amount_base' => $amount,
                'currency' => $rule->currency ?? $order->currency ?? 'SAR',
                'is_estimated' => true,
                'source' => 'rule',
                'posted_at' => $order->placed_at ?? $order->created_at,
            ]
        );
    }
}
