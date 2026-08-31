<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Canal de broadcast que deja salir el aviso en el momento, sin pasar por la cola, y que
 * ademas no puede voltear a quien lo emitio.
 *
 * ## El problema
 *
 * `BroadcastChannel` de Laravel no habla con Pusher: despacha un `BroadcastNotificationCreated`,
 * que `implements ShouldBroadcast` (no `Now`). Con `QUEUE_CONNECTION=database` eso lo guarda en
 * la tabla `jobs` y ahi se queda hasta que pase un worker.
 *
 * En el shared hosting el worker sale del scheduler (`Kernel::schedule()`), como
 * `queue:work --stop-when-empty` cada minuto con `withoutOverlapping(75)`. El peor caso es
 * sistematico: un emisor que corre DENTRO de un job encola su broadcast justo cuando el worker
 * acaba de vaciar la cola y esta por salir. Ese aviso espera al ciclo siguiente, y si el mutex
 * de 75 minutos sigue tomado, hasta 75 minutos. Medido el 31/8/2026 sobre el escaneo de facturas
 * de compra: el escaneo terminaba bien y el boton "Revisar escaneo" no se encendia nunca.
 *
 * No es una idea nueva del repo: `app/Events/ArticleEmbeddingsBatchGenerated.php:19-25` ya
 * documenta este mismo razonamiento para elegir `ShouldBroadcastNow`. Este canal es lo mismo,
 * pero para las notificaciones, que no pueden declararlo por su cuenta.
 *
 * ## 🔴 Por que no alcanza con `->onConnection('sync')` a secas
 *
 * Porque `SyncQueue::handleException()` **relanza** (`throw $e`, vendor linea 120), y no hay un
 * solo try/catch en toda la cadena hasta el emisor. Verificado el 31/8/2026 con un broadcaster
 * que tira: la excepcion sube por `Dispatcher` -> `BroadcastChannel` -> `NotificationSender` ->
 * `RoutesNotifications` y llega a `$user->notify()`.
 *
 * O sea que un 502 de Pusher daria un 500 al usuario sobre una operacion que YA se aplico en la
 * base --el "resultado parcial plausible" de `APRENDER_NO_PARCHEAR.md`-- y haria FALLAR al job
 * emisor, que se reintenta: `RunProviderOrderScanJob` volveria a correr el escaneo entero de la
 * factura contra la API de vision, y a pagarlo.
 *
 * **El aviso perdido es aceptable. El job re-corrido no.**
 *
 * ## Como bifurca
 *
 * Por DATO, no por clase: solo interviene si el mensaje es un `BroadcastMessage` que pidio
 * `connection === 'sync'`. Cualquier otra notificacion cae en el camino identico al de vendor.
 * Importa, porque este canal se bindea para las seis notificaciones del repo, y
 * `sendUpdateModelsNotification()` se llama desde mas de cien lugares del camino caliente.
 *
 * Y dentro de la rama `sync`, el momento del envio depende de donde estemos:
 *
 * - **En consola** (worker, scheduler, comandos): se despacha en el acto. No hay ninguna
 *   respuesta que se este demorando.
 * - **En un request**: se agenda en `app()->terminating()`, o sea DESPUES de mandada la
 *   respuesta. El usuario no espera ni un milisegundo de la llamada a Pusher. Es el mismo
 *   patron que ya usan `UserHelper::schedule_company_owner_context_updated_broadcast()` y
 *   `DemoEventoEmitter::programar_flush()`.
 *
 * `runningInConsole()` y no un flag declarado a mano: `ArticleImportHelper::error_notification()`
 * se llama desde `Jobs/ProcessArticleImport.php` **y** desde
 * `Helpers/import/article/InitExcelImport.php`. El mismo emisor, los dos contextos. Un flag por
 * archivo se desincroniza del codigo y falla mudo, que es justo el modo de falla que esta mision
 * vino a sacar.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class InstantBroadcastChannel extends BroadcastChannel
{
    /**
     * Manda la notificacion.
     *
     * El cuerpo replica el de `Illuminate\Notifications\Channels\BroadcastChannel::send()`
     * (vendor, lineas 38-52) en vez de delegar con `parent::send()`, y es a proposito: delegar
     * obligaria a llamar `getData()` dos veces --una para decidir la rama y otra adentro del
     * padre-- y `AddedModel::toBroadcast()` lee estado con `UserHelper::userId()`. A un metodo
     * que lee estado no se lo llama dos veces por comodidad.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     * @return \Illuminate\Notifications\Events\BroadcastNotificationCreated|null
     */
    public function send($notifiable, Notification $notification)
    {
        $message = $this->getData($notifiable, $notification);

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            $message instanceof BroadcastMessage ? $message->data : $message
        );

        if ($message instanceof BroadcastMessage) {
            $event->onConnection($message->connection)
                  ->onQueue($message->queue);
        }

        /**
         * Solo las que pidieron salir en el momento pasan por el camino nuevo. El resto se
         * despacha exactamente como lo hace vendor.
         */
        if (!($message instanceof BroadcastMessage) || $message->connection !== 'sync') {
            return $this->events->dispatch($event);
        }

        if ($this->es_consola()) {
            $this->despachar_sin_romper($event);

            return $event;
        }

        /**
         * En un request, el envio se agenda para despues de mandada la respuesta.
         *
         * 🔴 El try/catch va ADENTRO del closure y no es redundante con el de
         * `despachar_sin_romper()`: una excepcion que se escape de `terminating()` llega al
         * handler global con los headers ya mandados, y le pega el HTML del error al final del
         * JSON que el usuario ya recibio. Mismo motivo que documenta
         * `DemoEventoEmitter::programar_flush()`.
         */
        app()->terminating(function () use ($event) {
            $this->despachar_sin_romper($event);
        });

        return null;
    }

    /**
     * ¿Estamos en consola (worker, scheduler, comando) o atendiendo un request?
     *
     * Vive en su propio metodo por una razon concreta: en PHPUnit `runningInConsole()` es SIEMPRE
     * true, asi que sin este punto de apoyo la rama del `terminating()` --la que corre en
     * produccion en cada request web-- se quedaba sin una sola prueba. El test la ejerce pisando
     * este metodo.
     *
     * @return bool
     */
    protected function es_consola()
    {
        return app()->runningInConsole();
    }

    /**
     * Despacha el evento y se traga cualquier problema, dejando rastro.
     *
     * No es un catch mudo: se loguea con el canal, porque un catch sin log alrededor de un envio
     * produce un resultado plausible que nadie revisa nunca (`APRENDER_NO_PARCHEAR.md`). Lo que
     * NO puede pasar es que el problema suba: del otro lado hay un job que se reintentaria, o un
     * usuario que recibiria un 500 sobre una operacion ya aplicada.
     *
     * @param \Illuminate\Notifications\Events\BroadcastNotificationCreated $event
     * @return void
     */
    protected function despachar_sin_romper(BroadcastNotificationCreated $event)
    {
        try {
            $this->events->dispatch($event);
        } catch (\Throwable $e) {
            Log::warning('InstantBroadcastChannel: no se pudo emitir el aviso en tiempo real.', [
                'notificacion' => get_class($event->notification),
                'message'      => $e->getMessage(),
            ]);
        }
    }
}
