<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A barcode mapped to a sellable item (spec 08 §3.3). Unique per organization. */
class ProductBarcode extends Model
{
    protected $fillable = [
        'organization_id', 'product_id', 'product_variant_id', 'barcode', 'barcode_raw',
        'symbology', 'store_id', 'is_primary', 'pack_size', 'source', 'created_by_user_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'pack_size' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
