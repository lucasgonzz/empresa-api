<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarea de la Agenda (módulo Alertas → Agenda). Una fila es una tarea puntual o la REGLA de una
 * tarea recurrente: las ocurrencias de una recurrente no se guardan, se calculan al vuelo con
 * AgendaHelper::ocurrencia() a partir de `fecha_realizacion`, `unidad_frecuencia`,
 * `cantidad_frecuencia` y (opcional) `fecha_fin_recurrencia`.
 */
class Pending extends Model
{
    protected $guarded = [];

    /**
     * Las columnas de la migración de 2024 son `boolean()->nullable()`, que MySQL devuelve como
     * "0"/"1" en string o null. Sin el cast, la SPA vieja comparaba con `== 1` y andaba, pero el
     * contrato nuevo de la agenda promete `true`/`false` y en PHP `"0"` es truthy adentro de un
     * `if`: el cast cierra las dos cosas.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'es_recurrente' => 'boolean',
        'completado'    => 'boolean',
    ];

    function scopeWithAll($q) {
        $q->with('unidad_frecuencia', 'expense_concept');
    }

    function unidad_frecuencia() {
        return $this->belongsTo(UnidadFrecuencia::class);
    }

    function expense_concept() {
        return $this->belongsTo(ExpenseConcept::class);
    }

    function pending_completeds() {
        return $this->hasMany(PendingCompleted::class);
    }
}
