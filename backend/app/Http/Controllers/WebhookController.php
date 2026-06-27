<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SyncOrdersJob;
use App\Jobs\SyncInventoryJob;
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
                 ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown');

        switch ($platform) {
            case 'shopify':
                $this->handleShopify($event, $payload);
                break;
            case 'salla':
                $this->handleSalla($event, $payload);
                break;
            case 'woocommerce':
                $this->handleWoo($event, $payload);
                break;
            case 'zid':
                $this->handleZid($event, $payload);
                break;
            case 'amazon':
                $this->handleAmazon($event, $payload);
                break;
            case 'noon':
                $this->handleNoon($event, $payload);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleShopify($event, $payload)
    {
        if (str_contains($event, 'orders/')) {
            SyncOrdersJob::dispatch($payload['id'], 'shopify');
        }
        if (str_contains($event, 'products/')) {
            SyncInventoryJob::dispatch($payload['id'], 'shopify');
        }
    }

    protected function handleSalla($event, $payload)
    {
        if (str_contains($event, 'order.')) {
            SyncOrdersJob::dispatch($payload['data']['id'], 'salla');
        }
    }

    protected function handleWoo($event, $payload)
    {
        if (str_contains($event, 'order.')) {
            SyncOrdersJob::dispatch($payload['id'], 'woocommerce');
        }
    }

    protected function handleZid($event, $payload)
    {
        // Zid specific logic
    }

    protected function handleAmazon($event, $payload)
    {
        // SP-API order notifications (e.g. ORDER_CHANGE / ORDER_STATUS_CHANGE).
        if (stripos($event, 'order') !== false) {
            $orderId = $payload['Payload']['OrderChangeNotification']['AmazonOrderId']
                ?? $payload['orderId']
                ?? null;
            if ($orderId) {
                SyncOrdersJob::dispatch($orderId, 'amazon');
            }
        }
    }

    protected function handleNoon($event, $payload)
    {
        if (stripos($event, 'order') !== false) {
            $orderId = $payload['data']['id'] ?? $payload['order_id'] ?? null;
            if ($orderId) {
                SyncOrdersJob::dispatch($orderId, 'noon');
            }
        }
    }
}
