<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Worker de cola desde el scheduler
    |--------------------------------------------------------------------------
    |
    | App\Console\Kernel programa `queue:work --stop-when-empty` cada minuto. En el
    | shared hosting es lo ÚNICO que procesa la cola (hay un solo cron por instancia,
    | el de schedule:run). En el VPS es redundante: supervisor ya tiene un worker de
    | larga vida por instancia, y cada arranque de ese artisan son 0,3-0,5 s de CPU
    | por instancia y por minuto. Con QUEUE_SCHEDULER_WORKER=false el scheduler no
    | lo programa. Default true: ninguna instancia que no declare la variable cambia
    | de comportamiento. filter_var y no (bool): "false" y "0" tienen que apagarlo.
    |
    */

    'scheduler_worker' => filter_var(env('QUEUE_SCHEDULER_WORKER', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Worker propio del turno del asistente (shared hosting)
    |--------------------------------------------------------------------------
    |
    | Misión asistente-whatsapp-velocidad. En el shared el turno del asistente esperaba al cron
    | del minuto (~28 s de mediana). Con esto encendido (y QUEUE_SCHEDULER_WORKER también), el turno
    | se despacha a la cola `<QUEUE_NAME>_asistente` y el scheduler deja un worker vivo sobre ella.
    | Con QUEUE_ASISTENTE_WORKER=false el turno vuelve a `default` y lo atiende el cron de siempre:
    | es el freno por cliente si Hostinger se queja, sin desplegar. En el VPS sólo hace algo si además
    | está prendido `asistente_worker_vps` (abajo).
    | Default true. filter_var y no (bool): "false" y "0" tienen que apagarlo.
    |
    */

    'asistente_worker' => filter_var(env('QUEUE_ASISTENTE_WORKER', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Worker propio del turno del asistente en el VPS (apagado por defecto)
    |--------------------------------------------------------------------------
    |
    | Misión asistente-cola-vps (S4 de colas-prevencion-fase2). En el VPS el turno del asistente va a
    | `default`, que consume un solo worker de supervisor con --timeout=3600: un export de hasta 1 h lo
    | deja esperando. Con QUEUE_ASISTENTE_WORKER_VPS=true (y QUEUE_ASISTENTE_WORKER, que por defecto es
    | true) el turno va a `<cola>_asistente` y el scheduler del cliente lanza el mismo worker por minuto
    | que el shared (sin mutex, --max-time=65). En el VPS NO se mira `scheduler_worker` (vale false a
    | propósito) ni la caché. Default false: ninguna instancia cambia sin pedirlo.
    |
    | Para volver atrás, en este orden: 1) QUEUE_ASISTENTE_WORKER=false (los turnos nuevos vuelven a
    | `default` y el worker sigue hasta vaciar `_asistente`); 2) con `_asistente` en 0, quitar esta
    | variable. Al revés quedarían turnos huérfanos. filter_var y no (bool): "false" y "0" la apagan.
    |
    */

    'asistente_worker_vps' => filter_var(env('QUEUE_ASISTENTE_WORKER_VPS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Cola propia de los embeddings de artículos (shared y VPS)
    |--------------------------------------------------------------------------
    |
    | Misión embeddings-lote-cola. Los embeddings (los lotes que arma el comando, el job de un
    | artículo de los observers y el finalizador de la tanda) iban a `default` y, con miles de
    | artículos, la tapaban: las actualizaciones masivas, las imágenes y las exportaciones
    | esperaban detrás. Con esto prendido todo eso va a `<cola>_embeddings` (con QUEUE_NAME sin
    | setear: `default_embeddings`) y lo atiende un worker que lanza el scheduler de la propia
    | instancia cada minuto sólo si hay jobs pendientes (App\Console\Kernel). A diferencia de la
    | cola del asistente en el VPS, nace PRENDIDA y no depende de supervisor: en el VPS cada
    | cliente ya tiene el programa `_schedule` que corre `schedule:run`, así que no hay que tocar
    | ninguna configuración de servidor.
    |
    | Para volver atrás, en este orden: 1) QUEUE_EMBEDDINGS_WORKER=false (los jobs nuevos vuelven a
    | `default`; el worker sigue programado mientras queden jobs en `_embeddings`); 2) recién con
    | `_embeddings` vacía, bajar de versión. Al revés quedarían jobs huérfanos.
    | filter_var y no (bool): "false" y "0" tienen que apagarla.
    |
    */

    'embeddings_worker' => filter_var(env('QUEUE_EMBEDDINGS_WORKER', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            /*
             * Aísla la cola por instalación: instalaciones que comparten la misma base
             * de datos (Fénix, Galván, etc.) definen QUEUE_NAME en su .env con el nombre
             * de su negocio para que su worker solo tome/encole sus propios jobs y no
             * los de otra instalación. Sin QUEUE_NAME seteada, el comportamiento sigue
             * siendo exactamente el mismo que antes: cola 'default'.
             */
            'queue' => env('QUEUE_NAME', 'default'),
            // retry_after debe superar el timeout máximo de cualquier job en esta cola.
            // Jobs pesados (exportaciones, importaciones, actualizaciones masivas) declaran timeout = 3600 (60 min).
            // Configurar en 4200 segundos (70 min) asegura que Laravel no marque un job como "abandonado"
            // si todavía está en ejecución.
            'retry_after' => 4200,
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            /*
             * Mismo criterio de aislamiento que la conexión 'database': prioriza
             * QUEUE_NAME. Si no está seteada, cae a REDIS_QUEUE como fallback legacy
             * para instalaciones que ya la tuvieran configurada específicamente para
             * redis, y si tampoco existe, usa 'default' como siempre.
             */
            'queue' => env('QUEUE_NAME', env('REDIS_QUEUE', 'default')),
            'retry_after' => 9000,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
