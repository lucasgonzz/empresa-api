<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function provincia() {
        return $this->belongsTo(Provincia::class);
    }
}
