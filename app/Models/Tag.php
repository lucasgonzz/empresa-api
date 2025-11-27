<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $guarded = [];
    
    function scopeWithAll($q) {
        
    }
    
    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed();
    }
}
