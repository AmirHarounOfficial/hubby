<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PlatformProduct;
use App\Models\SyncLog;
use App\Services\Integrations\IntegrationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldQueue
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
            'type' => 'products',
            'status' => 'in_progress',
        ]);

        try {
            $service = $this->getService();
            $products = $service->fetchProducts($this->store);

            foreach ($products as $productData) {
                $mappedData = $this->mapProductData($productData);
                
                $product = Product::updateOrCreate(
                    [
                        'organization_id' => $this->store->organization_id,
                        'sku' => $mappedData['sku'],
                    ],
                    [
                        'name' => $mappedData['name'],
                        'description' => $mappedData['description'],
                        'price' => $mappedData['price'],
                        'stock' => $mappedData['stock'],
                        'image_url' => $mappedData['image_url'],
                    ]
                );

                foreach ($mappedData['variants'] as $variantData) {
                    $variant = ProductVariant::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'sku' => $variantData['sku'],
                        ],
                        [
                            'name' => $variantData['name'],
                            'price' => $variantData['price'],
                            'stock' => $variantData['stock'],
                        ]
                    );

                    PlatformProduct::updateOrCreate(
                        [
                            'product_variant_id' => $variant->id,
                            'store_id' => $this->store->id,
                        ],
                        [
                            'external_id' => $variantData['external_id'],
                        ]
                    );
                }
            }

            $log->update(['status' => 'success']);
            $this->store->update(['status' => 'connected', 'last_synced_at' => now()]);
        } catch (\Exception $e) {
            Log::error("SyncProductsJob failed for store {$this->store->id}: " . $e->getMessage());
            $log->update(['status' => 'failed', 'message' => $e->getMessage()]);
            $this->store->update(['status' => 'error']);
        }
    }

    protected function getService()
    {
        return IntegrationFactory::make($this->store->platform);
    }

    protected function mapProductData(array $data): array
    {
        if ($this->store->platform === 'shopify') {
            $mainVariant = $data['variants'][0] ?? null;
            return [
                'name' => $data['title'],
                'sku' => $mainVariant['sku'] ?? 'SHP-' . $data['id'],
                'description' => $data['body_html'],
                'price' => $mainVariant['price'] ?? 0,
                'stock' => array_sum(array_column($data['variants'], 'inventory_quantity')),
                'image_url' => $data['image']['src'] ?? null,
                'variants' => array_map(fn($v) => [
                    'external_id' => (string) $v['id'],
                    'name' => $v['title'],
                    'sku' => $v['sku'] ?? 'SHP-VAR-' . $v['id'],
                    'price' => $v['price'],
                    'stock' => $v['inventory_quantity'] ?? 0,
                ], $data['variants']),
            ];
        }

        if ($this->store->platform === 'salla') {
            return [
                'name' => $data['name'],
                'sku' => $data['sku'] ?? 'SAL-' . $data['id'],
                'description' => $data['description'],
                'price' => $data['price']['amount'] ?? 0,
                'stock' => $data['quantity'] ?? 0,
                'image_url' => $data['main_image'] ?? null,
                'variants' => array_map(fn($v) => [
                    'external_id' => (string) $v['id'],
                    'name' => $v['name'],
                    'sku' => $v['sku'] ?? 'SAL-VAR-' . $v['id'],
                    'price' => $v['price']['amount'] ?? 0,
                    'stock' => $v['quantity'] ?? 0,
                ], $data['skus'] ?? []), // Salla calls variants 'skus'
            ];
        }

        return $data;
    }
}
