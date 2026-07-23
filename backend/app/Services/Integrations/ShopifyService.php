<?php

namespace App\Services\Integrations;

use App\Models\Order;
use App\Models\Store;
use App\Models\Integration;
use App\Services\Integrations\Contracts\CapturesOrderFees;
use App\Services\Profit\FeeCapture\ShopifyFeeParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyService extends BaseIntegrationService implements CapturesOrderFees, SupportsReturnsInterface
{
    public function getAuthUrl(): string
    {
        $shop = request('shop'); // myshopify.com URL
        $apiKey = config('services.shopify.api_key');
        // read_shopify_payments_payouts lets us read the actual processing fees on each order.
        $scopes = 'read_orders,write_orders,read_products,write_products,read_inventory,write_inventory,read_shopify_payments_payouts';
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

    /**
     * Actual Shopify Payments processing fees for an order, from its transactions.
     * Needs the read_shopify_payments_payouts scope (see getAuthUrl).
     */
    public function fetchOrderFees(Store $store, Order $order): array
    {
        $domain = $store->domain;

        $response = $this->getHttpClient($store->integration)
            ->get("https://{$domain}/admin/api/2024-01/orders/{$order->external_id}/transactions.json");

        if ($response->failed()) {
            Log::error("Shopify fetchOrderFees failed for {$order->external_id}: ".$response->body());

            return [];
        }

        return ShopifyFeeParser::parse($response->json('transactions') ?? []);
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

    // --- Returns two-way sync (spec 03 §5.3) -------------------------------------------------

    public function supportsReturnCapability(string $capability): bool
    {
        // Only refunds today — Shopify's Return objects (fetch/approve/reject) require a GraphQL
        // client this codebase doesn't have yet (M2 follow-up), and labels need Spec 04.
        return in_array($capability, config('returns.capabilities.shopify', []), true);
    }

    /**
     * Refund an order over the REST Admin API. Refunds still live in REST (only Return objects
     * moved to GraphQL), so this is safe. We attach the refund to the order's original sale/capture
     * transaction so the money actually goes back through the same gateway; without a resolvable
     * parent transaction we cannot move money, so we report failure rather than silently record a
     * zero-value refund.
     *
     * @return array{id:string,status:string,amount:float}|null
     */
    public function refundOrder(Store $store, string $externalOrderId, array $payload): ?array
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $client = $this->getHttpClient($store->integration);
        $base = "https://{$store->domain}/admin/api/2024-01";

        $parent = $this->parentTransactionId($client, $base, $externalOrderId);
        if (! $parent) {
            Log::error("Shopify refundOrder: no parent transaction for order {$externalOrderId}; cannot refund.");

            return null;
        }

        $response = $client->post("{$base}/orders/{$externalOrderId}/refunds.json", [
            'refund' => [
                'currency' => $payload['currency'] ?? $store->organization?->base_currency ?? 'SAR',
                'notify' => (bool) ($payload['notify'] ?? false),
                'note' => $payload['note'] ?? 'Refund via Hubby returns',
                'transactions' => [[
                    'parent_id' => $parent,
                    'amount' => number_format($amount, 2, '.', ''),
                    'kind' => 'refund',
                    'gateway' => $payload['gateway'] ?? null,
                ]],
            ],
        ]);

        if ($response->failed()) {
            Log::error("Shopify refundOrder failed for {$externalOrderId}: ".$response->body());

            return null;
        }

        $refund = $response->json('refund') ?? [];

        return [
            'id' => (string) ($refund['id'] ?? ''),
            'status' => 'succeeded',
            'amount' => $amount,
        ];
    }

    /** The order's original sale/capture transaction id — the parent a refund attaches to. */
    protected function parentTransactionId($client, string $base, string $externalOrderId): ?int
    {
        $response = $client->get("{$base}/orders/{$externalOrderId}/transactions.json");
        if ($response->failed()) {
            return null;
        }

        $transactions = $response->json('transactions') ?? [];
        foreach ($transactions as $tx) {
            if (in_array($tx['kind'] ?? '', ['sale', 'capture'], true) && ($tx['status'] ?? '') === 'success') {
                return (int) $tx['id'];
            }
        }

        return null;
    }

    public function fetchReturns(Store $store, array $params = []): array
    {
        return []; // needs the Shopify GraphQL Return API — M2 follow-up.
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
        return null; // needs Spec 04 (Shipping & Labels).
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
