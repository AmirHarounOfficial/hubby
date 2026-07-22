<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SyncOrdersJob;
use App\Jobs\SyncInventoryJob;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $platform)
    {
        Log::info("Webhook received from {$platform}");

        $payload = $request->all();
        $event = $request->header('X-Shopify-Topic') ??
                 $request->header('X-Salla-Event') ??
                 $request->header('X-WC-Webhook-Topic') ??
                 $request->header('X-Noon-Event') ??
                 $request->header('X-Amzn-Notification-Type') ??
                 $request->header('X-Trendyol-Event') ??
                 ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown');

        // Every sync job runs against one tenant's store — its credentials, its
        // organization. A webhook we can't tie back to a connected store is not
        // actionable, so drop it rather than guessing (a wrong guess would sync
        // another tenant's data, or re-sync every store on the platform).
        $store = $this->resolveStore($request, $platform, $payload);

        if (! $store) {
            Log::warning("Ignored {$platform} webhook [{$event}]: could not resolve a connected store.");

            return response()->json(['status' => 'ignored', 'reason' => 'unknown_store']);
        }

        switch ($platform) {
            case 'shopify':
                $this->handleShopify($store, $event, $payload);
                break;
            case 'salla':
                $this->handleSalla($store, $event, $payload);
                break;
            case 'woocommerce':
                $this->handleWoo($store, $event, $payload);
                break;
            case 'zid':
                $this->handleZid($store, $event, $payload);
                break;
            case 'amazon':
                $this->handleAmazon($store, $event, $payload);
                break;
            case 'noon':
                $this->handleNoon($store, $event, $payload);
                break;
            case 'trendyol':
                $this->handleTrendyol($store, $event, $payload);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleShopify(Store $store, $event, $payload)
    {
        if (str_contains($event, 'orders/')) {
            $this->syncOrder($store, $payload['id'] ?? null, $event);
        }
        if (str_contains($event, 'products/')) {
            // Inventory reconciles by SKU across the whole store, so there is no
            // single-product path — re-pull this store's levels.
            SyncInventoryJob::dispatch($store);
        }
    }

    protected function handleSalla(Store $store, $event, $payload)
    {
        if (str_contains($event, 'order.')) {
            $this->syncOrder($store, $payload['data']['id'] ?? null, $event);
        }
    }

    protected function handleWoo(Store $store, $event, $payload)
    {
        if (str_contains($event, 'order.')) {
            $this->syncOrder($store, $payload['id'] ?? null, $event);
        }
    }

    protected function handleZid(Store $store, $event, $payload)
    {
        // Zid specific logic
    }

    protected function handleAmazon(Store $store, $event, $payload)
    {
        // SP-API order notifications (e.g. ORDER_CHANGE / ORDER_STATUS_CHANGE).
        if (stripos($event, 'order') !== false) {
            $orderId = $payload['Payload']['OrderChangeNotification']['AmazonOrderId']
                ?? $payload['orderId']
                ?? null;

            $this->syncOrder($store, $orderId, $event);
        }
    }

    protected function handleNoon(Store $store, $event, $payload)
    {
        if (stripos($event, 'order') !== false) {
            $orderId = $payload['data']['id'] ?? $payload['order_id'] ?? null;

            $this->syncOrder($store, $orderId, $event);
        }
    }

    protected function handleTrendyol(Store $store, $event, $payload)
    {
        // Trendyol pushes order/package status events; pull the affected order.
        if (stripos($event, 'order') !== false || stripos($event, 'package') !== false) {
            $orderId = $payload['orderNumber']
                ?? $payload['data']['orderNumber']
                ?? $payload['data']['id']
                ?? $payload['order_id']
                ?? null;

            $this->syncOrder($store, $orderId, $event);
        }
    }

    /**
     * Queue a re-pull of the one order the webhook is about.
     *
     * Without an id there is nothing to narrow the sync to, and dispatching an
     * unscoped job would re-sync the entire store on every such webhook.
     */
    protected function syncOrder(Store $store, $orderId, string $event): void
    {
        if (blank($orderId)) {
            Log::warning("Ignored {$store->platform} webhook [{$event}] for store {$store->id}: no order id in payload.");

            return;
        }

        SyncOrdersJob::dispatch($store, (string) $orderId);
    }

    /**
     * Map an inbound webhook back to the connected store that owns it.
     *
     * Platforms identify the merchant differently (shop domain, merchant id,
     * supplier id, seller id) but we persist whichever one the merchant gave us
     * on connect as `stores.domain` / `integrations.shop_domain`, so we try each
     * candidate the payload offers against those columns.
     */
    protected function resolveStore(Request $request, string $platform, array $payload): ?Store
    {
        foreach ($this->storeIdentifiers($request, $platform, $payload) as $identifier) {
            $identifier = trim((string) $identifier);

            if ($identifier === '') {
                continue;
            }

            $store = Store::where('platform', $platform)
                ->where(function ($query) use ($identifier) {
                    $query->where('domain', $identifier)
                        ->orWhereHas('integration', function ($integration) use ($identifier) {
                            $integration->where('shop_domain', $identifier)
                                ->orWhere('platform_id', $identifier);
                        });
                })
                ->first();

            if ($store) {
                return $store;
            }
        }

        return null;
    }

    /**
     * Candidate merchant identifiers a platform may send, most specific first.
     *
     * @return array<int, mixed>
     */
    protected function storeIdentifiers(Request $request, string $platform, array $payload): array
    {
        return match ($platform) {
            'shopify' => [
                $request->header('X-Shopify-Shop-Domain'),
                $payload['shop_domain'] ?? null,
            ],
            'salla' => [
                $payload['merchant'] ?? null,
                $payload['data']['store']['id'] ?? null,
                $payload['store_id'] ?? null,
            ],
            'woocommerce' => [
                // WooCommerce sends the source as a full URL; we store bare hosts.
                $this->host($request->header('X-WC-Webhook-Source')),
                $this->host($payload['store_url'] ?? null),
            ],
            'zid' => [
                $request->header('X-Zid-Store-Id'),
                $payload['store_id'] ?? null,
            ],
            'amazon' => [
                $payload['Payload']['OrderChangeNotification']['SellerId'] ?? null,
                $payload['sellerId'] ?? null,
                $payload['NotificationMetadata']['SellerId'] ?? null,
            ],
            'noon' => [
                $payload['partner_code'] ?? null,
                $payload['data']['partner_code'] ?? null,
                $payload['seller_code'] ?? null,
            ],
            'trendyol' => [
                $payload['supplierId'] ?? null,
                $payload['data']['supplierId'] ?? null,
            ],
            default => [],
        };
    }

    /** Reduce a URL to the bare host we persist on the store. */
    protected function host(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        return parse_url($url, PHP_URL_HOST) ?: rtrim(preg_replace('#^https?://#', '', trim($url)), '/');
    }
}
