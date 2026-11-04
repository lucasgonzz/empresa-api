<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La configuración de la prueba gratuita de un dueño (misión prueba-experiencia-empresa, 3/11/2026).
 *
 * Una fila por dueño. Existe solo si el demo setup recibió el objeto `prueba` del admin (C1): su
 * presencia es lo que prende el "modo prueba" (modal de primer ingreso, botón de importar, borrado de lo
 * sembrado al primer import, hitos al admin). El vencimiento NO está acá: es `users.expired_at`.
 *
 * `owner_id` y no `user_id`: ver la migración `create_prueba_gratuita_tables`. Solo lo escribe
 * `PruebaHelper`.
 */
class PruebaConfig extends Model
{
    protected $table = 'prueba_config';

    protected $guarded = [];

    protected $dates = ['modal_visto_at', 'semilla_borrada_at'];

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
