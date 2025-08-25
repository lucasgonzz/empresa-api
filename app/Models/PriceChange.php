<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceChange extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('price_types');        
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('final_price');
    }
}
