<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleMeliAttribute extends Model
{
    protected $table = 'article_meli_attribute';
    
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('meli_attribute', 'meli_attribute_value');
    }

    function meli_attribute() {
        return $this->belongsTo(MeliAttribute::class);
    }

    function meli_attribute_value() {
        return $this->belongsTo(MeliAttributeValue::class);
    }
}
