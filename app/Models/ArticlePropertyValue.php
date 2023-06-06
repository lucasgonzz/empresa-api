<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticlePropertyValue extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article_property_type');        
    }

    function article_property_type() {
        return $this->belongsTo(ArticlePropertyType::class);
    }
}
