<?php

namespace App\Services\Warehouse;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Support\BarcodeNormalizer;

/**
 * Resolve a scanned string to whatever it actually is (spec 08 §4.0). Shared by every workflow.
 *
 * Resolution order matters and is deliberate:
 *   1. product_barcodes  — a stored barcode always beats a SKU coincidence
 *   2/3. variant / product SKU — SKU-as-barcode fallback (very common on small catalogues)
 *   4. stock_locations   — checked AFTER items, because a bin code like A-01-3 could collide with a
 *                          SKU; if it does the item wins, which is why location creation warns on
 *                          a colliding code
 *   5. orders            — packing-slip scan
 *
 * Everything is scoped by organization_id: a barcode is unambiguous inside a tenant, and cross-tenant
 * collisions are expected (two orgs selling the same EAN).
 */
class BarcodeResolver
{
    public function resolve(int $organizationId, string $raw): ResolveResult
    {
        $variants = BarcodeNormalizer::variants($raw);
        $primary = $variants[0];
        $checkDigit = BarcodeNormalizer::checkDigitValid($raw);

        // 1. Stored barcode (authoritative). Try every equivalent form so a UPC-A scan matches an
        //    EAN-13 catalogue and vice-versa.
        $barcode = ProductBarcode::where('organization_id', $organizationId)
            ->whereIn('barcode', $variants)
            ->with(['product', 'variant'])
            ->first();

        if ($barcode) {
            return ResolveResult::item(
                $primary,
                $barcode->product,
                $barcode->variant,
                max(1, (int) $barcode->pack_size),
                'product_barcode',
                $checkDigit,
            );
        }

        // 2. Variant SKU.
        $variant = ProductVariant::whereIn('sku', $variants)
            ->whereHas('product', fn ($q) => $q->where('organization_id', $organizationId))
            ->with('product')
            ->first();

        if ($variant) {
            return ResolveResult::item($primary, $variant->product, $variant, 1, 'variant_sku', $checkDigit);
        }

        // 3. Product SKU.
        $product = Product::where('organization_id', $organizationId)->whereIn('sku', $variants)->first();
        if ($product) {
            return ResolveResult::item($primary, $product, null, 1, 'product_sku', $checkDigit);
        }

        // 4. Location label.
        $location = StockLocation::where('organization_id', $organizationId)
            ->where(fn ($q) => $q->whereIn('barcode', $variants)->orWhereIn('code', $variants))
            ->first();

        if ($location) {
            return ResolveResult::location($primary, $location, 'stock_location');
        }

        // 5. Order (packing slip / picking-list handoff).
        $order = Order::whereIn('external_id', $variants)
            ->whereHas('store', fn ($q) => $q->where('organization_id', $organizationId))
            ->first();

        if ($order) {
            return ResolveResult::order($primary, $order, 'order_external_id');
        }

        return ResolveResult::unknown($primary, $checkDigit);
    }
}
