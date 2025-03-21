<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleSurchage extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function article() {
        return $this->belongsTo('App\Models\Article');
    }
}
