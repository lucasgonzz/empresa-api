<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un archivo de la memoria de contexto del agente de un negocio (misión app-ia-y-memoria,
 * 29/10/2026).
 *
 * La memoria calca la estructura del conocimiento de ComercioCity: `contexto/contexto_maestro.md`
 * (siempre en el prompt), `contexto/INDICE.md` (generado, siempre en el prompt) y archivos por tema
 * que el agente abre a demanda con leer_contexto. Toda la lógica —plantilla, topes, revisiones,
 * índice— vive en AgenteContextoHelper: este modelo es solo la fila.
 *
 * 🔴 `user_id` es SIEMPRE el dueño. Toda lectura filtra por él: dos comercios que comparten la base
 * no se ven la memoria.
 */
class AgenteContextoArchivo extends Model
{
    use SoftDeletes;

    /** Nombre de la tabla: el plural automático de Eloquent daría `agente_contexto_archivos` igual, pero se fija. */
    protected $table = 'agente_contexto_archivos';

    protected $guarded = [];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'version' => 'integer',
    ];

    /**
     * Las versiones guardadas de este archivo, de la más vieja a la más nueva.
     */
    public function revisiones()
    {
        return $this->hasMany(AgenteContextoRevision::class, 'archivo_id')->orderBy('version');
    }

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
