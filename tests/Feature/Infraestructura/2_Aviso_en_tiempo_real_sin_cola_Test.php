<?php

namespace Tests\Feature\Infraestructura;

use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Notifications\ImportStatusNotification;
use App\Notifications\MessageSend;
use App\Notifications\UpdateModels;
use App\Notifications\Channels\InstantBroadcastChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Los avisos en tiempo real salen en el momento y no por la cola.
 *
 * ## El defecto que este test fija
 *
 * `BroadcastChannel` no habla con Pusher: despacha un `BroadcastNotificationCreated`, que
 * `implements ShouldBroadcast` (no `Now`). Con `QUEUE_CONNECTION=database` eso lo guarda en la
 * tabla `jobs` y ahi se queda hasta que pase un worker.
 *
 * En el shared hosting el worker sale del scheduler (`Kernel::schedule()`), como
 * `queue:work --stop-when-empty` cada minuto con `withoutOverlapping(75)`. Y el peor caso es
 * sistematico: un emisor que corre DENTRO de un job encola su broadcast justo cuando el worker
 * acaba de vaciar la cola y esta por salir. Ese aviso espera al ciclo siguiente, y si el mutex
 * de 75 minutos sigue tomado, hasta 75 minutos.
 *
 * Medido el 31/8/2026 sobre el escaneo de facturas de compra: el escaneo terminaba bien y el
 * boton "Revisar escaneo" no se encendia nunca con la pantalla abierta. En el VPS el sintoma es
 * mas leve --supervisor tiene un worker de larga vida-- pero existe igual: un import de cinco
 * minutos bloquea por cabecera todos los broadcasts que esten detras.
 *
 * ## Por que hacia falta un test nuevo y no alcanzaban los que habia
 *
 * 🔴 Los tests que ya cubrian estos avisos usan `Notification::fake()`, que reemplaza el
 * `ChannelManager` entero: la notificacion nunca llega al canal de broadcast y `toBroadcast()`
 * ni se llama. Verifican que se envio, no por donde salio. Por eso pasaban en verde con el
 * aviso sin llegar.
 *
 * ## Por que se mide el mensaje y no la tabla `jobs`
 *
 * 🔴 `phpunit.xml` declara `QUEUE_CONNECTION=sync`, asi que en el entorno de tests **nada se
 * encola nunca**: un assert contra `jobs` da 0 filas con arreglo y sin el, y pasaria en verde
 * midiendo nada. Forzar `config(['queue.default' => 'database'])` tampoco alcanza (probado el
 * 31/8/2026: el manager de colas ya resolvio la conexion). Lo que si es determinista es el dato
 * que decide el camino: `BroadcastManager::queue()` hace `->connection($event->connection)`, y
 * ese `connection` sale del `BroadcastMessage` via `BroadcastChannel::send()` (vendor, lineas
 * 46-49). Eso es lo que se asierta.
 *
 * DatabaseTransactions y no RefreshDatabase: la base de testing del slot esta sembrada de antes
 * y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Aviso_en_tiempo_real_sin_cola_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
    }

    /**
     * Payload minimo que el constructor de GlobalNotification exige.
     *
     * @param array $extra
     * @return array
     */
    protected function payload(array $extra = [])
    {
        return array_merge([
            'message_text'          => 'zz aviso de prueba',
            'color_variant'         => 'success',
            'functions_to_execute'  => [],
            'info_to_show'          => [],
            'owner_id'              => $this->user->id,
            'is_only_for_auth_user' => $this->user->id,
        ], $extra);
    }

    /**
     * 🔴 EL TEST DE LA MISION: el aviso pide salir por `sync`, no por la cola.
     *
     * @group broadcast
     * @return void
     */
    public function test_el_aviso_global_pide_salir_por_sync()
    {
        $mensaje = (new GlobalNotification($this->payload()))->toBroadcast($this->user);

        $this->assertSame(
            'sync',
            $mensaje->connection,
            'El aviso no pidio conexion sync, asi que se va a encolar. En el shared hosting eso '
                . 'son hasta 75 minutos de espera: el worker se agenda cada minuto con '
                . 'withoutOverlapping(75), y un emisor que corre dentro de un job encola su '
                . 'broadcast justo cuando el worker esta por salir por --stop-when-empty.'
        );
    }

    /**
     * Las OTRAS dos notificaciones que tenian el mismo defecto tambien salen en el momento.
     *
     * `ImportStatusNotification` es la que mueve la barra de progreso mientras el usuario importa,
     * y `MessageSend` avisa de un mensaje nuevo en un pedido o un chat. Las dos se encolaban.
     *
     * 🔴 `ImportStatusNotification` es el caso que mas vale fijar con un test: tenia TRES metodos
     * --`shouldBroadcastNow()`, `viaConnections()` y `viaQueues()`-- que intentaban exactamente
     * esto, los tres muertos, y un comentario afirmando que funcionaba. Alguien ya habia intentado
     * arreglar este mismo problema y quedo creyendo que estaba resuelto. Un assert es lo unico que
     * no se puede creer de memoria.
     *
     * @group broadcast
     * @return void
     */
    public function test_las_otras_notificaciones_de_tiempo_real_tambien_piden_sync()
    {
        $importacion = (new ImportStatusNotification(null, $this->user->id))
            ->toBroadcast($this->user);

        $this->assertSame(
            'sync',
            $importacion->connection,
            'ImportStatusNotification se sigue encolando: la barra de progreso de la importacion '
                . 'llega tarde o no llega.'
        );

        $mensaje = (new MessageSend('zz mensaje de prueba', false, 'zz', null, false))
            ->toBroadcast($this->user);

        $this->assertSame(
            'sync',
            $mensaje->connection,
            'MessageSend se sigue encolando: el aviso de un mensaje nuevo llega tarde, que para un '
                . 'mensaje es lo mismo que no llegar.'
        );
    }

    /**
     * El payload no cambia: es un contrato cruzado con empresa-spa.
     *
     * `empresa-spa/src/common-vue/mixins/broadcast.js` escucha
     * `Echo.channel('global_notification.'+owner_id).notification(...)`, y de ahi lee
     * `is_only_for_auth_user` y `notification_modal` para decidir que modal abre. Si esta
     * mision le hubiera cambiado una clave al mensaje, el aviso llegaria en el momento y el
     * front no sabria que hacer con el.
     *
     * @group broadcast
     * @return void
     */
    public function test_el_payload_del_aviso_no_cambio()
    {
        $mensaje = (new GlobalNotification($this->payload([
            'notification_modal' => 'provider_order_scan_ready',
        ])))->toBroadcast($this->user);

        $this->assertSame('zz aviso de prueba', $mensaje->data['message_text']);
        $this->assertSame($this->user->id, $mensaje->data['is_only_for_auth_user']);
        $this->assertSame('provider_order_scan_ready', $mensaje->data['notification_modal']);
    }

    /**
     * 🔴 La red de contencion: un Pusher caido NO puede voltear a quien emitio el aviso.
     *
     * Sin esto, `SyncQueue::handleException()` relanza (`throw $e`, vendor linea 120) y no hay un
     * solo try/catch en toda la cadena hasta el emisor. Verificado el 31/8/2026: con un
     * broadcaster que tira, la excepcion sube por `Dispatcher` -> `BroadcastChannel:51` ->
     * `NotificationSender:148` -> `RoutesNotifications:18` y llega a `$user->notify()`.
     *
     * O sea que un 502 de Pusher daria un 500 al usuario sobre una operacion que YA se aplico en
     * la base, y haria FALLAR al job emisor, que se reintenta: `RunProviderOrderScanJob` volveria
     * a correr el escaneo entero de la factura contra la API de vision, y a pagarlo.
     *
     * El aviso perdido es aceptable. El job re-corrido no.
     *
     * @group broadcast
     * @return void
     */
    public function test_si_el_broadcaster_falla_el_emisor_no_se_entera()
    {
        Broadcast::extend('zz_explota', function () {
            return new class {
                public function broadcast(array $channels, $event, array $payload = [])
                {
                    throw new \RuntimeException('zz pusher caido');
                }

                public function auth($request)
                {
                    return true;
                }

                public function validAuthenticationResponse($request, $result)
                {
                    return $result;
                }
            };
        });

        config([
            'broadcasting.connections.zz_explota' => ['driver' => 'zz_explota'],
            'broadcasting.default'                => 'zz_explota',
        ]);

        Log::spy();

        $this->user->notify(new GlobalNotification($this->payload()));

        /* Haber llegado hasta aca ES el test: la excepcion no subio hasta el emisor. */
        $this->assertTrue(true, 'El emisor sobrevivio a un broadcaster que tira.');

        Log::shouldHaveReceived('warning');
    }


    /**
     * Guarda del radio de explosion: una notificacion que NO pide sync sale como salia antes.
     *
     * El canal propio se bindea para TODAS las notificaciones del repo, y
     * `sendUpdateModelsNotification()` se llama desde mas de cien lugares del camino caliente de
     * crear y editar. Este caso asegura que la bifurcacion es por dato --el mensaje pidio `sync`
     * o no-- y no un cambio global.
     *
     * 🔴 Manda la notificacion POR EL CANAL y no se conforma con mirar el `BroadcastMessage`.
     * La primera version de este caso hacia lo segundo y no guardaba nada: `UpdateModels` no la
     * toco esta mision, asi que asertar sobre su mensaje pasa igual aunque el canal este roto.
     * Se comprueba invirtiendo la condicion de `InstantBroadcastChannel::send()`: con esta
     * version, el caso falla; con la anterior, seguia verde.
     *
     * @group broadcast
     * @return void
     */
    public function test_una_notificacion_que_no_pide_sync_sale_por_el_camino_de_siempre()
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $canal = app(BroadcastChannel::class);

        $this->assertInstanceOf(
            InstantBroadcastChannel::class,
            $canal,
            'El binding del provider no esta puesto: las notificaciones no pasan por el canal propio.'
        );

        $devuelto = $canal->send($this->user, new UpdateModels('article', true, $this->user->id));

        /**
         * 🔴 El retorno es lo que distingue POR QUE CAMINO salio, y es la unica forma de
         * detectarlo desde afuera: la rama de vendor devuelve lo que devuelve `dispatch()` --un
         * array de respuestas de listeners-- y la rama nueva devuelve el evento. Sin esta
         * asercion, invertir la condicion de `send()` mandaria a UpdateModels por el camino
         * nuevo y este caso seguiria verde, porque el evento se despacha igual y con la misma
         * conexion.
         */
        $this->assertNotInstanceOf(
            BroadcastNotificationCreated::class,
            $devuelto,
            'UpdateModels salio por el camino nuevo, cuando tenia que salir por el de siempre.'
        );

        Event::assertDispatched(BroadcastNotificationCreated::class, function ($evento) {
            /* Conexion en null = se encola, que es exactamente como salia antes de esta mision. */
            return is_null($evento->connection);
        });
    }

    /**
     * Desde un request, el aviso sale DESPUES de la respuesta, no durante.
     *
     * 🔴 Este caso existe porque en PHPUnit `runningInConsole()` es **true**, asi que todos los
     * demas casos ejercen la rama de consola. La rama que corre en produccion en cada request
     * web --la del `terminating()`-- se quedaba sin una sola prueba. Aca se la fuerza pisando
     * `es_consola()`, que existe en el canal justamente para dar este punto de apoyo.
     *
     * Lo que protege: que el usuario no pague la llamada a Pusher, y que el aviso salga igual
     * cuando el framework termina el request.
     *
     * @group broadcast
     * @return void
     */
    public function test_desde_un_request_el_aviso_sale_recien_al_terminar()
    {
        /* El fake va ANTES de construir el canal: el canal se queda con el dispatcher que le
         * pasan, asi que creado antes se llevaria el real y el fake no veria nada. */
        Event::fake([BroadcastNotificationCreated::class]);

        $canal = new class(app('events')) extends InstantBroadcastChannel {
            protected function es_consola()
            {
                return false;
            }
        };

        $devuelto = $canal->send($this->user, new GlobalNotification($this->payload()));

        $this->assertNull(
            $devuelto,
            'Desde un request el envio se agenda, asi que send() no devuelve el evento.'
        );

        /* Si esto falla, el aviso salio DURANTE el request y el usuario esta pagando la
         * llamada a Pusher, que es justo lo que el `terminating()` viene a evitar. */
        Event::assertNotDispatched(BroadcastNotificationCreated::class);

        app()->terminate();

        /* Y al terminar el request, sale. */
        Event::assertDispatched(BroadcastNotificationCreated::class);
    }
}
