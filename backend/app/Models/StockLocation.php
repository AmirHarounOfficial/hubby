<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bin/shelf within a warehouse (spec 08 §3.2). Advisory and auditable, NOT authoritative for qty. */
class StockLocation extends Model
{
    public const TYPES = ['bin', 'shelf', 'staging', 'receiving', 'packing', 'quarantine'];

    protected $fillable = [
        'organization_id', 'warehouse_id', 'code', 'name', 'type', 'barcode', 'sequence', 'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
