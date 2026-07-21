<?php

namespace App\Events;

use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa en tiempo real al módulo de WhatsApp de empresa-spa (grupo 137) que un chat
 * tiene novedades: mensaje entrante nuevo, respuesta de IA o manual enviada, o cambio
 * de estado de entrega de un mensaje ya enviado. Canal privado por dueño (`whatsapp.{owner_id}`)
 * para que solo el dueño y sus empleados puedan escucharlo (autorización en `routes/channels.php`).
 */
class WhatsappChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @var int Id del usuario dueño (owner) del chat; define el canal. */
    public $owner_id;

    /** @var WhatsappChat Chat afectado (se manda resumido en broadcastWith). */
    public $chat;

    /** @var WhatsappChatMessage|null Mensaje nuevo o actualizado; null si el evento es solo del chat. */
    public $message;

    /**
     * @param  int  $owner_id  Dueño de la cuenta (mismo criterio que `whatsapp_chats.user_id`).
     * @param  WhatsappChat  $chat  Chat que cambió.
     * @param  WhatsappChatMessage|null  $message  Mensaje nuevo o cuyo estado cambió, si aplica.
     */
    public function __construct(int $owner_id, WhatsappChat $chat, WhatsappChatMessage $message = null)
    {
        $this->owner_id = $owner_id;
        $this->chat = $chat;
        $this->message = $message;
    }

    /**
     * Canal privado por dueño: solo el owner y sus empleados pueden suscribirse
     * (ver autorización en `routes/channels.php`).
     *
     * @return PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('whatsapp.'.$this->owner_id);
    }

    /**
     * Nombre corto del evento para `Echo.private('whatsapp.'+ownerId).listen('.WhatsappChatUpdated', ...)`.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'WhatsappChatUpdated';
    }

    /**
     * Payload liviano: no se manda el chat completo con relaciones, solo lo que el
     * front necesita para actualizar la lista y la conversación abierta sin pedir
     * de nuevo el endpoint completo.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'chat_id' => $this->chat->id,
            'message' => $this->message,
            'chat' => [
                'id' => $this->chat->id,
                'unread_count' => $this->chat->unread_count,
                'last_message_at' => $this->chat->last_message_at,
            ],
        ];
    }
}
