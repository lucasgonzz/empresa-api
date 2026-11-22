<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por cada simulación o ejecución del reset de datos de negocio (misión reset-datos-cliente).
 *
 * Tabla de sistema: el reset no la vacía nunca. `token_hash` está oculto: ni siquiera el hash sale en una respuesta.
 */
class ResetDatosNegocioRegistro extends Model
{
    /** @var string */
    protected $table = 'reset_datos_negocio_registros';

    /** @var array<int, string> Sin protección de asignación masiva: sólo la escribe ResetDatosNegocioHelper. */
    protected $guarded = [];

    /** @var array<int, string> Nunca se serializa el hash del token. */
    protected $hidden = ['token_hash'];

    /** @var array<string, string> */
    protected $casts = [
        'conteos' => 'array',
        'resultado' => 'array',
        'total_filas' => 'integer',
        'respaldo_bytes' => 'integer',
    ];

    /**
     * Convención del proyecto: todo modelo trae el scope aunque no cargue relaciones.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
