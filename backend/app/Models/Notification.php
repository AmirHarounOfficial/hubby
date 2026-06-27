<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'message',
        'type',
        'read_at',
    ];
}
