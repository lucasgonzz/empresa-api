<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceType extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('sub_categories');
    }

    function sub_categories() {
        return $this->belongsToMany(SubCategory::class)->withPivot('percentage');
    }
}
