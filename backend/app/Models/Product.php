<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'sku',
        'price',
        'description',
        'stock',
        'image_url',
        'status',
        'category_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function platformProducts()
    {
        return $this->hasManyThrough(PlatformProduct::class, ProductVariant::class);
    }

    public function stores()
    {
        return $this->hasManyThrough(
            Store::class, 
            PlatformProduct::class, 
            'id', // Not really used this way with nested through, but let's see
            'id', 
            'id', 
            'store_id'
        );
    }
}
