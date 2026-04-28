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
        // Recupera mensaje con relaciones completas para render inmediato en SPA (ticket para bandeja vía Pusher).
        $this->message = SupportMessage::where('id', $support_message_id)->withAll()->first();
        if (!is_null($this->message)) {
            self::prepareMessageAndTicketForClient($this->message);
        }
        // Define canal de soporte por usuario.
        $this->channel_name = 'support.user.' . $user_id;
    }

    /**
     * Carga ticket y contador de no leídos (mensajes admin sin read_at) para badge en empresa-spa.
     */
    private static function prepareMessageAndTicketForClient(SupportMessage $message): void
    {
        $message->loadMissing('ticket');
        if ($message->ticket) {
            $message->ticket->loadCount([
                'messages as unread_messages_count' => function ($sub) {
                    $sub->where('sender_type', 'admin')->whereNull('read_at');
                },
            ]);
        }
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
     * Payload enviado a Pusher: array (no modelo suelto) para que vayan anidadas
     * message.ticket.unread_messages_count (el badge de empresa-spa depende de ello).
     */
    public function broadcastWith()
    {
        if (is_null($this->message)) {
            return [
                'message' => null,
            ];
        }

        return [
            'message' => $this->message->toArray(),
        ];
    }
}

