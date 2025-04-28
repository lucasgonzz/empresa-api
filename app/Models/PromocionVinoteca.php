<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromocionVinoteca extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles', 'images');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'unidades_por_promo');
    }

    function images() {
        return $this->morphMany('App\Models\Image', 'imageable');
    }
}
