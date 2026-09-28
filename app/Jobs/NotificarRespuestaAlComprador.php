<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\TiendaChatHelper;
use App\Models\Buyer;
use App\Models\Message;
use App\Models\User;
use App\Notifications\RespuestaDelComercioPorMail;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Le avisa al comprador de la tienda que el comercio le respondió a mano (misión
 * mensajes-tienda-online, 28/9/2026). Son dos avisos independientes:
 *
 * 1. EN VIVO (contrato C2'): el evento `RespuestaDelComercio` al canal público
 *    `tienda-respuestas.{owner_id}.{buyer_id}`, que escucha la tienda del comprador. Ya NO se usa
 *    `message.from_commerce.{buyer_id}` (el C2 original): ese canal se cruza entre comercios de la
 *    flota, ver el docblock de `RespuestaDelComercio`.
 * 2. POR MAIL, solo si el request decidió que corresponde (como mucho uno cada 30 minutos por
 *    conversación, `TiendaChatHelper::corresponde_mail()`) y el comprador tiene email. Sale por
 *    `RespuestaDelComercioPorMail`, que es SOLO mail.
 *
 * Cada aviso va en su propio try/catch: un SMTP caído no se lleva puesto el aviso en vivo, ni un
 * Pusher caído el mail.
 *
 * Se despacha con `dispatchAfterResponse()` desde `TiendaChatController::enviar()`: corre en el
 * mismo proceso PHP del request, pero DESPUÉS de mandada la respuesta. Un SMTP lento o un Pusher
 * colgado no demoran el envío del comercio ni un milisegundo.
 *
 * REGLA DE ORO (la misma de `BroadcastOrderCreated` de tienda-api): este Job NUNCA tira una
 * excepción hacia arriba. El mensaje YA está guardado y respondido; como corre en
 * `Application::terminate()`, una excepción que se escape sube hasta `public/index.php` sin filtro
 * y le pega el HTML del error al final de un JSON que el usuario ya recibió. Todo va en try/catch
 * de `\Throwable` (una credencial ausente tira `TypeError`, no `Exception`), y el log en su propio
 * try/catch.
 *
 * Recibe escalares y no modelos: el mensaje se relee de la base acá adentro (con todo lo que
 * MySQL le puso por default: `from_buyer`, `read`, `type`).
 *
 * 🔴 NO implementa `ShouldQueue`, a diferencia de `BroadcastOrderCreated`, y es a propósito: este
 * aviso tiene que salir en el momento. Si alguien lo despachara con `dispatch()` y fuera a la
 * cola, en el shared hosting esperaría al worker del scheduler (hasta 75 minutos, ver el docblock
 * de `InstantBroadcastChannel`), que para un mensaje es lo mismo que no llegar.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class NotificarRespuestaAlComprador
{
    use Dispatchable;

    /** @var int */
    public $message_id;

    /** @var int */
    public $buyer_id;

    /** @var int Dueño del comercio: define el canal y firma el mail. */
    public $owner_id;

    /** @var bool Lo decide el request con `TiendaChatHelper::corresponde_mail()`. */
    public $enviar_mail;

    /**
     * @param  int  $message_id
     * @param  int  $buyer_id
     * @param  int  $owner_id
     * @param  bool  $enviar_mail
     */
    public function __construct($message_id, $buyer_id, $owner_id, $enviar_mail)
    {
        $this->message_id  = (int) $message_id;
        $this->buyer_id    = (int) $buyer_id;
        $this->owner_id    = (int) $owner_id;
        $this->enviar_mail = (bool) $enviar_mail;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {
            $message = Message::find($this->message_id);
            $buyer = Buyer::find($this->buyer_id);
        } catch (\Throwable $e) {
            $this->loguear('no se pudo releer el mensaje o el comprador', $e);
            return;
        }

        if (is_null($message) || is_null($buyer)) {
            $this->loguear('el mensaje o el comprador ya no existen, no se avisa', null);
            return;
        }

        // 1. En vivo a la tienda (C2'). `emitir_respuesta_a_la_tienda()` ya ataja todo.
        TiendaChatHelper::emitir_respuesta_a_la_tienda($this->owner_id, $message);

        // 2. Por mail.
        if (!$this->enviar_mail) {
            return;
        }

        // Sin email no hay a quién mandarle. El canal mail de Laravel lo saltearía igual, pero
        // recién después de armar el mail entero (`MailChannel::send()` llama a `toMail()` antes de
        // mirar la dirección): cortarlo acá es más barato y deja explícita la regla.
        if (trim((string) $buyer->email) === '') {
            return;
        }

        try {
            // El comercio va explícito y no sale de la sesión (ver `RespuestaDelComercioPorMail`).
            $commerce = User::find($this->owner_id);

            $buyer->notify(new RespuestaDelComercioPorMail($message, $commerce));
        } catch (\Throwable $e) {
            $this->loguear('falló el mail al comprador, el mensaje igual quedó guardado', $e);
        }
    }

    /**
     * Deja rastro sin poder tirar: el catch que llama a esto no está cubierto por ningún try, y un
     * logger que falla (disco lleno, permisos de storage/logs) haría escapar la excepción del Job.
     *
     * @param  string  $que_paso
     * @param  \Throwable|null  $e
     * @return void
     */
    protected function loguear($que_paso, $e)
    {
        try {
            Log::warning('NotificarRespuestaAlComprador: '.$que_paso.'.', [
                'message_id' => $this->message_id,
                'buyer_id'   => $this->buyer_id,
                'error'      => $e ? $e->getMessage() : null,
            ]);
        } catch (\Throwable $e_log) {
            // Si ni siquiera se puede loguear, lo único que importa es que muera acá adentro.
        }
    }
}
