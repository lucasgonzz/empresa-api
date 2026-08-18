<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappBotConfig extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        // Si los chats nuevos nacen con la respuesta automática de IA prendida por defecto.
        'ai_enabled_default' => 'boolean',
        // Si se debe enviar automáticamente el comprobante PDF de la venta por el agente.
        'auto_send_sale_pdf' => 'boolean',
        // Segundos que espera el agente después de un mensaje entrante antes de generar la
        // respuesta (agrupa los mensajes que el cliente manda seguidos). 0 = responde al instante.
        'ai_reply_delay_seconds' => 'integer',
        // Segundos que la respuesta generada espera confirmación humana antes de enviarse
        // sola. 0 = se envía sin esperar a nadie.
        'ai_confirm_delay_seconds' => 'integer',
        // Si el agente interpreta las imágenes que manda el cliente. Se castea a booleano
        // porque MySQL devuelve 0/1 como string y este valor decide, con un `if`, si el turno
        // viaja a Anthropic como texto o como bloques con la imagen en base64: un '0' string
        // es truthy en PHP y prendería la visión (y su costo) en TODOS los negocios.
        'ai_vision_enabled' => 'boolean',
        // Si el dueño habilitó el botón de simular mensajes del cliente dentro de la
        // conversación (y en la bandeja). Mismo motivo que `ai_vision_enabled`: MySQL
        // devuelve 0/1 como string y un '0' string es truthy en PHP.
        'chat_simulation_enabled' => 'boolean',
    ];

    /**
     * Retorna la configuración activa para el user dado, o null si no existe.
     */
    public static function getForUser(int $user_id): ?self
    {
        return static::where('user_id', $user_id)
            ->where('is_active', true)
            ->first();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
