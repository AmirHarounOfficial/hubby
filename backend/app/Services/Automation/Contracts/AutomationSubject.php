<?php

namespace App\Services\Automation\Contracts;

/**
 * The thing a rule runs against (an order, a low-stock variant, a failed sync). Decouples the engine
 * from any one model: the engine only needs facts to evaluate, identity to audit, and a way to apply
 * a mutation. (spec 02 §4.1)
 */
interface AutomationSubject
{
    public function organizationId(): int;

    /** `order` | `product_variant` | `store` | `sync_log`. */
    public function type(): string;

    public function id(): int;

    /** Stable key for the idempotency ledger, e.g. "order:1234". */
    public function key(): string;

    /** Human label for the audit view (e.g. the order's external id). */
    public function label(): ?string;

    /** The normalised, flat facts map the conditions read. */
    public function facts(): array;

    /** The underlying model, for actions to mutate. */
    public function model(): object;
}
