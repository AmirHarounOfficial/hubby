<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SyncLog;
use App\Services\Integrations\IntegrationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Store $store;

    /**
     * When set, only the order with this platform id is synced — the webhook
     * path, where re-pulling the store's whole order history would be wasteful.
     */
    public ?string $externalId;

    /**
     * Create a new job instance.
     *
     * With no store the job fans out to one instance per store (the scheduler
     * path). With a store and no external id it syncs that store's orders.
     */
    public function __construct(?Store $store = null, ?string $externalId = null)
    {
        $this->store = $store;
        $this->externalId = $externalId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->store) {
            Store::all()->each(fn($store) => self::dispatch($store));
            return;
        }
        $log = SyncLog::create([
            'store_id' => $this->store->id,
            'type' => 'orders',
            'status' => 'in_progress',
        ]);

        try {
            $service = $this->getService();
            $orders = $service->fetchOrders($this->store, $this->fetchParams());
            $synced = 0;

            foreach ($orders as $orderData) {
                $mappedData = $this->mapOrderData($orderData);

                // Platforms we can't narrow server-side return the full page, so
                // filter down to the webhook's order here.
                if ($this->externalId !== null && ($mappedData['external_id'] ?? null) !== $this->externalId) {
                    continue;
                }

                $synced++;

                $order = Order::updateOrCreate(
                    [
                        'store_id' => $this->store->id,
                        'external_id' => $mappedData['external_id'],
                    ],
                    [
                        'status' => $mappedData['status'],
                        'total' => $mappedData['total'],
                        'currency' => $mappedData['currency'],
                        'customer_name' => $mappedData['customer_name'],
                        'customer_email' => $mappedData['customer_email'],
                        // The platform's order date, so analytics buckets by when the order was
                        // placed, not when we happened to sync it (defect #7).
                        'placed_at' => $this->parseDate($mappedData['placed_at'] ?? null),
                        'raw_data' => $orderData,
                    ]
                );

                // Sync items
                foreach ($mappedData['items'] as $itemData) {
                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $order->id,
                            'external_id' => $itemData['external_id'] ?? null,
                        ],
                        [
                            'name' => $itemData['name'],
                            'sku' => $itemData['sku'] ?? null,
                            'quantity' => $itemData['quantity'],
                            'price' => $itemData['price'],
                        ]
                    );
                }

                // Refresh the P&L rollup for this order now that its lines are in place.
                CalculateOrderProfitJob::dispatch($order->id);
            }

            $log->update(['status' => 'success']);

            if ($this->externalId !== null) {
                // Webhook-driven single-order sync: no notification (these fire
                // per order and would drown the merchant's feed).
                if ($synced === 0) {
                    Log::warning("SyncOrdersJob: order {$this->externalId} not returned by {$this->store->platform} for store {$this->store->id}.");
                }

                return;
            }

            \App\Models\Notification::create([
                'organization_id' => $this->store->organization_id,
                'title' => 'Sync Complete',
                'message' => "Successfully synced orders for {$this->store->name} ({$this->store->platform}).",
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error("SyncOrdersJob failed for store {$this->store->id}: " . $e->getMessage());
            $log->update(['status' => 'failed', 'message' => $e->getMessage()]);

            \App\Models\Notification::create([
                'organization_id' => $this->store->organization_id,
                'title' => 'Sync Failed',
                'message' => "Failed to sync orders for {$this->store->name}: " . $e->getMessage(),
                'type' => 'error',
            ]);
        }
    }

    protected function getService()
    {
        return IntegrationFactory::make($this->store->platform);
    }

    /**
     * Normalise a platform-supplied order date to a Carbon instance, or null if it is
     * missing/unparseable — the caller stores null and analytics falls back to created_at.
     */
    protected function parseDate($value): ?\Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            // Carbon::parse handles ISO strings and DateTimeInterface (e.g. the Trendyol Carbon) alike.
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Query params narrowing the fetch to a single order, where the platform
     * supports it. Others fall back to a normal fetch plus a local filter.
     *
     * @return array<string, mixed>
     */
    protected function fetchParams(): array
    {
        if ($this->externalId === null) {
            return [];
        }

        return match ($this->store->platform) {
            // status=any is required — Shopify's order list defaults to open only.
            'shopify' => ['ids' => $this->externalId, 'status' => 'any'],
            default => [],
        };
    }

    protected function mapOrderData(array $data): array
    {
        if ($this->store->platform === 'shopify') {
            return [
                'external_id' => (string) $data['id'],
                'status' => $data['financial_status'],
                'total' => $data['total_price'],
                'currency' => $data['currency'],
                'customer_name' => ($data['customer']['first_name'] ?? '') . ' ' . ($data['customer']['last_name'] ?? ''),
                'customer_email' => $data['customer']['email'] ?? null,
                'placed_at' => $data['created_at'] ?? $data['processed_at'] ?? null,
                'items' => array_map(fn($item) => [
                    'external_id' => (string) $item['id'],
                    'name' => $item['title'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ], $data['line_items']),
            ];
        }

        if ($this->store->platform === 'salla') {
            return [
                'external_id' => (string) $data['id'],
                'status' => $data['status']['name'] ?? 'pending',
                'total' => $data['amounts']['total']['amount'] ?? 0,
                'currency' => $data['amounts']['total']['currency'] ?? 'SAR',
                'customer_name' => ($data['customer']['first_name'] ?? '') . ' ' . ($data['customer']['last_name'] ?? ''),
                'customer_email' => $data['customer']['email'] ?? null,
                'placed_at' => $data['date']['date'] ?? $data['created_at'] ?? null,
                'items' => array_map(fn($item) => [
                    'external_id' => (string) $item['id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']['amount'] ?? 0,
                ], $data['items'] ?? []),
            ];
        }

        if ($this->store->platform === 'trendyol') {
            return [
                'external_id' => (string) ($data['orderNumber'] ?? $data['id']),
                'status' => strtolower($data['status'] ?? $data['shipmentPackageStatus'] ?? 'pending'),
                'total' => $data['totalPrice'] ?? $data['grossAmount'] ?? 0,
                'currency' => $data['currencyCode'] ?? 'TRY',
                'customer_name' => trim(($data['customerFirstName'] ?? '') . ' ' . ($data['customerLastName'] ?? '')),
                'customer_email' => $data['customerEmail'] ?? null,
                // Trendyol sends orderDate as epoch milliseconds.
                'placed_at' => isset($data['orderDate'])
                    ? \Illuminate\Support\Carbon::createFromTimestampMs((int) $data['orderDate'])
                    : null,
                'items' => array_map(fn($item) => [
                    'external_id' => (string) ($item['id'] ?? $item['lineItemId'] ?? ''),
                    'name' => $item['productName'] ?? $item['name'] ?? '',
                    'sku' => $item['sku'] ?? $item['merchantSku'] ?? $item['barcode'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? $item['amount'] ?? 0,
                ], $data['lines'] ?? $data['items'] ?? []),
            ];
        }

        return $data;
    }
}
