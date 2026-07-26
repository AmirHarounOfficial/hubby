<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One counted line (spec 08 §3.7). counted_qty is absolute, never a delta. */
class CountEntry extends Model
{
    protected $fillable = [
        'count_session_id', 'product_id', 'product_variant_id', 'sku', 'name', 'stock_location_id',
        'expected_qty', 'counted_qty', 'live_qty_at_approval', 'variance', 'status',
        'recount_of_entry_id', 'counted_by_user_id', 'client_seq', 'client_counted_at',
        'counted_at', 'note',
    ];

    protected $casts = [
        'expected_qty' => 'integer',
        'counted_qty' => 'integer',
        'live_qty_at_approval' => 'integer',
        'variance' => 'integer',
        'client_seq' => 'integer',
        'client_counted_at' => 'datetime',
        'counted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
