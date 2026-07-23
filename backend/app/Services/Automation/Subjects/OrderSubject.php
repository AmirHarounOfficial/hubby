<?php

namespace App\Services\Automation\Subjects;

use App\Models\Order;
use App\Services\Automation\Contracts\AutomationSubject;

/**
 * Builds the `order.*` facts map from an Order (spec 02 §3.5 field catalogue).
 *
 * Facts are computed once per evaluation pass and cached — every rule in the pass reads the same
 * snapshot. Values the platform doesn't send stay `null`, and the operator layer treats null as
 * "never matches" (except the null-safe operators), so a missing field can't accidentally satisfy
 * a rule.
 */
class OrderSubject implements AutomationSubject
{
    private ?array $facts = null;

    public function __construct(private readonly Order $order, private readonly ?string $previousStatus = null)
    {
    }

    public function organizationId(): int
    {
        return (int) ($this->order->store?->organization_id ?? 0);
    }

    public function type(): string
    {
        return 'order';
    }

    public function id(): int
    {
        return (int) $this->order->id;
    }

    public function key(): string
    {
        return 'order:'.$this->order->id;
    }

    public function label(): ?string
    {
        return $this->order->external_id;
    }

    public function model(): object
    {
        return $this->order;
    }

    public function facts(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }

        $order = $this->order;
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $raw = is_array($order->raw_data) ? $order->raw_data : [];

        $payment = $this->paymentMethod($raw);

        return $this->facts = [
            'order.channel' => $order->store?->platform,
            'order.store_id' => (int) $order->store_id,
            'order.status' => $order->status,
            'order.previous_status' => $this->previousStatus,
            'order.total' => (float) $order->total,
            'order.currency' => $order->currency,
            'order.item_count' => $items->count(),
            'order.total_quantity' => (int) $items->sum('quantity'),
            'order.skus' => $items->pluck('sku')->filter()->values()->all(),
            'order.product_names' => $items->pluck('name')->filter()->values()->all(),
            'order.tags' => is_array($order->tags) ? $order->tags : [],
            'order.payment_method' => $payment,
            'order.is_cod' => $payment === 'cod',
            'order.shipping_country' => $this->country($raw),
            'order.shipping_city' => $this->city($raw),
            'order.customer_email' => $order->customer_email,
            'order.is_held' => (bool) $order->is_held,
            'order.folder' => $order->folder,
            'order.fulfillment_location' => $order->fulfillment_location,
            'order.carrier_code' => $order->carrier_code,
            'order.created_hour' => $order->created_at?->hour,
            'order.age_minutes' => $order->created_at ? (int) $order->created_at->diffInMinutes(now()) : null,
        ];
    }

    /** Normalise the platform's payment label to our vocabulary. */
    private function paymentMethod(array $raw): string
    {
        $candidates = [
            $raw['payment_method'] ?? null,
            $raw['payment']['method'] ?? null,
            $raw['gateway'] ?? null,
            $raw['financial_status'] ?? null,
        ];
        $value = strtolower(trim((string) collect($candidates)->first(fn ($c) => $c !== null && $c !== '')));

        return match (true) {
            $value === '' => 'unknown',
            str_contains($value, 'cod'), str_contains($value, 'cash') => 'cod',
            str_contains($value, 'card'), str_contains($value, 'credit'), str_contains($value, 'mada') => 'card',
            str_contains($value, 'wallet'), str_contains($value, 'apple'), str_contains($value, 'stc') => 'wallet',
            str_contains($value, 'transfer'), str_contains($value, 'bank') => 'bank_transfer',
            str_contains($value, 'tabby'), str_contains($value, 'tamara'), str_contains($value, 'bnpl') => 'bnpl',
            default => 'marketplace',
        };
    }

    /** ISO-3166-1 alpha-2, uppercase, or null. */
    private function country(array $raw): ?string
    {
        $value = $raw['shipping_address']['country_code']
            ?? $raw['shipping_address']['country']
            ?? $raw['shipping']['country'] ?? null;

        return $value ? strtoupper(substr((string) $value, 0, 2)) : null;
    }

    private function city(array $raw): ?string
    {
        return $raw['shipping_address']['city'] ?? $raw['shipping']['city'] ?? null;
    }
}
