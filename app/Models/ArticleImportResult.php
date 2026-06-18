<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Resultado de un chunk de importación de artículos.
 * Registra conteos, artículos creados/actualizados y artículos con código repetido.
 */
class ArticleImportResult extends Model
{
    protected $guarded = [];

    /**
     * Conversión automática de columnas JSON a array PHP.
     * created_with_repeated_code_ids: IDs de artículos creados que duplicaron un código en BD.
     */
    protected $casts = [
        'created_with_repeated_code_ids' => 'array',
    ];

    function scopeWithAll($q) {
        
    }

    function article_import_result_observations() {
        return $this->hasMany(ArticleImportResultObservation::class);
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
