<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a pick list (spec 08 §3.4). */
class PickListItem extends Model
{
    public const STATUSES = ['pending', 'in_progress', 'picked', 'short', 'over_pick_hold', 'skipped'];

    protected $fillable = [
        'pick_list_id', 'order_item_id', 'order_id', 'product_id', 'product_variant_id', 'sku',
        'name', 'image_url', 'stock_location_id', 'qty_required', 'qty_picked', 'qty_short',
        'status', 'short_reason', 'substituted_variant_id', 'sequence', 'picked_by_user_id',
        'picked_at',
    ];

    protected $casts = [
        'qty_required' => 'integer',
        'qty_picked' => 'integer',
        'qty_short' => 'integer',
        'sequence' => 'integer',
        'picked_at' => 'datetime',
    ];

    public function remaining(): int
    {
        return max(0, (int) $this->qty_required - (int) $this->qty_picked);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['picked', 'short'], true);
    }

    public function pickList(): BelongsTo
    {
        return $this->belongsTo(PickList::class);
    }
}
