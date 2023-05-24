<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricesList extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed();
    }
}
