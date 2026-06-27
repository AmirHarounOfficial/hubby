<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService extends BaseIntegrationService
{
    public function getAuthUrl(): string
    {
        $shop = request('shop'); // myshopify.com URL
        $apiKey = config('services.shopify.api_key');
        $scopes = 'read_orders,write_orders,read_products,write_products,read_inventory,write_inventory';
        $redirectUri = route('oauth.callback', ['platform' => 'shopify']);

        return "https://{$shop}/admin/oauth/authorize?client_id={$apiKey}&scope={$scopes}&redirect_uri={$redirectUri}";
    }

    public function exchangeCode(string $code): array
    {
        $shop = request('shop');
        $apiKey = config('services.shopify.api_key');
        $apiSecret = config('services.shopify.api_secret');

        $response = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => $apiKey,
            'client_secret' => $apiSecret,
            'code' => $code,
        ]);

        return $response->json();
    }

    public function refreshToken(Integration $integration): void
    {
        // Shopify offline tokens don't expire usually, but online ones do.
        // For MVP we assume offline tokens.
    }

    protected function getHttpClient(Integration $integration)
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $integration->access_token,
            'Content-Type' => 'application/json',
        ]);
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        $integration = $store->integration;
        $domain = $store->domain;
        
        $response = $this->getHttpClient($integration)
            ->get("https://{$domain}/admin/api/2024-01/orders.json", $params);

        if ($response->failed()) {
            \Log::error("Shopify fetchOrders failed: " . $response->body());
            return [];
        }

        return $response->json('orders') ?? [];
    }

    public function fetchProducts(Store $store): array
    {
        $integration = $store->integration;
        $domain = $store->domain;

        $response = $this->getHttpClient($integration)
            ->get("https://{$domain}/admin/api/2024-01/products.json");

        if ($response->failed()) {
            \Log::error("Shopify fetchProducts failed: " . $response->body());
            return [];
        }

        return $response->json('products') ?? [];
    }

    public function fetchInventory(Store $store): array
    {
        $integration = $store->integration;
        $domain = $store->domain;
        $client = $this->getHttpClient($integration);
        $base = "https://{$domain}/admin/api/2024-01";

        $locationId = $this->primaryLocationId($store);
        if (! $locationId) {
            return [];
        }

        $response = $client->get("{$base}/inventory_levels.json", [
            'location_ids' => $locationId,
            'limit' => 250,
        ]);

        if ($response->failed()) {
            Log::error("Shopify fetchInventory failed: " . $response->body());
            return [];
        }

        return $response->json('inventory_levels') ?? [];
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        $domain = $store->domain;
        $client = $this->getHttpClient($store->integration);
        $base = "https://{$domain}/admin/api/2024-01";

        $inventoryItemId = $this->inventoryItemIdForSku($store, $sku);
        $locationId = $this->primaryLocationId($store);

        if (! $inventoryItemId || ! $locationId) {
            Log::warning("Shopify updateInventory: could not resolve SKU [{$sku}] on store {$store->id}.");
            return false;
        }

        $response = $client->post("{$base}/inventory_levels/set.json", [
            'location_id' => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available' => $qty,
        ]);

        if ($response->failed()) {
            Log::error("Shopify updateInventory failed for SKU {$sku}: " . $response->body());
            return false;
        }

        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        $status = strtolower($status);

        if ($status === 'cancelled' || $status === 'canceled') {
            return $this->cancelOrder($store, $externalId);
        }

        // "shipped"/"delivered" map to a Shopify fulfillment. Anything else has
        // no direct REST equivalent (Shopify derives status from fulfillment +
        // financial state), so we report that nothing was pushed.
        if (in_array($status, ['shipped', 'fulfilled', 'delivered'], true)) {
            return $this->fulfillOrder($store, $externalId);
        }

        Log::info("Shopify has no direct status mapping for [{$status}] (order {$externalId}); skipped push.");
        return false;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        $domain = $store->domain;
        $client = $this->getHttpClient($store->integration);

        $response = $client->post(
            "https://{$domain}/admin/api/2024-01/orders/{$externalId}/cancel.json"
        );

        if ($response->failed()) {
            Log::error("Shopify cancelOrder failed for {$externalId}: " . $response->body());
            return false;
        }

        Log::info("Shopify order canceled: {$externalId}");
        return true;
    }

    /**
     * Create a fulfillment for every fulfillment order so the order reads as shipped.
     */
    protected function fulfillOrder(Store $store, string $externalId): bool
    {
        $domain = $store->domain;
        $client = $this->getHttpClient($store->integration);
        $base = "https://{$domain}/admin/api/2024-01";

        $foResponse = $client->get("{$base}/orders/{$externalId}/fulfillment_orders.json");
        if ($foResponse->failed()) {
            Log::error("Shopify fulfillment_orders lookup failed for {$externalId}: " . $foResponse->body());
            return false;
        }

        $fulfillmentOrders = $foResponse->json('fulfillment_orders') ?? [];
        $ok = false;

        foreach ($fulfillmentOrders as $fo) {
            $response = $client->post("{$base}/fulfillments.json", [
                'fulfillment' => [
                    'line_items_by_fulfillment_order' => [
                        ['fulfillment_order_id' => $fo['id']],
                    ],
                ],
            ]);

            if ($response->successful()) {
                $ok = true;
            } else {
                Log::error("Shopify fulfillment failed for {$externalId}: " . $response->body());
            }
        }

        return $ok;
    }

    /** The store's primary (first) inventory location id, cached per request. */
    protected function primaryLocationId(Store $store): ?int
    {
        $domain = $store->domain;
        $response = $this->getHttpClient($store->integration)
            ->get("https://{$domain}/admin/api/2024-01/locations.json");

        if ($response->failed()) {
            Log::error("Shopify locations lookup failed: " . $response->body());
            return null;
        }

        return $response->json('locations.0.id');
    }

    /** Resolve a SKU to its Shopify inventory_item_id by scanning product variants. */
    protected function inventoryItemIdForSku(Store $store, string $sku): ?int
    {
        $domain = $store->domain;
        $client = $this->getHttpClient($store->integration);

        $response = $client->get("https://{$domain}/admin/api/2024-01/products.json", [
            'fields' => 'variants',
            'limit' => 250,
        ]);

        if ($response->failed()) {
            return null;
        }

        foreach ($response->json('products') ?? [] as $product) {
            foreach ($product['variants'] ?? [] as $variant) {
                if (($variant['sku'] ?? null) === $sku) {
                    return $variant['inventory_item_id'] ?? null;
                }
            }
        }

        return null;
    }
}
