<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A refund record (spec 03 §3.5). */
class Refund extends Model
{
    protected $fillable = [
        'organization_id', 'store_id', 'order_id', 'return_request_id', 'external_id', 'issuer',
        'method', 'status', 'amount', 'items_amount', 'shipping_amount', 'tax_amount', 'fee_amount',
        'currency', 'gateway', 'reason', 'failure_reason', 'attempts', 'idempotency_key',
        'processed_at', 'created_by_user_id', 'raw_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'items_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
