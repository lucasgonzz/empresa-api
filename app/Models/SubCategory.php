<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubCategory extends Model
{

    use SoftDeletes;

    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('category');        
    }

    function category() {
    	return $this->belongsTo('App\Models\Category');
    }
    
    public function articles() {
        return $this->hasMany('App\Models\Article');
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }
}
