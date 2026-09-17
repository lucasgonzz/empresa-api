<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa una conversación de WhatsApp entre la empresa y un teléfono (un chat por
 * teléfono por empresa). `client_id` puede ser null cuando el teléfono todavía no está
 * cargado como cliente del negocio.
 *
 * `last_inbound_at` es la única fuente de verdad de la ventana de 24 h de Meta: fuera de
 * esa ventana solo se puede responder con plantillas aprobadas (lo consumen los
 * controllers/services de los Prompts 02, 04 y 05 vía `is_within_service_window()`).
 */
class WhatsappChat extends Model
{
    protected $guarded = [];

    protected $casts = [
        // Respuesta automática de IA prendida/apagada para este chat puntual.
        'ai_enabled' => 'boolean',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        // Contador del debounce del agente (misión whatsapp-agente). Lo escribe y lo lee
        // `WhatsappAgentScheduler` por query builder crudo, no por el modelo; el cast está
        // para que si alguna vez sale serializado en una respuesta o en un broadcast salga
        // como número y no como el string que devuelve el driver de MySQL.
        'ai_schedule_token' => 'integer',
    ];

    /**
     * Relaciones a precargar cuando el controller pide el modelo completo vía
     * `Controller::fullModel()`. Sin este scope, `fullModel()` rompe.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['client', 'user']);
    }

    /**
     * Empresa (dueño) a la que pertenece el chat.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cliente del negocio vinculado al chat (puede ser null).
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Mensajes del chat, ordenados cronológicamente (el más viejo primero).
     */
    public function messages()
    {
        return $this->hasMany(WhatsappChatMessage::class)->orderBy('created_at');
    }

    /**
     * Indica si el chat todavía está dentro de la ventana de 24 h que impone Meta para
     * poder responder libremente (sin usar una plantilla aprobada). Se calcula a partir
     * de `last_inbound_at`: si nunca hubo un mensaje entrante, o pasaron 24 h o más desde
     * el último, el chat queda fuera de ventana.
     *
     * @return bool
     */
    public function is_within_service_window()
    {
        if (is_null($this->last_inbound_at)) {
            return false;
        }

        return $this->last_inbound_at->diffInHours(now()) < 24;
    }

    /**
     * Indica si el chat tiene una respuesta de la IA generada y todavía sin aprobar
     * (`ai_status = 'a_confirmar'`, ver el docblock de la máquina de estados en
     * `WhatsappChatHelper`). Es la tarjeta amarilla del tablero (misión
     * whatsapp-tablero-clientes) y el resaltado amarillo de la fila en la bandeja.
     *
     * @return bool
     */
    public function is_esperando_aprobacion()
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $this->id)
            ->where('ai_status', 'a_confirmar')
            ->exists();
    }

    /**
     * Indica si el último mensaje "real" del chat (excluyendo los `a_confirmar`, que
     * todavía no se dijeron) es entrante: o sea, si el cliente escribió y nadie —ni un
     * humano ni la IA— le contestó todavía. Es la tarjeta roja del tablero (misión
     * whatsapp-tablero-clientes) y el resaltado rojo de la fila en la bandeja.
     *
     * 🔴 ES EL MISMO CRITERIO, A PROPÓSITO REPLICADO Y NO IMPORTADO, que
     * `GenerateWhatsappAiReplyJob::has_unanswered_inbound()` (el que usa el agente
     * automático para decidir si tiene que generar una respuesta). No se centralizó ahí
     * porque ese Job dispara mensajes reales a clientes reales y tocarlo por una razón
     * puramente visual es más riesgo del que vale la pena — si el criterio de "sin
     * responder" cambia alguna vez, hay que cambiarlo en los dos lugares.
     *
     * @return bool
     */
    public function is_sin_responder()
    {
        $last_real_message = WhatsappChatMessage::where('whatsapp_chat_id', $this->id)
            ->where(function ($query) {
                $query->whereNull('ai_status')->orWhere('ai_status', '!=', 'a_confirmar');
            })
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (is_null($last_real_message)) {
            return false;
        }

        return $last_real_message->direction === 'in';
    }

    /**
     * Estado del chat para el tablero y el color de la fila en la bandeja (misión
     * whatsapp-tablero-clientes): 'esperando_aprobacion' | 'sin_responder' | null.
     *
     * 🔴 LA PRIORIDAD NO ES ARBITRARIA. Un chat con un mensaje `a_confirmar` pendiente
     * TAMBIÉN cumple el criterio crudo de `is_sin_responder()`: ese método excluye a
     * propósito los `a_confirmar` al buscar el último mensaje "real", así que ese último
     * real sigue siendo el entrante del cliente. Sin esta precedencia, el mismo chat
     * contaría en las dos tarjetas del tablero a la vez y tendría dos colores. Que "hay
     * algo generado esperando que lo aprueben" es un estado más avanzado que "todavía no
     * se generó nada", así que gana el amarillo.
     *
     * @return string|null
     */
    public function estado_pendiente()
    {
        if ($this->is_esperando_aprobacion()) {
            return 'esperando_aprobacion';
        }

        if ($this->is_sin_responder()) {
            return 'sin_responder';
        }

        return null;
    }
}
