<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A packing session for one box of an order (spec 08 §3.5/§4.2). */
class PackSession extends Model
{
    public const STATUSES = ['open', 'verifying', 'verified', 'labelled', 'closed', 'voided'];

    protected $fillable = [
        'organization_id', 'order_id', 'pick_list_id', 'warehouse_id', 'code', 'status',
        'package_index', 'package_count', 'weight_grams', 'length_mm', 'width_mm', 'height_mm',
        'packaging_type', 'shipment_ref', 'label_url', 'packed_by_user_id', 'verified_at',
        'completed_at', 'voided_at', 'meta',
    ];

    protected $casts = [
        'package_index' => 'integer',
        'package_count' => 'integer',
        'weight_grams' => 'integer',
        'verified_at' => 'datetime',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
        'meta' => 'array',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'verifying', 'verified', 'labelled'], true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackSessionItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
