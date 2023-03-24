<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Combo extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withPivot('amount');
    }

    public function sales() {
        return $this->belongsToMany('App\Models\Sale')->withPivot('amount', 'price');
    }
}
