<?php

namespace App\Services\Profit;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Splits an amount into net and VAT (spec 01 §4.4).
 *
 * The rule that governs everything here: **VAT collected is never revenue and never profit — it
 * is a liability.** In KSA/UAE storefronts prices are normally quoted VAT-inclusive, so treating
 * the sticker price as revenue overstates profit by the VAT rate (15% in KSA).
 */
class VatCalculator
{
    /**
     * @return array{0: string, 1: string} [net_ex_vat, vat]
     */
    public function split(string|float|int|null $amount, float $rate, bool $inclusive): array
    {
        $minor = Money::toMinor($amount);

        if ($minor === 0 || $rate <= 0) {
            return [Money::fromMinor($minor), '0.0000'];
        }

        if ($inclusive) {
            // The amount already contains VAT: net = gross / (1 + r).
            $net = (int) round($minor / (1 + $rate));
            $vat = $minor - $net;
        } else {
            $net = $minor;
            $vat = (int) round($minor * $rate);
        }

        return [Money::fromMinor($net), Money::fromMinor($vat)];
    }

    /**
     * Effective VAT rate for a line, most specific first:
     * order_items.tax_rate → orders.tax_rate → stores.vat_rate → organizations.default_vat_rate.
     *
     * The item/order columns are part of a deferred migration, so today this resolves from the
     * store then the organization; the null-coalescing chain upgrades itself when they land.
     */
    public function rateFor(?OrderItem $item, Order $order): float
    {
        $store = $order->store;

        return (float) (
            $item?->tax_rate
            ?? $order->tax_rate
            ?? $store?->vat_rate
            ?? $store?->organization?->default_vat_rate
            ?? 0.0
        );
    }

    /**
     * Whether the stored prices already include VAT, most specific first:
     * order_items → orders → stores.prices_include_vat → organizations.prices_include_vat.
     */
    public function isInclusive(?OrderItem $item, Order $order): bool
    {
        $store = $order->store;

        foreach ([
            $item?->tax_inclusive,
            $order->tax_inclusive,
            $store?->prices_include_vat,
            $store?->organization?->prices_include_vat,
        ] as $value) {
            if ($value !== null) {
                return (bool) $value;
            }
        }

        // MENA storefronts quote VAT-inclusive prices; defaulting the other way would silently
        // inflate revenue.
        return true;
    }
}
