<?php

namespace App\Services\Warehouse;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;

/**
 * What a scanned barcode turned out to be (spec 08 §4.0) — a tagged union of item / location /
 * order / unknown, so callers branch on `kind` rather than sniffing nullable fields.
 */
final class ResolveResult
{
    public const KIND_ITEM = 'item';
    public const KIND_LOCATION = 'location';
    public const KIND_ORDER = 'order';
    public const KIND_UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $kind,
        public readonly string $barcode,
        public readonly ?Product $product = null,
        public readonly ?ProductVariant $variant = null,
        public readonly int $packSize = 1,
        public readonly ?StockLocation $location = null,
        public readonly ?Order $order = null,
        public readonly ?string $matchedVia = null,
        public readonly ?bool $checkDigitValid = null,
    ) {
    }

    public static function item(string $barcode, ?Product $product, ?ProductVariant $variant, int $packSize, string $via, ?bool $checkDigit = null): self
    {
        return new self(self::KIND_ITEM, $barcode, $product, $variant, $packSize, null, null, $via, $checkDigit);
    }

    public static function location(string $barcode, StockLocation $location, string $via): self
    {
        return new self(self::KIND_LOCATION, $barcode, location: $location, matchedVia: $via);
    }

    public static function order(string $barcode, Order $order, string $via): self
    {
        return new self(self::KIND_ORDER, $barcode, order: $order, matchedVia: $via);
    }

    public static function unknown(string $barcode, ?bool $checkDigit = null): self
    {
        return new self(self::KIND_UNKNOWN, $barcode, checkDigitValid: $checkDigit);
    }

    public function isUnknown(): bool
    {
        return $this->kind === self::KIND_UNKNOWN;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = [
            'kind' => $this->kind,
            'barcode' => $this->barcode,
            'matched_via' => $this->matchedVia,
            'check_digit_valid' => $this->checkDigitValid,
        ];

        return match ($this->kind) {
            self::KIND_ITEM => $base + [
                'pack_size' => $this->packSize,
                'product' => $this->product ? [
                    'id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku,
                    'stock' => $this->product->stock,
                ] : null,
                'variant' => $this->variant ? [
                    'id' => $this->variant->id, 'sku' => $this->variant->sku, 'stock' => $this->variant->stock,
                    'price' => $this->variant->price,
                ] : null,
            ],
            self::KIND_LOCATION => $base + [
                'location' => [
                    'id' => $this->location->id, 'code' => $this->location->code,
                    'name' => $this->location->name, 'type' => $this->location->type,
                    'warehouse_id' => $this->location->warehouse_id,
                ],
            ],
            self::KIND_ORDER => $base + [
                'order' => [
                    'id' => $this->order->id, 'external_id' => $this->order->external_id,
                    'status' => $this->order->status,
                ],
            ],
            default => $base,
        };
    }
}
