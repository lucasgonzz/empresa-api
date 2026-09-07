<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            // Motor de tabla que se le agrega a cada CREATE TABLE (MySqlGrammar::compileCreate).
            // En produccion queda en null: sin la variable en el .env, el CREATE TABLE sale igual
            // que siempre y manda el default del servidor. En testing se setea DB_ENGINE=InnoDB
            // para que `migrate:fresh --env=testing` cree todo en InnoDB aunque el default del
            // MySQL local sea MyISAM: sin InnoDB, DatabaseTransactions no revierte nada (BEGIN y
            // ROLLBACK son no-ops sobre MyISAM) y los tests se contaminan entre si.
            'engine' => env('DB_ENGINE', null),
            // Conexiones persistentes (PDO::ATTR_PERSISTENT), apagadas salvo que el .env las prenda.
            //
            // El 7/9/2026 los ~31 clientes del shared hosting de Hostinger (cuenta u767360347) se
            // cayeron todos juntos con "SQLSTATE[HY000] [2002] Operation not permitted", entre 3 y
            // 38 veces por minuto. Lo que el hosting limita no son las conexiones abiertas sino las
            // conexiones NUEVAS por segundo, y el tope es de la CUENTA entera, no de cada sitio:
            // midiendolo en el propio servidor, en una rafaga entran ~18 y a partir de ahi se
            // rechazan TODAS durante varios segundos, para los 31 clientes a la vez. Laravel abre
            // una conexion nueva por request y no la reutiliza, asi que con pocos usuarios
            // trabajando al mismo tiempo ya se llega al tope. Con la conexion persistente PHP reusa
            // la que el proceso de FPM ya tiene abierta y deja de contar contra ese limite.
            //
            // Va apagada por defecto a proposito: este array pasa por array_filter(), que borra
            // toda clave de valor falsy, asi que sin DB_PERSISTENT en el .env la opcion NO llega al
            // PDO y la conexion se arma exactamente igual que antes de este cambio. Por eso el
            // cambio se mergea sin coordinar con nadie: se prende instancia por instancia, y la que
            // no la declare no se entera de nada.
            //
            // filter_var(..., FILTER_VALIDATE_BOOLEAN) y no un (bool) pelado: el .env devuelve
            // strings, y en PHP (bool) "false" es TRUE. Con el cast pelado, un DB_PERSISTENT=false
            // escrito justamente para apagarla la dejaria prendida; lo mismo con "off" o "no".
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                PDO::ATTR_PERSISTENT => filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOLEAN),
            ]) : [],
        ],

        

        'backup' => [
            'driver' => 'mysql',
            'host' => env('DB_BACKUP_HOST', '127.0.0.1'),
            'port' => env('DB_BACKUP_PORT', '3306'),
            'database' => env('DB_BACKUP_DATABASE', 'backup_sales'),
            'username' => env('DB_BACKUP_USERNAME', 'root'),
            'password' => env('DB_BACKUP_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
