<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "pusher", "ably", "redis", "log", "null"
    |
    */

    // Mismo criterio que admin-api: con PUSHER_* en .env, los eventos llegan a Echo.
    'default' => env('BROADCAST_DRIVER', 'pusher'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
                /**
                 * Techo de la llamada HTTP a Pusher, en segundos.
                 *
                 * Baja el default de 30 s a 5. Los 30 venian de dos lados a la vez: el cliente
                 * Guzzle que arma BroadcastServiceProvider::boot() (que usa esta misma clave si
                 * esta, y 30 si no) y el propio SDK de Pusher, que manda su `timeout` en cada
                 * POST. Esta clave baja los dos.
                 *
                 * Cinco segundos contra los 269 ms del peor caso medido el 31/8/2026 (handshake
                 * TLS en frio) y los 36 ms del caso normal: 18 veces de margen sobre lo peor que
                 * se midio. Importa porque desde esa fecha las notificaciones globales salen sin
                 * pasar por la cola, asi que un Pusher lento ya no es solo un aviso que tarda:
                 * es un worker clavado ese tiempo por cada notificacion.
                 *
                 * Sale de env() y no es un literal para poder bajarlo en la instancia de un
                 * cliente con red mala sin un release a las 40.
                 *
                 * 🔴 El `max(1, ...)` no es paranoia: `PUSHER_TIMEOUT=` vacio —o con cualquier
                 * cosa que no sea un numero— hace que `env()` devuelva '' y `(int) ''` de **0**,
                 * y 0 significa SIN LIMITE tanto en Guzzle como en el SDK de Pusher. O sea que
                 * un `.env` mal tipeado daba justo lo contrario del techo que esta clave existe
                 * para poner, y encima en el `.env.example` se invita a tocarla. Con el aviso ya
                 * fuera de la cola, un Pusher colgado clavaria un worker de FPM para siempre.
                 */
                'timeout' => max(1, (int) env('PUSHER_TIMEOUT', 5)),
                /**
                 * Pusher PHP 7+ usa Guzzle; curl_options no tienen efecto en ese cliente.
                 * - guzzle_verify: false evita cURL error 60 en Windows/WAMP sin CA bundle (solo desarrollo).
                 * - guzzle_ca_bundle: ruta a cacert.pem (https://curl.se/ca/cacert.pem) para verificar bien sin desactivar SSL.
                 */
                'guzzle_verify' => filter_var(
                    env('PUSHER_GUZZLE_VERIFY_SSL', env('APP_ENV') === 'production'),
                    FILTER_VALIDATE_BOOLEAN
                ),
                'guzzle_ca_bundle' => env('PUSHER_GUZZLE_CA_BUNDLE', ''),
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
