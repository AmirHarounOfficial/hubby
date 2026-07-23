<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A city spelling variant → canonical key (spec 04 §4.8). */
class CityAlias extends Model
{
    protected $fillable = [
        'organization_id', 'country_code', 'alias', 'canonical', 'canonical_en', 'canonical_ar',
    ];
}
