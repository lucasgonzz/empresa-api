<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\TiendaChatHelper;
use App\Models\Buyer;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageSend;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Le avisa al comprador de la tienda que el comercio le respondió a mano (misión
 * mensajes-tienda-online, 28/9/2026, contrato C2): el broadcast al canal público que la tienda ya
 * escucha (`message.from_commerce.{buyer_id}`) y, si corresponde, el mail "{comercio} respondió tu
 * mensaje". Las dos cosas las hace la `MessageSend` que ya existía, con `for_commerce = false`.
 *
 * Se despacha con `dispatchAfterResponse()` desde `TiendaChatController::enviar()`: corre en el
 * mismo proceso PHP del request, pero DESPUÉS de mandada la respuesta. Un SMTP lento o caído no
 * demora el envío del comercio ni un milisegundo.
 *
 * REGLA DE ORO (la misma de `BroadcastOrderCreated` de tienda-api): este Job NUNCA tira una
 * excepción hacia arriba. El mensaje YA está guardado y respondido; como corre en
 * `Application::terminate()`, una excepción que se escape sube hasta `public/index.php` sin filtro
 * y le pega el HTML del error al final de un JSON que el usuario ya recibió. Todo va en try/catch
 * de `\Throwable` (un problema de configuración de broadcasting o de mail no siempre llega como
 * `Exception`: una credencial ausente tira `TypeError`), y el log en su propio try/catch.
 *
 * Recibe escalares y no modelos: el mensaje se relee de la base acá adentro (con todo lo que
 * MySQL le puso por default: `from_buyer`, `read`, `type`), y no hay re-hidratación de
 * `SerializesModels` que pueda tirar `ModelNotFound` si algo se borró entre la respuesta y el
 * despacho.
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

    /** @var int Dueño del comercio: es el "comercio" del mail (nombre y enlace a la tienda). */
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

            if (is_null($message) || is_null($buyer)) {
                Log::warning('NotificarRespuestaAlComprador: el mensaje o el comprador ya no existen, no se avisa.', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                ]);
                return;
            }

            // El comercio va explícito y no sale de la sesión: esto corre después de la respuesta
            // y, si algún día corriera fuera de un request, `UserHelper::getFullModel()` caería en
            // `config('app.USER_ID')`, que en una base compartida es OTRO comercio.
            $commerce = User::find($this->owner_id);

            $buyer->notify(new MessageSend(
                $message,
                false,
                TiendaChatHelper::titulo_del_mail($commerce),
                null,
                $this->enviar_mail,
                $commerce
            ));
        } catch (\Throwable $e) {
            try {
                Log::error('NotificarRespuestaAlComprador: falló el aviso al comprador, el mensaje igual quedó guardado.', [
                    'message_id' => $this->message_id,
                    'buyer_id'   => $this->buyer_id,
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable $e_log) {
                // Si ni siquiera se puede loguear, lo único que importa es que muera acá adentro.
            }
        }
    }
}
