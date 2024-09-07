<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticlePerformance extends Model
{
    protected $guarded = [];

    protected $dates = ['performance_date'];

    function scopeWithAll($q) {
        
    }
}
