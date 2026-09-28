<?php

namespace App\Notifications;

use App\Http\Controllers\Helpers\TiendaChatHelper;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El mail "{comercio} respondió tu mensaje" que recibe el comprador de la tienda cuando el comercio
 * le contesta a mano desde el submódulo "Mensajes" (misión mensajes-tienda-online, 28/9/2026,
 * decisión 2 de Lucas: como mucho uno cada 30 minutos por conversación).
 *
 * SOLO mail. El aviso en vivo a la tienda va por el evento `RespuestaDelComercio` (contrato C2'), y
 * a propósito NO por `MessageSend`: su `via()` suma `broadcast` al canal público
 * `message.from_commerce.{buyer_id}`, que se cruza entre comercios de la flota (ver el docblock de
 * `RespuestaDelComercio`). Una notificación aparte deja a `MessageSend` exactamente como estaba para
 * sus otros usos, sin sumarle un flag más a un constructor que ya tiene cinco posicionales.
 *
 * Usa la misma vista que `MessageSend` (`emails.message-send`), con el comercio explícito: esto se
 * manda después de la respuesta, y ahí `UserHelper::getFullModel()` no es de fiar (fuera de un
 * request cae en `config('app.USER_ID')`, que en una base compartida es OTRO comercio).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class RespuestaDelComercioPorMail extends Notification
{
    /** @var \App\Models\Message La respuesta del comercio. */
    protected $message;

    /** @var \App\Models\User|null El dueño del comercio, que firma el mail. */
    protected $commerce;

    /**
     * @param  \App\Models\Message  $message
     * @param  \App\Models\User|null  $commerce
     */
    public function __construct($message, $commerce)
    {
        $this->message = $message;
        $this->commerce = $commerce;
    }

    /**
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
                    ->from('contacto@comerciocity.com', 'comerciocity.com')
                    ->subject(TiendaChatHelper::titulo_del_mail($this->commerce))
                    ->markdown('emails.message-send', [
                        'commerce' => $this->commerce,
                        'message'  => $this->message->text,
                        'logo_url' => 'https://api.comerciocity.com/public/storage/logo.png',
                        // 🔴 La vista es compartida con `MantenimientoMail`, que le pasa una lista
                        // de `messages`, y la recorre SIEMPRE con `@foreach($messages ...)`. Sin esta
                        // clave el mail no se puede armar: "Undefined variable: messages".
                        'messages' => [],
                    ]);
    }
}
