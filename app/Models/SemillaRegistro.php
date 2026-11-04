<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un registro sembrado por el demo setup o por `semilla:datos` (misión prueba-experiencia-empresa,
 * 3/11/2026): `tabla` + `registro_id`, agrupado por `lote`. Solo lo escribe `SemillaRegistroHelper`.
 *
 * `owner_id` y no `user_id`: ver la migración `create_prueba_gratuita_tables`.
 */
class SemillaRegistro extends Model
{
    protected $table = 'semilla_registros';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
