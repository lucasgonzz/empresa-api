<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bodega extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function articles() {
        return $this->hasMany(Article::class);
    }
}
