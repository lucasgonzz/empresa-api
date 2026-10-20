<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SerperUsageEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['ok' => 'boolean', 'credits' => 'float', 'occurred_at' => 'datetime', 'synced_at' => 'datetime'];

    public function scopeWithAll($query)
    {
    }
}
