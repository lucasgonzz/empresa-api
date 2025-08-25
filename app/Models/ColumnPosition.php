<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ColumnPosition extends Model
{
    protected $casts = [
        'positions' => 'array',
    ];
    
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }
}
