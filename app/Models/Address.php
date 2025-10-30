<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount');
    }
}
