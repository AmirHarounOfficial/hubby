<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An order line packed into a shipment/package (spec 04 §3.5). */
class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id', 'shipment_package_id', 'order_item_id', 'return_item_id', 'sku', 'name',
        'quantity', 'unit_weight_kg', 'unit_value', 'hs_code', 'country_of_origin',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_weight_kg' => 'decimal:3',
        'unit_value' => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
