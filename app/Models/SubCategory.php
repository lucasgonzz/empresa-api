<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubCategory extends Model
{

    use SoftDeletes;

    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('category', 'price_types');        
    }

    function category() {
        return $this->belongsTo('App\Models\Category');
    }

    function category_price_type_ranges() {
        return $this->hasMany(CategoryPriceTypeRange::class)->orderBy('min', 'asc');
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('percentage');
    }
    
    public function articles() {
        return $this->hasMany('App\Models\Article');
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }
}
