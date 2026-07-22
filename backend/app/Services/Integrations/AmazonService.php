<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use App\Services\Integrations\Support\AwsSignatureV4;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/**
 * Amazon Selling Partner API (SP-API) integration.
 *
 * Auth is Login with Amazon (LWA): the seller authorises the app, we exchange
 * the code at Amazon's token endpoint for a refresh token, and call SP-API with
 * a short-lived access token in the `x-amz-access-token` header.
 *
 * When AWS IAM credentials are configured, every request is additionally SigV4-signed
 * (getHttpClient() → AwsSignatureV4). Without them we fall back to the LWA token alone,
 * which the newer SP-API auth model accepts.
 */
class AmazonService extends BaseIntegrationService
{
    private function region(): string
    {
        // na | eu | fe — selects the SP-API host.
        return config('services.amazon.region', 'na');
    }

    /** SP-API host region → AWS signing region. */
    private function signingRegion(): string
    {
        return match ($this->region()) {
            'eu' => 'eu-west-1',
            'fe' => 'us-west-2',
            default => 'us-east-1',
        };
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
        $client = Http::withHeaders([
            'x-amz-access-token' => $integration->access_token,
        ]);

        $signer = $this->signer();
        if ($signer === null) {
            return $client;
        }

        // Sign at send time: SigV4 covers the fully-built request (method, path, query, headers,
        // body), none of which exist yet here.
        return $client->withRequestMiddleware(
            fn (RequestInterface $request) => $signer->sign($request, now())
        );
    }

    /** Build the SigV4 signer, or null when IAM credentials aren't provisioned. */
    private function signer(): ?AwsSignatureV4
    {
        $accessKey = config('services.amazon.aws_access_key_id');
        $secretKey = config('services.amazon.aws_secret_access_key');

        if (! $accessKey || ! $secretKey) {
            return null;
        }

        return new AwsSignatureV4(
            accessKey: $accessKey,
            secretKey: $secretKey,
            region: $this->signingRegion(),
            service: 'execute-api',
            sessionToken: config('services.amazon.aws_session_token'),
        );
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
