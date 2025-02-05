<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Combo extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('articles');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount');
    }

    public function sales() {
        return $this->belongsToMany('App\Models\Sale')->withPivot('amount', 'price');
    }
}
