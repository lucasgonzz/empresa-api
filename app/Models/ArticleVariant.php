<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleVariant extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article_property_values', 'addresses');
    }

    function article() {
        return $this->belongsTo(Article::class);
    }

    function article_property_values() {
        return $this->belongsToMany(ArticlePropertyValue::class);
    }

    function addresses() {
        return $this->belongsToMany(Address::class)->withPivot('amount');
    }
}
