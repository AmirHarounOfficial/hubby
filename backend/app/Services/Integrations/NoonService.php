<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * noon Seller (Partner) API integration.
 *
 * noon uses OAuth2 client credentials / authorization-code and a bearer token
 * against the seller API. Hosts differ per environment, so the base URL and
 * auth host are config-driven (`services.noon.*`) — fill them from your noon
 * partner account to activate live calls.
 */
class NoonService extends BaseIntegrationService
{
    private function authHost(): string
    {
        return rtrim(config('services.noon.auth_url', 'https://accounts.noon.partners'), '/');
    }

    private function apiBase(): string
    {
        return rtrim(config('services.noon.base_url', 'https://api.noon.partners'), '/');
    }

    public function getAuthUrl(): string
    {
        $clientId = config('services.noon.client_id');
        $redirectUri = route('oauth.callback', ['platform' => 'noon']);

        return $this->authHost() . "/oauth2/authorize"
            . "?client_id={$clientId}&response_type=code&scope=offline_access"
            . "&redirect_uri={$redirectUri}";
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post($this->authHost() . '/oauth2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.noon.client_id'),
            'client_secret' => config('services.noon.client_secret'),
            'redirect_uri' => route('oauth.callback', ['platform' => 'noon']),
        ]);

        return $response->json() ?? [];
    }

    public function refreshToken(Integration $integration): void
    {
        $response = Http::asForm()->post($this->authHost() . '/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
            'client_id' => config('services.noon.client_id'),
            'client_secret' => config('services.noon.client_secret'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $integration->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            ]);
        }
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get($this->apiBase() . '/v1/orders', $params);

        if ($response->failed()) {
            Log::error('Noon fetchOrders failed: ' . $response->body());
            return [];
        }

        return $response->json('data') ?? [];
    }

    public function fetchProducts(Store $store): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get($this->apiBase() . '/v1/products');

        if ($response->failed()) {
            Log::error('Noon fetchProducts failed: ' . $response->body());
            return [];
        }

        return $response->json('data') ?? [];
    }

    public function fetchInventory(Store $store): array
    {
        $levels = [];

        foreach ($this->fetchProducts($store) as $product) {
            if (! empty($product['sku'])) {
                $levels[] = [
                    'sku' => $product['sku'],
                    'available' => $product['stock'] ?? $product['quantity'] ?? 0,
                ];
            }
        }

        return $levels;
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        $response = $this->getHttpClient($store->integration)
            ->put($this->apiBase() . "/v1/products/{$sku}/stock", [
                'stock' => $qty,
            ]);

        if ($response->failed()) {
            Log::error("Noon updateInventory failed for {$sku}: " . $response->body());
            return false;
        }

        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        $response = $this->getHttpClient($store->integration)
            ->post($this->apiBase() . "/v1/orders/{$externalId}/status", [
                'status' => strtolower($status),
            ]);

        if ($response->failed()) {
            Log::error("Noon updateOrderStatus failed for {$externalId}: " . $response->body());
            return false;
        }

        return true;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        return $this->updateOrderStatus($store, $externalId, 'cancelled');
    }
}
