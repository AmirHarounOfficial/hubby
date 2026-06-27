<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'organization_id',
        'plan_id',
        'external_ref',
        'status',
        'starts_at',
        'trial_ends_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
