<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticlesPreImport extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('articles');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->select('name', 'provider_code')->withPivot('costo_actual', 'costo_nuevo', 'actualizado');
    }

}
