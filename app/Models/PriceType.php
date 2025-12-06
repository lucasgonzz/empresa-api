<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceType extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('categories', 'sub_categories', 'price_type_surchages');
    }

    function articles() {
        return $this->belongsToMany(Article::class);
    }

    function monedas() {
        return $this->belongsToMany(Moneda::class)->withPivot('percentage', 'final_price', 'setear_precio_final');
    }

    function categories() {
        return $this->belongsToMany(Category::class)->withPivot('percentage');
    }

    function sub_categories() {
        return $this->belongsToMany(SubCategory::class)->withPivot('percentage');
    }

    function price_type_surchages() {
        return $this->hasMany(PriceTypeSurchage::class)->orderBy('position', 'ASC');
    }
}
