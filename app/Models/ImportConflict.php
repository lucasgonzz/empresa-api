<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de un Excel de importación que quedó reportada en el resultado de la
 * importación: identificador ambiguo, placeholder descartado, fila sin
 * identificador, o fila sobrescrita por otra posterior con el mismo
 * identificador ("última fila gana").
 *
 * `fila_ganadora` (prompt 03, grupo 265) solo se usa con `tipo = 'fila_sobrescrita'`:
 * `fila` es el número de la fila que PERDIÓ (la que quedó pisada), `fila_ganadora`
 * es la fila que GANÓ (la que prevalece). Para el resto de los tipos queda null.
 * A diferencia de los demás tipos, 'fila_sobrescrita' NO representa una fila que
 * no se pudo procesar (se resolvió bien) y por eso no suma a `conflicts_count`.
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
