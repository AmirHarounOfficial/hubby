<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceService extends BaseIntegrationService implements SupportsReturnsInterface
{
    public function getAuthUrl(): string
    {
        // WooCommerce usually uses consumer keys or Application Passwords.
        return "";
    }

    public function exchangeCode(string $code): array
    {
        return [];
    }

    public function refreshToken(Integration $integration): void
    {
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        return [];
    }

    public function fetchProducts(Store $store): array
    {
        return [];
    }

    public function fetchInventory(Store $store): array
    {
        return [];
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        return true;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        return true;
    }

    // --- Returns two-way sync (spec 03 §5.3) -------------------------------------------------

    public function supportsReturnCapability(string $capability): bool
    {
        // WooCommerce core has no native "return" object (those are plugin territory), but it does
        // have a first-class REST refund endpoint — so refunds push and nothing else does.
        return in_array($capability, config('returns.capabilities.woocommerce', []), true);
    }

    /**
     * Refund an order via the WooCommerce REST API (`POST /orders/{id}/refunds`). Woo authenticates
     * with a consumer key/secret pair over Basic auth; we store them as the integration's
     * access_token (key) and refresh_token (secret).
     *
     * @return array{id:string,status:string,amount:float}|null
     */
    public function refundOrder(Store $store, string $externalOrderId, array $payload): ?array
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $response = $this->wooClient($store)
            ->post($this->wooUrl($store, "orders/{$externalOrderId}/refunds"), [
                'amount' => number_format($amount, 2, '.', ''),
                'reason' => $payload['note'] ?? 'Refund via Hubby returns',
                'api_refund' => true, // ask the gateway to move the money, not just record it
            ]);

        if ($response->failed()) {
            Log::error("Woo refundOrder failed for {$externalOrderId}: ".$response->body());

            return null;
        }

        $refund = $response->json() ?? [];

        return [
            'id' => (string) ($refund['id'] ?? ''),
            'status' => 'succeeded',
            'amount' => $amount,
        ];
    }

    public function fetchReturns(Store $store, array $params = []): array
    {
        return [];
    }

    public function approveReturn(Store $store, string $externalReturnId, array $payload = []): bool
    {
        return false;
    }

    public function rejectReturn(Store $store, string $externalReturnId, string $reason): bool
    {
        return false;
    }

    public function createReturnLabel(Store $store, string $externalReturnId, array $payload = []): ?array
    {
        return null;
    }

    protected function wooClient(Store $store)
    {
        $integration = $store->integration;

        return Http::withBasicAuth(
            (string) $integration?->access_token,
            (string) $integration?->refresh_token,
        )->acceptJson();
    }

    protected function wooUrl(Store $store, string $path): string
    {
        $domain = rtrim((string) $store->domain, '/');

        return "https://{$domain}/wp-json/wc/v3/{$path}";
    }
}
