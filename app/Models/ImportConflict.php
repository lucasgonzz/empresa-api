<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de un Excel de importación que no se pudo procesar de forma segura:
 * identificador ambiguo, placeholder descartado o fila sin identificador.
 *
 * Se persiste en bloque (insert masivo) al cerrar cada chunk de importación
 * desde ActualizarBBDD::persistir_conflictos(). Ver ProcessRow::get_conflictos().
 */
class ImportConflict extends Model
{
    /* Sin restricciones de asignación masiva: se inserta vía array desde ActualizarBBDD. */
    protected $guarded = [];

    /* article_ids viaja como JSON en la columna; se expone como array PHP. */
    protected $casts = [
        'article_ids' => 'array',
    ];

    /**
     * Scope requerido por Controller::fullModel() para poder traer el modelo
     * con sus relaciones "completas". Este modelo no expone relaciones propias
     * por ahora, así que queda vacío (se completa si se agregan relaciones).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Historial de importación al que pertenece este conflicto.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function import_history()
    {
        return $this->belongsTo(ImportHistory::class, 'import_history_id');
    }
}
