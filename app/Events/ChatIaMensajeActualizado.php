<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa en tiempo real que un mensaje del chat con el asistente de IA cambió
 * de estado (misión chat-ia-y-modulo-ia): la respuesta quedó lista, o quedó en
 * error. Lo dispara ResponderMensajeChatIaJob SIEMPRE al terminar — también
 * cuando falla — para que la SPA corte la espera sin depender del polling.
 *
 * Canal PRIVADO por PERSONA (`chat.user.{auth_user_id}`), no por cuenta: las
 * conversaciones son de cada persona (pueden traer saldos de clientes) y un
 * empleado no escucha las del dueño ni al revés. La autorización vive en
 * `routes/channels.php`, sin la rama de owner_id que sí tiene el canal de
 * WhatsApp.
 *
 * 🔴 Payload liviano a propósito (D8 del plan): tres ids/strings y nada más.
 * El texto de la respuesta JAMÁS viaja por Pusher (límite de 10 KB, y sobre
 * todo: es data sensible que se pide por REST autenticado con el evento como
 * aviso). No le agregues `contenido` a broadcastWith().
 *
 * En la SPA se escucha con `.listen('.ChatIaMensajeActualizado', ...)` — con
 * punto inicial, porque es un Event con broadcastAs, no una Notification.
 */
class ChatIaMensajeActualizado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @var int Id de la PERSONA dueña de la conversación; define el canal. */
    public $auth_user_id;

    /** @var int Id de la conversación a la que pertenece el mensaje. */
    public $ai_conversation_id;

    /** @var int Id del mensaje que cambió (el assistant generado por el job). */
    public $ai_message_id;

    /** @var string Estado nuevo del mensaje: 'listo' | 'error'. */
    public $estado;

    /**
     * @param  int  $auth_user_id  Persona dueña de la conversación (ai_conversations.auth_user_id).
     * @param  int  $ai_conversation_id  Conversación del mensaje.
     * @param  int  $ai_message_id  Mensaje cuyo estado cambió.
     * @param  string  $estado  Estado nuevo ('listo' | 'error').
     */
    public function __construct(int $auth_user_id, int $ai_conversation_id, int $ai_message_id, string $estado)
    {
        $this->auth_user_id = $auth_user_id;
        $this->ai_conversation_id = $ai_conversation_id;
        $this->ai_message_id = $ai_message_id;
        $this->estado = $estado;
    }

    /**
     * Canal privado por persona: solo esa persona puede suscribirse
     * (ver autorización en `routes/channels.php`).
     *
     * @return PrivateChannel
     */
    public function broadcastOn()
    {
        return new PrivateChannel('chat.user.'.$this->auth_user_id);
    }

    /**
     * Nombre corto para `Echo.private('chat.user.'+userId).listen('.ChatIaMensajeActualizado', ...)`.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'ChatIaMensajeActualizado';
    }

    /**
     * Payload mínimo: el evento AVISA, la SPA busca el mensaje por REST
     * (GET ai-conversations/{id}/messages/{message_id}). Nada del contenido
     * de la conversación viaja por acá.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'ai_conversation_id' => $this->ai_conversation_id,
            'ai_message_id'      => $this->ai_message_id,
            'estado'             => $this->estado,
        ];
    }
}
