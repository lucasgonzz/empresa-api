<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncFromMeliArticle extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles');
    }

    function articles() {
        return $this->belongsToMany(Article::class, 'sync_from_meli_article_article')->withPivot('status', 'error_code');
    }
}
