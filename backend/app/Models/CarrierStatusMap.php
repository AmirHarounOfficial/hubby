<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Data-driven carrier→normalized status mapping (spec 04 §3.11). */
class CarrierStatusMap extends Model
{
    protected $table = 'carrier_status_map';

    protected $fillable = [
        'carrier_code', 'raw_code', 'raw_status', 'normalized_status', 'is_exception', 'is_final',
        'description_en', 'description_ar', 'priority',
    ];

    protected $casts = [
        'is_exception' => 'boolean',
        'is_final' => 'boolean',
        'priority' => 'integer',
    ];
}
