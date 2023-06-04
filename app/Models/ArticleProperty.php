<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleProperty extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article_property_type', 'article_property_values');
    }

    function article_property_type() {
        return $this->belongsTo(ArticlePropertyType::class);
    }

    function article_property_values() {
        return $this->belongsToMany(ArticlePropertyValue::class);
    }
}
