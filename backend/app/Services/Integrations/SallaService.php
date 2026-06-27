<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SallaService extends BaseIntegrationService
{
    public function getAuthUrl(): string
    {
        $clientId = config('services.salla.client_id');
        $redirectUri = route('oauth.callback', ['platform' => 'salla']);

        return "https://accounts.salla.sa/oauth2/auth?client_id={$clientId}&response_type=code&scope=offline_access&redirect_uri={$redirectUri}";
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post("https://accounts.salla.sa/oauth2/token", [
            'client_id' => config('services.salla.client_id'),
            'client_secret' => config('services.salla.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('oauth.callback', ['platform' => 'salla']),
        ]);

        return $response->json();
    }

    public function refreshToken(Integration $integration): void
    {
        $response = Http::asForm()->post("https://accounts.salla.sa/oauth2/token", [
            'client_id' => config('services.salla.client_id'),
            'client_secret' => config('services.salla.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $integration->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => now()->addSeconds($data['expires_in']),
            ]);
        }
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get("https://api.salla.dev/admin/v2/orders", $params);

        return $response->json('data') ?? [];
    }

    public function fetchProducts(Store $store): array
    {
        $response = $this->getHttpClient($store->integration)
            ->get("https://api.salla.dev/admin/v2/products");

        return $response->json('data') ?? [];
    }

    public function fetchInventory(Store $store): array
    {
        // Salla exposes stock on the product record; derive a simple
        // sku → quantity map from the product list.
        $levels = [];

        foreach ($this->fetchProducts($store) as $product) {
            if (! empty($product['sku'])) {
                $levels[] = [
                    'sku' => $product['sku'],
                    'available' => $product['quantity'] ?? 0,
                ];
            }
        }

        return $levels;
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        $productId = $this->productIdForSku($store, $sku);

        if (! $productId) {
            Log::warning("Salla updateInventory: SKU [{$sku}] not found on store {$store->id}.");
            return false;
        }

        $response = $this->getHttpClient($store->integration)
            ->put("https://api.salla.dev/admin/v2/products/{$productId}", [
                'quantity' => $qty,
            ]);

        if ($response->failed()) {
            Log::error("Salla updateInventory failed for SKU {$sku}: " . $response->body());
            return false;
        }

        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        // Salla moves orders via a status slug.
        $slug = match (strtolower($status)) {
            'processing' => 'under_review',
            'shipped' => 'in_progress',
            'delivered' => 'delivered',
            'cancelled', 'canceled' => 'canceled',
            default => strtolower($status),
        };

        $response = $this->getHttpClient($store->integration)
            ->post("https://api.salla.dev/admin/v2/orders/{$externalId}/status", [
                'slug' => $slug,
            ]);

        if ($response->failed()) {
            Log::error("Salla updateOrderStatus failed for {$externalId}: " . $response->body());
            return false;
        }

        return true;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        return $this->updateOrderStatus($store, $externalId, 'canceled');
    }

    /** Resolve a SKU to a Salla product id. */
    protected function productIdForSku(Store $store, string $sku): ?string
    {
        $response = $this->getHttpClient($store->integration)
            ->get("https://api.salla.dev/admin/v2/products", ['sku' => $sku]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data.0.id');
    }
}
