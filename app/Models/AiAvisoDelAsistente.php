<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso del asistente para el dueño que sale POR WHATSAPP por el camino de avisos que consulta el
 * admin (misión agente-mejoras-uso-real, ronda de arreglos, 30/10/2026): hoy, el de un turno que no se
 * pudo terminar —se cortó con fotos sin cargar, o quedó colgado en `pendiente`—.
 *
 * Lo escribe AvisosDeTurnosIaHelper::dejar() (una fila por mensaje del asistente, nunca dos) y lo
 * sirve y lo sella AvisosDelAsistenteHelper (`turno_sin_terminar:{uuid}`). `owner_id` es el dueño (no
 * `user_id`: ver la migración 2026_10_30_090000).
 */
class AiAvisoDelAsistente extends Model
{
    /** El aviso de un turno que no terminó (cortado con fotos sin cargar, o colgado). */
    const TIPO_TURNO_SIN_TERMINAR = 'turno_sin_terminar';

    protected $table = 'ai_avisos_del_asistente';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'                => 'integer',
        'ai_conversation_id'      => 'integer',
        'ai_message_id'           => 'integer',
        'avisado_por_whatsapp_at' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel(). Sin relaciones que traer.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
