<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockSuggestion extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    public function articles()
    {
        return $this->hasMany(StockSuggestionArticle::class);
    }
}
