<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('sellers');
    }
    
    public function sellers() {
        return $this->belongsToMany('App\Models\Seller')->withPivot('percentage');
    }
}
