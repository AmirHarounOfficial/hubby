<?php

namespace App\Services\Integrations;

use App\Models\Store;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

class ZidService extends BaseIntegrationService
{
    public function getAuthUrl(): string
    {
        return "";
    }

    public function exchangeCode(string $code): array
    {
        return [];
    }

    public function refreshToken(Integration $integration): void
    {
    }

    public function fetchOrders(Store $store, array $params = []): array
    {
        return [];
    }

    public function fetchProducts(Store $store): array
    {
        return [];
    }

    public function fetchInventory(Store $store): array
    {
        return [];
    }

    public function updateInventory(Store $store, string $sku, int $qty): bool
    {
        return true;
    }

    public function updateOrderStatus(Store $store, string $externalId, string $status): bool
    {
        return true;
    }

    public function cancelOrder(Store $store, string $externalId): bool
    {
        return true;
    }
}
