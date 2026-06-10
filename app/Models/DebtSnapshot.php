<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para los snapshots de deuda diarios.
 * Almacena el saldo total de clientes y proveedores al final de cada día
 * por usuario dueño (owner), separado por moneda.
 *
 * Campos principales:
 * - user_id: ID del dueño del negocio
 * - date: fecha del snapshot
 * - deuda_clientes, deuda_clientes_usd: saldo total de clientes por moneda
 * - deuda_proveedores, deuda_proveedores_usd: saldo total de proveedores por moneda
 */
class DebtSnapshot extends Model
{
    /**
     * Sin restricciones en asignación masiva; todos los campos son asignables.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Scope vacío requerido por Controller::fullModel() para invocar ->withAll() sin errores.
     * Actualmente no carga relaciones adicionales.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeWithAll($q)
    {
        return $q;
    }
}
