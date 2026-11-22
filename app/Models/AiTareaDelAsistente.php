<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una tarea propia del asistente personal (misión agente-tareas-programadas, 21/11/2026): una instrucción PARA
 * el modelo que, a su hora, dispara un turno desatendido cuyo resultado le llega al dueño por WhatsApp.
 * Ver DespertadorDeTareasIaHelper (quién la despierta) y TareasDelAsistenteIaHelper (alta, cambios, límites).
 *
 * No es una tarea de la Agenda (`pendings`): aquella es un texto fijo para el dueño; esta es trabajo del asistente.
 */
class AiTareaDelAsistente extends Model
{
    /** Estados de la tarea. */
    const ESTADO_ACTIVA = 'activa';

    const ESTADO_PAUSADA = 'pausada';

    const ESTADO_CANCELADA = 'cancelada';

    const ESTADO_TERMINADA = 'terminada';

    /** Recurrencias. No hay nada más frecuente que diaria. */
    const RECURRENCIA_UNA_VEZ = 'una_vez';

    const RECURRENCIA_DIARIA = 'diaria';

    const RECURRENCIA_DIAS_SEMANA = 'dias_semana';

    const RECURRENCIA_SEMANAL = 'semanal';

    const RECURRENCIAS = [
        self::RECURRENCIA_UNA_VEZ,
        self::RECURRENCIA_DIARIA,
        self::RECURRENCIA_DIAS_SEMANA,
        self::RECURRENCIA_SEMANAL,
    ];

    /** Franjas del día que el dueño puede decir en vez de una hora. */
    const FRANJAS = ['manana', 'mediodia', 'tarde', 'noche'];

    protected $table = 'ai_tareas_del_asistente';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'             => 'integer',
        'auth_user_id'         => 'integer',
        'ai_conversation_id'   => 'integer',
        'ejecuciones'          => 'integer',
        'fallos_seguidos'      => 'integer',
        'fecha_inicio'         => 'date:Y-m-d',
        'fecha_fin'            => 'date:Y-m-d',
        'proxima_ejecucion_at' => 'datetime',
        'ultima_ejecucion_at'  => 'datetime',
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
