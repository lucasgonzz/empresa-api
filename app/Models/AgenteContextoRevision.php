<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una versión guardada de un archivo de la memoria del agente (misión app-ia-y-memoria,
 * 29/10/2026).
 *
 * Una fila por versión, con el contenido COMPLETO de esa versión, quién la escribió, por qué y desde
 * qué conversación. Nunca se edita: por eso no tiene `updated_at`.
 */
class AgenteContextoRevision extends Model
{
    /** Nombre de la tabla: el plural automático de Eloquent daría `agente_contexto_revisions`. */
    protected $table = 'agente_contexto_revisiones';

    protected $guarded = [];

    /** Solo `created_at`: una revisión no se modifica. */
    const UPDATED_AT = null;

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'archivo_id'         => 'integer',
        'version'            => 'integer',
        'ai_conversation_id' => 'integer',
    ];

    /**
     * El archivo al que pertenece (también si está borrado).
     */
    public function archivo()
    {
        return $this->belongsTo(AgenteContextoArchivo::class, 'archivo_id')->withTrashed();
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
