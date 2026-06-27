<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Amazon Selling Partner API (SP-API) integration.
 *
 * Auth is Login with Amazon (LWA): the seller authorises the app, we exchange
 * the code at Amazon's token endpoint for a refresh token, and call SP-API with
 * a short-lived access token in the `x-amz-access-token` header.
 *
 * NOTE: production SP-API calls also require AWS SigV4 request signing with the
 * app's IAM role. That signing belongs in getHttpClient() once AWS credentials
 * are provisioned; the request shapes below are otherwise correct.
 */
class AmazonService extends BaseIntegrationService
{
    private function region(): string
    {
        // na | eu | fe — selects the SP-API host.
        return config('services.amazon.region', 'na');
    }

    private function endpoint(): string
    {
        return match ($this->region()) {
            'eu' => 'https://sellingpartnerapi-eu.amazon.com',
            'fe' => 'https://sellingpartnerapi-fe.amazon.com',
            default => 'https://sellingpartnerapi-na.amazon.com',
        };
    }

    public function getAuthUrl(): string
    {
        $appId = config('services.amazon.app_id');
        $redirectUri = route('oauth.callback', ['platform' => 'amazon']);
        $state = bin2hex(random_bytes(8));

        return "https://sellercentral.amazon.com/apps/authorize/consent"
            . "?application_id={$appId}&state={$state}&redirect_uri={$redirectUri}";
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
            'redirect_uri' => route('oauth.callback', ['platform' => 'amazon']),
        ]);

        return $response->json() ?? [];
    }

    public function refreshToken(Integration $integration): void
    {
        $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
            'client_id' => config('services.amazon.client_id'),
            'client_secret' => config('services.amazon.client_secret'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $integration->update([
                'access_token' => $data['access_token'],
                'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            ]);
        }
    }

    protected function getHttpClient(Integration $integration)
    {
        return Http::withHeaders([
            'x-amz-access-token' => $integration->access_token,
        ]);
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        $marketplaceId = config('services.amazon.marketplace_id');
        $query = array_merge([
            'MarketplaceIds' => $marketplaceId,
            'CreatedAfter' => now()->subDays(30)->toIso8601String(),
        ], $params);

        $response = $this->getHttpClient($store->integration)
            ->get($this->endpoint() . '/orders/v0/orders', $query);

        if ($response->failed()) {
            Log::error('Amazon fetchOrders failed: ' . $response->body());
            return [];
        }

        return $response->json('payload.Orders') ?? [];
    }

    public function fetchProducts(Store $store): array
    {
        // SP-API exposes seller catalog via the FBA Inventory summaries endpoint.
        $response = $this->getHttpClient($store->integration)
            ->get($this->endpoint() . '/fba/inventory/v1/summaries', [
                'granularityType' => 'Marketplace',
                'granularityId' => config('services.amazon.marketplace_id'),
                'marketplaceIds' => config('services.amazon.marketplace_id'),
            ]);

        if ($response->failed()) {
            Log::error('Amazon fetchProducts failed: ' . $response->body());
            return [];
        }

        return $response->json('payload.inventorySummaries') ?? [];
    }

    public function fetchInventory(Store $store): array
    {
        return $this->fetchProducts($store);
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        // Inventory feeds on Amazon go through the Listings Items API.
        $sellerId = config('services.amazon.seller_id');
        $marketplaceId = config('services.amazon.marketplace_id');

        if (! $sellerId) {
            Log::warning('Amazon updateInventory skipped: seller_id not configured.');
            return false;
        }

        $response = $this->getHttpClient($store->integration)
            ->patch($this->endpoint() . "/listings/2021-08-01/items/{$sellerId}/{$sku}", [
                'productType' => 'PRODUCT',
                'patches' => [[
                    'op' => 'replace',
                    'path' => '/attributes/fulfillment_availability',
                    'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => $qty]],
                ]],
            ] + ['marketplaceIds' => $marketplaceId]);

        if ($response->failed()) {
            Log::error("Amazon updateInventory failed for {$sku}: " . $response->body());
            return false;
        }

        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        // Amazon order status is seller-driven only for cancellation/confirmation;
        // arbitrary status transitions aren't supported via SP-API.
        if (in_array(strtolower($status), ['cancelled', 'canceled'], true)) {
            return $this->cancelOrder($store, $externalId);
        }

        Log::info("Amazon has no direct status mapping for [{$status}] (order {$externalId}).");
        return false;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        Log::info("Amazon order cancellation requested for {$externalId} (handled via seller feed).");
        return false;
    }
}
