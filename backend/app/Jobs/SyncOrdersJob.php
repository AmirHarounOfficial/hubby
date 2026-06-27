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

    protected $store;

    /**
     * Create a new job instance.
     */
    public function __construct(Store $store = null)
    {
        $this->store = $store;
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
            $orders = $service->fetchOrders($this->store);

            foreach ($orders as $orderData) {
                $mappedData = $this->mapOrderData($orderData);
                
                $order = Order::updateOrCreate(
                    [
                        'external_id' => $mappedData['external_id'],
                    ],
                    [
                        'store_id' => $this->store->id,
                        'status' => $mappedData['status'],
                        'total' => $mappedData['total'],
                        'currency' => $mappedData['currency'],
                        'customer_name' => $mappedData['customer_name'],
                        'customer_email' => $mappedData['customer_email'],
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
                            'product_name' => $itemData['name'],
                            'sku' => $itemData['sku'] ?? null,
                            'quantity' => $itemData['quantity'],
                            'price' => $itemData['price'],
                        ]
                    );
                }
            }

            $log->update(['status' => 'success']);
            
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
                'items' => array_map(fn($item) => [
                    'external_id' => (string) $item['id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price']['amount'] ?? 0,
                ], $data['items'] ?? []),
            ];
        }

        return $data;
    }
}
