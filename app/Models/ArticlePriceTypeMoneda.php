<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticlePriceTypeMoneda extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function price_type()
    {
        return $this->belongsTo(PriceType::class);
    }

    public function moneda()
    {
        return $this->belongsTo(Moneda::class);
    }
}
