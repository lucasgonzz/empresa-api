<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje de una conversación del asistente de IA.
 *
 * rol: 'user' (lo escribió la persona) | 'assistant' (lo generó la IA).
 *
 * estado (relevante para rol 'assistant'):
 * - 'listo': tiene `contenido` definitivo.
 * - 'pendiente': el job de respuesta todavía no terminó; `contenido` es null
 *   y la SPA muestra el indicador de "pensando".
 * - 'error': la generación falló. `contenido` lleva un texto amigable que el
 *   usuario SÍ ve en la conversación y `error_mensaje` el detalle técnico.
 *
 * Los mensajes 'user' nacen directamente 'listo'.
 */
class AiMessage extends Model
{
    protected $guarded = [];

    /**
     * Conversación a la que pertenece el mensaje.
     */
    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
