<?php

namespace Tests\Feature\Broadcast;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastManager;
use Psr\Http\Message\RequestInterface;
use Pusher\Pusher;

/**
 * Arma el broadcaster de Pusher DE VERDAD (el que registra `BroadcastServiceProvider`) sin salir a la
 * red, para los tests de la misión pusher-canales-por-sistema.
 *
 * ## 🔴 Por qué hace falta
 *
 * En testing `BROADCAST_DRIVER=log` (lo fuerza `phpunit.xml`), y `routes/channels.php` registra los
 * callbacks en el driver por defecto AL BOOTEAR. O sea: sin esto, cualquier evento sale por el
 * `LogBroadcaster` (que no pasa por `formatChannels()`) y cualquier `POST broadcasting/auth` da 200
 * para cualquiera (el `auth()` del log no hace nada). Ningún test sobre ese driver probaría el espacio.
 *
 * Lo que hace `usar_pusher_de_prueba()`:
 *
 * 1. Pone `pusher` como driver por defecto, con credenciales FALSAS en config.
 * 2. Olvida los drivers ya resueltos en el `BroadcastManager`, así el próximo pedido construye el
 *    broadcaster por el `Broadcast::extend('pusher', ...)` del provider: el mismo camino que en
 *    producción, y por lo tanto `PusherBroadcasterConEspacio`.
 * 3. Le cambia el SDK de Pusher por uno con un cliente Guzzle que NO sale a la red: un handler propio
 *    anota cada pedido y contesta 200 `{}`. El SDK es el real, así que valida los nombres de canal
 *    (que el `@` del sufijo sea aceptado) y arma el cuerpo del POST igual que en producción.
 * 4. Vuelve a requerir `routes/channels.php`, para que los callbacks de autorización queden
 *    registrados en ESTA instancia.
 *
 * El handler propio y no un `MockHandler` de Guzzle porque ese tira `OutOfBoundsException` cuando se le
 * acaba la cola, y un evento que de golpe emite dos veces haría fallar el test por un motivo que no es
 * el que se prueba.
 *
 * PHP 7.4.
 */
trait PusherDePrueba
{
    /**
     * Pedidos HTTP que el SDK de Pusher intentó hacer (cada `trigger` es un POST a `/events`).
     *
     * @var RequestInterface[]
     */
    protected $pedidos_a_pusher = [];

    /**
     * Key falsa de la app de Pusher de prueba.
     *
     * @var string
     */
    protected $key_de_prueba = 'clave-publica-de-prueba';

    /**
     * Secret falso de la app de Pusher de prueba. Es el mismo literal de los vectores del plan, a
     * propósito: así el espacio de estos tests sale de `calcular(base, 'testing', 'secreto-de-prueba')`.
     *
     * @var string
     */
    protected $secret_de_prueba = 'secreto-de-prueba';

    /**
     * Deja a `pusher` como broadcaster por defecto, construido por el provider, con un SDK que no sale
     * a la red y con los callbacks de `routes/channels.php` registrados en él.
     *
     * @return \Illuminate\Contracts\Broadcasting\Broadcaster El broadcaster que quedó por defecto.
     */
    protected function usar_pusher_de_prueba()
    {
        config([
            'broadcasting.default'            => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver'  => 'pusher',
                'key'     => $this->key_de_prueba,
                'secret'  => $this->secret_de_prueba,
                'app_id'  => '123456',
                'options' => [
                    'cluster' => 'sa1',
                    'useTLS'  => true,
                    'timeout' => 1,
                ],
            ],
        ]);

        /** El manager es singleton: hay que olvidar el driver `log` que ya resolvió el arranque. */
        $manager = app(BroadcastManager::class);
        $manager->forgetDrivers();

        /** Construido por el `Broadcast::extend('pusher', ...)` de `BroadcastServiceProvider`. */
        $broadcaster = $manager->driver('pusher');

        $broadcaster->setPusher(new Pusher(
            $this->key_de_prueba,
            $this->secret_de_prueba,
            '123456',
            ['cluster' => 'sa1', 'useTLS' => true, 'timeout' => 1],
            $this->cliente_http_que_no_sale()
        ));

        // Los callbacks de autorización, sobre ESTA instancia (ver el docblock del trait).
        require base_path('routes/channels.php');

        return $broadcaster;
    }

    /**
     * Cliente Guzzle cuyo handler anota el pedido y contesta 200 `{}` sin tocar la red.
     *
     * @return Client
     */
    protected function cliente_http_que_no_sale()
    {
        /** Handler que reemplaza al de cURL. */
        $handler = function (RequestInterface $request, array $options) {
            $this->pedidos_a_pusher[] = $request;

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        };

        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    /**
     * Los canales de cada `trigger` que se mandó a Pusher, en orden: un array de nombres por evento.
     *
     * El SDK manda `channels` (array) en el cuerpo JSON del POST a `/events`.
     *
     * @return array<int, string[]>
     */
    protected function canales_emitidos()
    {
        /** Un elemento por trigger. */
        $por_trigger = [];

        foreach ($this->pedidos_a_pusher as $pedido) {
            if (substr($pedido->getUri()->getPath(), -strlen('/events')) !== '/events') {
                continue;
            }

            /** Cuerpo del trigger. */
            $cuerpo = json_decode((string) $pedido->getBody(), true);

            if (isset($cuerpo['channels'])) {
                $por_trigger[] = $cuerpo['channels'];
            } elseif (isset($cuerpo['channel'])) {
                $por_trigger[] = [$cuerpo['channel']];
            }
        }

        return $por_trigger;
    }
}
