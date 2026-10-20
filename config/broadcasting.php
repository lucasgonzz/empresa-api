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
    | Espacio de nombres de los canales (misión pusher-canales-por-sistema, 19/10/2026)
    |--------------------------------------------------------------------------
    |
    | Toda la flota —demos incluidas— emite a UNA sola app de Pusher, y los canales llevaban solo un
    | id (`global_notification.2600`). Dos sistemas con el mismo id de dueño se escuchaban entre sí: el
    | 19/10/2026 el aviso del escaneo de una factura de demo3 le apareció a Ferretotal, y un login en
    | demo3 podía expulsar al dueño de Ferretotal por el canal del candado de sesión.
    |
    | Desde esta misión cada canal sale con el sufijo `@{espacio}`. El espacio se CALCULA solo
    | (hmac de la base, el APP_ENV y el secret de Pusher: ver App\Broadcasting\EspacioDeCanales), así
    | que no hay nada que cargar en ningún `.env`.
    |
    | `espacio` es un override manual: si no está vacío, se usa ese valor (saneado a minúsculas y
    | dígitos, hasta 32). Sirve para los tests y para alinear a mano empresa-api y tienda-api de un
    | mismo cliente si algún día no dieran lo mismo (`php artisan canales:espacio` muestra el de cada
    | lado). 🔴 Si se carga en un lado, se carga EL MISMO en el otro: si no, el ERP y la tienda de ese
    | cliente dejan de oírse en los canales que cruzan.
    |
    */

    'espacio' => env('BROADCAST_ESPACIO', ''),

    /*
    | Modo de los canales que cruzan ERP ↔ tienda (`tienda-respuestas.`, `message.from_commerce.`,
    | `tienda-mensajes.`).
    |
    | Dentro del ERP no hay ventana: empresa-api y empresa-spa salen juntos. Pero la tienda se
    | actualiza aparte, así que una punta nueva tiene que seguir andando con la otra vieja, y al revés
    | (regla de Lucas del 14/8: compatibilidad hacia atrás entre proyectos).
    |
    | - true (TRANSICIÓN, el default): esos canales se emiten con Y sin espacio en el mismo `trigger`,
    |   y se autoriza también `private-tienda-mensajes.{owner}` sin sufijo. Viejo↔viejo, viejo↔nuevo y
    |   nuevo↔nuevo andan, y nadie recibe dos veces.
    | - false (ESTRICTO): solo con espacio. Se prende en las DOS mitades de un sistema a la vez (el
    |   `.env` de empresa-api y el de tienda-api del mismo cliente), cuando las dos ya están en la
    |   versión nueva. Con un solo lado en estricto, ese cliente deja de oír al otro.
    |
    | 🔴 Solo un falso EXPLÍCITO (`false`, `0`, `off`, `no`) pasa a estricto. Una línea vacía
    | (`BROADCAST_CANALES_VIEJOS_DE_TIENDA=`), un `null`/`(null)` escrito o un valor mal tipeado
    | quedan en transición: `env()` devuelve '' para la línea vacía y null para `null`, y
    | `filter_var()` da false para los dos, o sea que sin este cuidado un `.env` a medio editar
    | cortaba en silencio la comunicación con una tienda vieja. Es la misma trampa que documenta
    | `PUSHER_TIMEOUT` más abajo. Equivocarse para el lado de la transición es lo seguro: es
    | exactamente el comportamiento de antes de esta misión para esos canales. tienda-api usa la MISMA
    | expresión: las dos mitades de un sistema leen igual el mismo valor.
    |
    */

    'canales_viejos_de_tienda' => in_array(env('BROADCAST_CANALES_VIEJOS_DE_TIENDA'), [null, ''], true)
        || filter_var(env('BROADCAST_CANALES_VIEJOS_DE_TIENDA'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false,

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
