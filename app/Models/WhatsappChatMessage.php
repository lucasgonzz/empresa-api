<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa un mensaje individual (entrante o saliente) dentro de un `WhatsappChat`.
 * `source` distingue si lo mandó el cliente, un empleado a mano, el agente de IA, salió
 * como plantilla de Meta, o es un mensaje de sistema (ej: comprobante de venta automático).
 */
class WhatsappChatMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        // Momento en que la respuesta del agente se envía sola si nadie la confirma antes
        // (misión whatsapp-agente). Se castea a Carbon para que el front reciba una fecha
        // ISO y pueda armar el contador regresivo. Null cuando no hay auto-envío pendiente.
        'ai_auto_send_at' => 'datetime',
        // Contador que invalida el job de auto-envío pendiente (misión whatsapp-agente). Lo
        // escribe y lo lee `WhatsappAiAutoSendScheduler` por query builder crudo, no por el
        // modelo; el cast está para que si sale serializado en una respuesta o en un
        // broadcast salga como número y no como el string que devuelve el driver de MySQL.
        'ai_auto_send_token' => 'integer',
        // El mensaje lo inyectó el endpoint de simulación (`simulate-inbound`) o salió como
        // respuesta del agente a uno inyectado; el cliente nunca lo escribió ni lo recibió.
        // Se castea a booleano para que llegue al front y al broadcast como true/false y la
        // conversación lo pueda distinguir de un mensaje real, que es todo el punto de la
        // columna: `direction` y `source` son idénticos a los de un mensaje real a propósito.
        'is_simulated' => 'boolean',
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
        $query->with(['whatsapp_chat', 'sent_by_user']);
    }

    /**
     * Chat al que pertenece el mensaje.
     */
    public function whatsapp_chat()
    {
        return $this->belongsTo(WhatsappChat::class);
    }

    /**
     * Empleado que envió el mensaje manualmente (aplica a mensajes 'manual'/'plantilla').
     */
    public function sent_by_user()
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
