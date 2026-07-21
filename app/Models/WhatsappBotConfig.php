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
