<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
	use SoftDeletes;
	
	protected $guarded = [];	

    function scopeWithAll($q) {
        $q->with('price_types');
    }

    function sub_categories() {
        return $this->hasMany(SubCategory::class);
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('percentage');
    }

    function category_price_type_ranges() {
        return $this->hasMany(CategoryPriceTypeRange::class)->orderBy('min', 'asc');
    }
}
