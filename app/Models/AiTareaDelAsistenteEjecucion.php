<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una corrida (o una omisión) de una tarea del asistente (misión agente-tareas-programadas, 21/11/2026). La
 * base garantiza con un unique (tarea_id, ocurrencia_at) que una ocurrencia se despierta UNA sola vez.
 */
class AiTareaDelAsistenteEjecucion extends Model
{
    /** Estados de la ejecución. */
    const ESTADO_EN_CURSO = 'en_curso';

    const ESTADO_LISTA = 'lista';

    const ESTADO_ENTREGADA = 'entregada';

    const ESTADO_NO_ENTREGADA = 'no_entregada';

    const ESTADO_FALLIDA = 'fallida';

    const ESTADO_OMITIDA = 'omitida';

    /** Motivos de una omisión o una falla. */
    const MOTIVO_VENCIDA = 'vencida';

    const MOTIVO_TOPE_DIARIO = 'tope_diario';

    const MOTIVO_TOPE_DEL_PLAN = 'tope_del_plan';

    const MOTIVO_ERROR = 'error';

    const MOTIVO_COLGADA = 'colgada';

    protected $table = 'ai_tareas_del_asistente_ejecuciones';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tarea_id'           => 'integer',
        'owner_id'           => 'integer',
        'ai_conversation_id' => 'integer',
        'ai_message_id'      => 'integer',
        'ocurrencia_at'      => 'datetime',
        'entregada_at'       => 'datetime',
    ];

    /**
     * Regla de los modelos nuevos: el scope existe aunque no cargue relaciones.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
