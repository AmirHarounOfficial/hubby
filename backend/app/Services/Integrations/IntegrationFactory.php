<?php

namespace App\Services\Integrations;

class IntegrationFactory
{
    public static function make(string $platform): IntegrationServiceInterface
    {
        return match (strtolower($platform)) {
            'shopify' => new ShopifyService(),
            'salla' => new SallaService(),
            'woocommerce' => new WooCommerceService(),
            'zid' => new ZidService(),
            'amazon' => new AmazonService(),
            'noon' => new NoonService(),
            default => throw new \Exception("Platform [{$platform}] not supported"),
        };
    }
}
