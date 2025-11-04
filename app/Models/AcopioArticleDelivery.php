<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcopioArticleDelivery extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }
}
