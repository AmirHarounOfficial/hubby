<?php

namespace App\Jobs;

use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Integrations\IntegrationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $variant;
    protected $sourceStore;

    /**
     * Create a new job instance.
     */
    public function __construct(ProductVariant $variant, Store $sourceStore = null)
    {
        $this->variant = $variant;
        $this->sourceStore = $sourceStore;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $organization = $this->variant->product->organization;
        $stores = $organization->stores()
            ->where('status', 'connected')
            ->when($this->sourceStore, function ($query) {
                return $query->where('id', '!=', $this->sourceStore->id);
            })
            ->get();

        foreach ($stores as $store) {
            try {
                $service = $this->getService($store);
                $service->updateInventory($store, $this->variant->sku, $this->variant->stock);
            } catch (\Exception $e) {
                Log::error("PushInventoryJob failed for store {$store->id}: " . $e->getMessage());
            }
        }
    }

    protected function getService(Store $store)
    {
        return IntegrationFactory::make($store->platform);
    }
}
