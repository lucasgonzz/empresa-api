<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica que un mensaje fue leído (actualización de read_at) a la ventana del emisor.
 */
class SupportMessageRead implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Mensaje con read_at.
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * Usuario empresa que debe recibir el "visto" (emisor del mensaje leído).
     *
     * @var int
     */
    public $listener_user_id = 0;

    /**
     * @param int $support_message_id
     */
    public function __construct(int $support_message_id)
    {
        $this->message = SupportMessage::where('id', $support_message_id)->withAll()->first();
        $sender_user_id = (int) (optional($this->message)->sender_user_id ?? 0);
        if ($sender_user_id < 1 && $this->message) {
            $sender_user_id = (int) (optional($this->message->ticket)->user_id ?? 0);
        }
        $this->listener_user_id = $sender_user_id;
    }

    /**
     * Canal del usuario en empresa cuyo propio mensaje se marcó como visto.
     */
    public function broadcastOn()
    {
        if ($this->listener_user_id < 1) {
            return [];
        }
        return [new Channel('support.user.' . $this->listener_user_id)];
    }

    public function broadcastAs()
    {
        return 'SupportMessageRead';
    }

    public function broadcastWith()
    {
        return [
            'message' => $this->message,
        ];
    }
}
