<?php

namespace App\Services\Integrations;

use App\Models\Store;

/**
 * Capability interface for the returns two-way sync (spec 03 §5.3).
 *
 * Deliberately NOT folded into IntegrationServiceInterface: all seven platform services implement
 * that, but only some can act on returns, and each can do a different subset (Shopify can push a
 * refund over REST but its return *objects* need GraphQL; a marketplace issues its own refunds and
 * only lets us mirror the decision). Call sites guard with `instanceof` and then probe the specific
 * capability, so a platform advertising nothing degrades to a local-only RMA rather than erroring.
 */
interface SupportsReturnsInterface
{
    /**
     * Pull raw platform return objects for mirroring into local RMAs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchReturns(Store $store, array $params = []): array;

    /** Mirror an approve decision onto the platform's own return object. */
    public function approveReturn(Store $store, string $externalReturnId, array $payload = []): bool;

    /** Mirror a reject decision onto the platform's own return object. */
    public function rejectReturn(Store $store, string $externalReturnId, string $reason): bool;

    /**
     * Issue a refund against the original order on the platform.
     *
     * @return array{id:string,status:string,amount:float}|null null on failure (caller retries)
     */
    public function refundOrder(Store $store, string $externalOrderId, array $payload): ?array;

    /**
     * Create a prepaid return label with the platform/carrier (needs Spec 04 for most platforms).
     *
     * @return array{tracking_number:string,label_url:?string}|null
     */
    public function createReturnLabel(Store $store, string $externalReturnId, array $payload = []): ?array;

    /** Capability probe: one of 'fetch', 'approve', 'reject', 'refund', 'label'. */
    public function supportsReturnCapability(string $capability): bool;
}
