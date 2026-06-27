<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;

interface IntegrationServiceInterface
{
    public function getAuthUrl(): string;
    
    public function exchangeCode(string $code): array;
    
    public function refreshToken(Integration $integration): void;
    
    public function fetchOrders(Store $store, array $params = []): array;
    
    public function fetchProducts(Store $store): array;
    
    public function fetchInventory(Store $store): array;
    
    public function updateInventory(Store $store, string $sku, int $qty): bool;
    
    public function updateOrderStatus(Store $store, string $externalId, string $status): bool;

    public function cancelOrder(Store $store, string $externalId): bool;
}
