<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Datos del negocio (remitente) para la cabecera de la etiqueta de envío.
 * Pertenece al usuario dueño (multi-tenant lógico).
 * Provincia y localidad son texto libre (sin FK).
 */
class SaleSenderInfo extends Model
{
    protected $guarded = [];

    /**
     * Scope requerido por fullModel(); sin relaciones eager por defecto.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
