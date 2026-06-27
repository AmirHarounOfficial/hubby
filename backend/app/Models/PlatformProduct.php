<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformProduct extends Model
{
    protected $fillable = [
        'product_variant_id',
        'store_id',
        'external_id',
        'sync_enabled',
    ];

    protected $casts = [
        'sync_enabled' => 'boolean',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
