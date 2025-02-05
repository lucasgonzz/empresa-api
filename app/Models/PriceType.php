<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceType extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('categories', 'sub_categories');
    }

    function categories() {
        return $this->belongsToMany(Category::class)->withPivot('percentage');
    }

    function sub_categories() {
        return $this->belongsToMany(SubCategory::class)->withPivot('percentage');
    }
}
