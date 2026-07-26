<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changed_fields' => 'array',
        /* Fecha en que se marcó la importación como fallida (prompt 500). */
        'failed_at'      => 'datetime',
    ];

    function chunks() {
        return $this->hasMany(ArticleImportResult::class, 'import_history_id');
    }

    /**
     * Conflictos de importación (identificadores ambiguos, placeholders descartados,
     * filas sin identificador) detectados en cualquiera de los chunks de este historial.
     * Ver App\Models\ImportConflict y ArticleImportHelper\ActualizarBBDD::persistir_conflictos().
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function conflicts() {
        return $this->hasMany(ImportConflict::class, 'import_history_id');
    }

    function articulos_creados() {
        return $this->belongsToMany(Article::class, 'article_creados_import_history');
    }

    function articulos_actualizados() {
        return $this->belongsToMany(Article::class, 'article_actualizados_import_history')
                    ->using(ArticleActualizadosImportHistory::class)
                    ->withPivot('updated_props');
    }
}
