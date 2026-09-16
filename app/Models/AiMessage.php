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
 *
 * `canal` (misión asistente-por-whatsapp): 'sistema' (el panel del chat, el
 * default de la columna y lo que era todo hasta hoy) | 'whatsapp' (el dueño
 * escribiendo al número de ComercioCity, empujado por el admin). El canal
 * cambia el prompt y qué herramientas se declaran: en WhatsApp no hay tarjeta
 * que tocar, así que la confirmación es por texto (confirmar_carga_pendiente).
 * `whatsapp_message_id` guarda el wamid del entrante que lo originó, para poder
 * rastrear un mensaje puntual; la conversación NO se resuelve por ahí (la cita
 * la resuelve el admin, que es el único que conoce los wamid).
 */
class AiMessage extends Model
{
    /** Canal de un mensaje escrito desde el panel del chat del sistema (default de la columna). */
    const CANAL_SISTEMA = 'sistema';

    /** Canal de un mensaje que entró por WhatsApp, empujado por el admin. */
    const CANAL_WHATSAPP = 'whatsapp';

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

    /**
     * Fotos que trajo este mensaje, en el orden en que llegaron (misión asistente-por-whatsapp).
     * Solo las tienen los mensajes 'user' de canal 'whatsapp'.
     */
    public function imagenes()
    {
        return $this->hasMany(AiMessageImagen::class, 'ai_message_id')->orderBy('orden');
    }

    /**
     * true si el mensaje entró por WhatsApp.
     *
     * @return bool
     */
    public function es_de_whatsapp()
    {
        return (string) $this->canal === self::CANAL_WHATSAPP;
    }
}
