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
}
