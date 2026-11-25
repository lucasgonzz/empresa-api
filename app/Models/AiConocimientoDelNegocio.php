<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un tema del negocio que el dueño ya le contó al asistente (`sabido`) o que prefirió no contestar
 * (`omitido`) — misión onboarding-conversacional-whatsapp (23/11/2026).
 *
 * Toda la lógica (catálogo de los 32 temas, vigencia, cursor, ciclo de vida) vive en
 * ConocimientoDelNegocioIaHelper y OnboardingDelNegocioIaHelper: este modelo es sólo la fila.
 * `owner_id` es SIEMPRE el dueño; toda lectura filtra por él.
 */
class AiConocimientoDelNegocio extends Model
{
    const ESTADO_SABIDO = 'sabido';

    const ESTADO_OMITIDO = 'omitido';

    const FUENTE_AGENTE = 'agente';

    const FUENTE_CUESTIONARIO = 'cuestionario';

    const FUENTE_DUENO = 'dueno';

    protected $table = 'ai_conocimiento_del_negocio';

    protected $guarded = [];

    protected $casts = [
        'owner_id'           => 'integer',
        'ai_conversation_id' => 'integer',
        'marcado_at'         => 'datetime',
    ];

    public function scopeWithAll($query)
    {
    }
}
