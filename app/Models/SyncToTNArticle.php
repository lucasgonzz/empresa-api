<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncToTNArticle extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('article');
    }

    function article() {
        return $this->belongsTo(Article::class);
    }
}
