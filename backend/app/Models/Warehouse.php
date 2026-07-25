<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical warehouse (spec 08 §3.1). */
class Warehouse extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'code', 'address', 'timezone', 'is_default', 'is_active', 'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    /** Codes are printed on labels and typed by hand — always uppercase. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(StockLocation::class);
    }
}
