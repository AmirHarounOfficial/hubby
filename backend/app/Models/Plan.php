<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'price',
        'store_limit',
        'order_limit',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];
}
