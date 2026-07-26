<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a packing session (spec 08 §3.5). */
class PackSessionItem extends Model
{
    protected $fillable = [
        'pack_session_id', 'order_item_id', 'product_variant_id', 'sku', 'name',
        'qty_required', 'qty_packed',
    ];

    protected $casts = [
        'qty_required' => 'integer',
        'qty_packed' => 'integer',
    ];

    public function remaining(): int
    {
        return max(0, (int) $this->qty_required - (int) $this->qty_packed);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PackSession::class, 'pack_session_id');
    }
}
