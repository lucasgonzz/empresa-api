<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changed_fields' => 'array',
    ];

    function articulos_creados() {
        return $this->belongsToMany(Article::class, 'article_creados_import_history');
    }

    function articulos_actualizados() {
        return $this->belongsToMany(Article::class, 'article_actualizados_import_history')
                    ->using(ArticleActualizadosImportHistory::class)
                    ->withPivot('updated_props');
    }
}
