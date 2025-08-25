<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryPerformance extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles_stock_minimo');
    }

    function articles_stock_minimo() {
        return $this->belongsToMany(Article::class);
    }
}
