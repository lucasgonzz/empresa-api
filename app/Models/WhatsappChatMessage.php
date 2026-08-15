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
