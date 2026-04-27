<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Mensaje completo a entregar al frontend.
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * Canal público por usuario para la sesión del cliente.
     *
     * @var string
     */
    public $channel_name;

    /**
     * Construye evento cargando el mensaje con sus relaciones.
     *
     * @param int $support_message_id
     * @param int $user_id
     */
    public function __construct(int $support_message_id, int $user_id)
    {
        // Recupera mensaje con relaciones completas para render inmediato en SPA.
        $this->message = SupportMessage::where('id', $support_message_id)->withAll()->first();
        // Define canal de soporte por usuario.
        $this->channel_name = 'support.user.' . $user_id;
    }

    /**
     * Define el canal de broadcast.
     */
    public function broadcastOn()
    {
        return new Channel($this->channel_name);
    }

    /**
     * Nombre explícito del evento para escuchar desde Echo.
     */
    public function broadcastAs()
    {
        return 'SupportMessageReceived';
    }

    /**
     * Payload enviado al frontend.
     */
    public function broadcastWith()
    {
        return [
            'message' => $this->message,
        ];
    }
}

