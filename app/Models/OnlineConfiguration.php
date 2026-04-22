<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlineConfiguration extends Model
{
    protected $guarded = [];
    protected $casts = [
        'auto_scroll_home' => 'integer',
        'auto_scroll_home_init' => 'integer',
        'auto_scroll_home_interval' => 'integer',
    ];

    function scopeWithAll($q) {
        
    }
}
