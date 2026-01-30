<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleImportResult extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    function articulos_creados() {
        return $this->belongsToMany(Article::class, 'article_creados_article_import_result');
    }

    function articulos_actualizados() {
        return $this->belongsToMany(Article::class, 'article_actualizados_article_import_result')
                    ->using(ArticleActualizadosImportHistory::class)
                    ->withPivot('updated_props');
    }
}
