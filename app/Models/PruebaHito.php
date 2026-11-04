<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El primer momento de cada hito de la prueba gratuita por dueño (misión prueba-experiencia-empresa,
 * 3/11/2026). `owner_id` + `tipo` es único: es lo que hace que cada tipo se emita UNA sola vez al admin
 * (C2) y lo que contesta `GET admin-sync/prueba/estado` (C3). Solo lo escribe `RegistrarHitoDePruebaJob`.
 *
 * `owner_id` y no `user_id`: ver la migración `create_prueba_gratuita_tables`.
 */
class PruebaHito extends Model
{
    protected $table = 'prueba_hitos';

    public $timestamps = false;

    protected $guarded = [];

    protected $dates = ['ocurrido_at'];

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
