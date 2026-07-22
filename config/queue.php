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
