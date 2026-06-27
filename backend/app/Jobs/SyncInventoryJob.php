<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\ProductVariant;
use App\Models\InventoryLog;
use App\Models\SyncLog;
use App\Services\Integrations\ShopifyService;
use App\Services\Integrations\SallaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInventoryJob implements ShouldQueue
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
        // This job usually runs via webhook or frequently via cron for the master store.
        // If it's the master store, we sync its stock to our central DB, 
        // and then PushInventoryJob will push to other stores.
        
        $log = SyncLog::create([
            'store_id' => $this->store->id,
            'type' => 'inventory',
            'status' => 'in_progress',
        ]);

        try {
            $service = $this->getService();
            $inventory = $service->fetchInventory($this->store);

            foreach ($inventory as $item) {
                $variant = ProductVariant::where('sku', $item['sku'])->first();
                if ($variant) {
                    $oldStock = $variant->stock;
                    $newStock = $item['quantity'];
                    
                    if ($oldStock != $newStock) {
                        $variant->update(['stock' => $newStock]);
                        
                        InventoryLog::create([
                            'product_variant_id' => $variant->id,
                            'change' => $newStock - $oldStock,
                            'source' => $this->store->platform,
                            'reason' => 'Sync from platform',
                        ]);

                        // Trigger push to other stores if this is the master store
                        if ($this->store->is_master) {
                            PushInventoryJob::dispatch($variant, $this->store);
                        }
                    }
                }
            }

            $log->update(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error("SyncInventoryJob failed for store {$this->store->id}: " . $e->getMessage());
            $log->update(['status' => 'failed', 'message' => $e->getMessage()]);
        }
    }

    protected function getService()
    {
        return match ($this->store->platform) {
            'shopify' => new ShopifyService(),
            'salla' => new SallaService(),
            default => throw new \Exception("Platform not supported"),
        };
    }
}
