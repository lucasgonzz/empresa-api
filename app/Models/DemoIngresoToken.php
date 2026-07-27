<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de ingreso con sesión iniciada para leads que acceden a una demo
 * emitida desde admin-api (grupo 233). No es de un solo uso: vale durante
 * toda la ventana del turno, hasta `expires_at` o hasta que se revoque.
 */
class DemoIngresoToken extends Model
{
    /**
     * Sin restricción de asignación masiva: el helper es el único punto
     * de escritura y arma el array completo a mano.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de columnas de fecha.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel(). Se completa si en algún
     * momento este modelo se expone vía fullModel(); por ahora no tiene
     * relaciones para eager-loadear.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        // se completa al definir relaciones
        return $query;
    }
}
