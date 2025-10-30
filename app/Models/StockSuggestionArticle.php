<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockSuggestionArticle extends Model
{
    
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    function from_address() {
        return $this->belongsTo(Address::class, 'from_address_id');
    }

    function to_address() {
        return $this->belongsTo(Address::class, 'to_address_id');
    }
}
