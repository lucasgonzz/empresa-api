<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para guardar el estado y el resultado del análisis de un Excel que corre
 * en segundo plano.
 *
 * Con archivos de miles de filas, el análisis síncrono dentro de un request HTTP
 * supera el timeout. Esta tabla permite persistir la corrida en cola y consultarla
 * cuando esté lista.
 *
 * Los campos `payload`, `resultado` y `codigos_proveedor` se almacenan como JSON
 * y se cargan automáticamente como arrays PHP mediante los casts.
 */
class ExcelAnalysisRun extends Model
{
    /* Sin restricciones de asignación masiva: se inserta/actualiza vía array. */
    protected $guarded = [];

    /* Campos JSON que deben exponerse como arrays PHP. */
    protected $casts = [
        'payload' => 'array',
        'resultado' => 'array',
        'codigos_proveedor' => 'array',
    ];

    /**
     * Scope requerido por Controller::fullModel() para poder traer el modelo
     * con sus relaciones "completas". Este modelo no expone relaciones propias
     * por ahora, así que devuelve el query sin modificar (se completa si se
     * agregan relaciones).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
