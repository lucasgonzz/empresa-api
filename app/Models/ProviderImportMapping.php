<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de columnas que el usuario confirmó la última vez que importó un Excel
 * de un proveedor (misión importacion-excel-motor-rapido, 24/9/2026).
 *
 * Una fila por (user_id, model_name, provider_id). La escribe
 * ProviderImportMappingHelper::guardar_desde_recomendacion() cuando el usuario confirma el
 * paso 2 del modal de importación con IA, y la leen el análisis (para reconocer el formato y
 * recomendar lo que el usuario ya corrigió) y el cambio de proveedor en el paso 2.
 *
 * `column_mapping` es JSON y se expone como array: una entrada por columna no ignorada, con
 * {excel_column, excel_column_normalizada, excel_column_index, excel_column_letter,
 * system_property, corregida}.
 */
class ProviderImportMapping extends Model
{
    /* Sin restricciones de asignación masiva: se inserta/actualiza vía array desde el helper. */
    protected $guarded = [];

    protected $casts = [
        'column_mapping'      => 'array',
        'ultimo_uso_at'       => 'datetime',
        'columnas_corregidas' => 'integer',
        'veces_usado'         => 'integer',
    ];

    /**
     * Scope requerido por el repo para todo modelo nuevo: trae el proveedor al que pertenece
     * la configuración, que es lo único que hace falta para mostrarla ("la última vez que
     * importaste de {proveedor}").
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('provider');
    }

    /**
     * Proveedor de la configuración. Provider usa SoftDeletes: si el proveedor se borró, la
     * relación devuelve null y el helper descarta la configuración.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}
