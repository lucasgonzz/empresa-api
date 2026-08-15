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
