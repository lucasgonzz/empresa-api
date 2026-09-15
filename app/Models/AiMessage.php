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
 *
 * `acciones_habilitadas` (misión asistente-ia-acciones): true si el assistant
 * se generó con las herramientas de carga (la SPA nueva manda `acciones: true`
 * en el POST). Sin el flag, la respuesta es de solo lectura como siempre.
 */
class AiMessage extends Model
{
    protected $guarded = [];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'acciones_habilitadas' => 'boolean',
    ];

    /**
     * Conversación a la que pertenece el mensaje.
     */
    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * Tarjetas de carga que propuso este mensaje, en el orden en que se crearon.
     * Qué se muestra de cada mensaje lo decide AccionesIaHelper::cargar_en_mensajes():
     * un mensaje que no está 'listo' nunca muestra tarjetas.
     */
    public function acciones()
    {
        return $this->hasMany(AiMessageAction::class, 'ai_message_id')->orderBy('id');
    }
}
