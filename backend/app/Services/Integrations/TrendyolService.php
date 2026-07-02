<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trendyol Marketplace (Türkiye) API integration.
 *
 * Trendyol does NOT use OAuth — the merchant creates an API Key + API Secret in
 * the Seller Center and provides their Supplier (Seller) ID. We store:
 *   - access_token  → API Key
 *   - refresh_token → API Secret
 *   - shop_domain / store.domain → Supplier ID
 *
 * Auth is HTTP Basic (apiKey:apiSecret); all endpoints are namespaced under the
 * supplier: https://api.trendyol.com/sapigw/suppliers/{supplierId}/...
 */
class TrendyolService extends BaseIntegrationService
{
    private function apiBase(): string
    {
        return rtrim(config('services.trendyol.base_url', 'https://api.trendyol.com'), '/');
    }

    private function supplierEndpoint(Store $store, string $path): string
    {
        $supplierId = $store->integration->shop_domain ?? $store->domain;

        return $this->apiBase() . "/sapigw/suppliers/{$supplierId}" . $path;
    }

    /** API keys are static — there is no OAuth redirect. */
    public function getAuthUrl(): string
    {
        return '';
    }

    /** Not applicable: credentials are entered manually, not via a code exchange. */
    public function exchangeCode(string $code): array
    {
        return [];
    }

    /** Trendyol API keys do not expire, so there is nothing to refresh. */
    public function refreshToken(Integration $integration): void
    {
        // no-op
    }

    /**
     * Basic auth (apiKey:apiSecret). Trendyol also recommends a User-Agent of
     * "{supplierId} - SelfIntegration".
     */
    protected function getHttpClient(Integration $integration)
    {
        $apiKey = $integration->access_token;
        $apiSecret = $integration->refresh_token ?? '';
        $supplierId = $integration->shop_domain ?? '';

        return Http::withBasicAuth($apiKey, $apiSecret)
            ->withHeaders(['User-Agent' => trim("{$supplierId} - SelfIntegration")]);
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get($this->supplierEndpoint($store, '/orders'), $params);

        if ($response->failed()) {
            Log::error('Trendyol fetchOrders failed: ' . $response->body());
            return [];
        }

        // Trendyol paginates orders under "content".
        return $response->json('content') ?? $response->json('data') ?? [];
    }

    public function fetchProducts(Store $store): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get($this->supplierEndpoint($store, '/products'));

        if ($response->failed()) {
            Log::error('Trendyol fetchProducts failed: ' . $response->body());
            return [];
        }

        return $response->json('content') ?? $response->json('data') ?? [];
    }

    public function fetchInventory(Store $store): array
    {
        $levels = [];

        foreach ($this->fetchProducts($store) as $product) {
            $sku = $product['stockCode'] ?? $product['barcode'] ?? $product['sku'] ?? null;
            if (! empty($sku)) {
                $levels[] = [
                    'sku' => $sku,
                    'available' => $product['quantity'] ?? $product['stock'] ?? 0,
                ];
            }
        }

        return $levels;
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        // Trendyol updates stock (and price) in bulk via price-and-inventory.
        $response = $this->getHttpClient($store->integration)
            ->post($this->supplierEndpoint($store, '/products/price-and-inventory'), [
                'items' => [
                    ['barcode' => $sku, 'quantity' => $qty],
                ],
            ]);

        if ($response->failed()) {
            Log::error("Trendyol updateInventory failed for {$sku}: " . $response->body());
            return false;
        }

        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        // Trendyol shipment-package status updates live under /shipment-packages.
        $response = $this->getHttpClient($store->integration)
            ->put($this->supplierEndpoint($store, "/shipment-packages/{$externalId}"), [
                'status' => strtoupper($status),
            ]);

        if ($response->failed()) {
            Log::error("Trendyol updateOrderStatus failed for {$externalId}: " . $response->body());
            return false;
        }

        return true;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        return $this->updateOrderStatus($store, $externalId, 'Cancelled');
    }
}
