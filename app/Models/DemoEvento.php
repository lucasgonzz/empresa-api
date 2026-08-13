<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento de la demo, persistido localmente antes de empujarse al admin (mision 50).
 *
 * `sincronizado_at` null = todavia pendiente. El push no borra filas: las marca, para
 * que quede el rastro de lo que hizo el lead aunque el admin no lo haya recibido nunca.
 */
class DemoEvento extends Model
{
    /**
     * @var string
     */
    protected $table = 'demo_eventos';

    /**
     * Sin restriccion de asignacion masiva: DemoEventoEmitter es el unico punto de
     * escritura y arma el array completo a mano.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'datos' => 'array',
        'ocurrido_at' => 'datetime',
        'sincronizado_at' => 'datetime',
        'intentos' => 'integer',
    ];

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
