<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'organization_id',
        'sku',
        'price',
        'stock',
    ];

    protected static function booted(): void
    {
        // Keep the denormalised organization_id in step with the parent product, so every
        // creation path (product CRUD, product sync) gets per-org SKU scoping for free.
        static::creating(function (ProductVariant $variant) {
            if ($variant->organization_id === null && $variant->product_id !== null) {
                $variant->organization_id = Product::whereKey($variant->product_id)->value('organization_id');
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function platformProducts(): HasMany
    {
        return $this->hasMany(PlatformProduct::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }
}
