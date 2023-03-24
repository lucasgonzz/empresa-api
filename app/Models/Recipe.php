<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('article', 'articles');
    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withPivot('amount', 'notes', 'order_production_status_id');
    }
}
