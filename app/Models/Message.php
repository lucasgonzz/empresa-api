<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('article.images', 'article.colors', 'article.sizes', 'article.questions');
    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
