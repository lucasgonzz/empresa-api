<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TiendaNubeOrder extends Model
{

    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles.images');
    }

    public function articles()
    {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'price');
    }

}
