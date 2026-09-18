<?php

use Illuminate\Support\Str;

/**
 * Identidad del frente que está corriendo este código.
 *
 * Un cliente tiene DOS frentes (api-galvan y api-galvan2, por ejemplo): uno sirve la versión
 * activa y el otro queda preparado con la próxima, y el upgrade rota cuál de los dos atiende.
 * Los dos cuelgan del mismo dominio raíz, así que hasta ahora emitían LA MISMA cookie de sesión
 * -mismo nombre, mismo path, mismo domain-: para el navegador era una sola cookie. Pero con
 * SESSION_DRIVER=file cada frente guarda sus sesiones en su propio disco
 * (/home/api-galvan/... vs /home/api-galvan2/...), así que el id que escribe uno NO EXISTE en el
 * almacén del otro. Cada respuesta del frente viejo pisaba la cookie del nuevo con un id que el
 * nuevo no conoce, y al redirigir de un frente al otro el usuario quedaba con 401 en todo.
 *
 * 🔴 Se toma de la CARPETA de instalación, no de APP_URL, y esto se midió en producción el
 * 10/9/2026 antes de elegirlo: los dos frentes de `servian` tienen el MISMO APP_URL
 * (https://api-servian.comerciocity.com en /home/api-servian y en /home/api-servian2), así que
 * derivar de ahí le habría dado a ese cliente el mismo nombre en los dos frentes -o sea, un
 * no-op silencioso: ningún error, el problema intacto-. Y otras ocho carpetas del hosting
 * compartido directamente no tienen APP_URL en su .env. La carpeta, en cambio, es distinta
 * siempre y por construcción: cada frente es su propia instalación, tanto en el VPS
 * (/home/api-galvan vs /home/api-galvan2) como en el compartido (.../galvan/api vs
 * .../galvan2/api). Dos frentes no pueden compartirla.
 *
 * De paso evita dos fragilidades de APP_URL: que cambia con solo agregarle o sacarle la barra
 * final -y cualquier cambio del nombre desloguea a ese frente-, y que depende de QUÉ archivo
 * .env se haya cargado (con APP_ENV=testing y un .env.testing al lado, el valor es otro).
 *
 * A propósito NADA que dependa del request ($_SERVER['HTTP_HOST'], request(), etc.): con
 * `config:cache` eso se congelaría con el valor del momento del cacheo, que es justo el modo de
 * falla que se quiere evitar. `base_path()` no tiene ese problema: cacheado o no, es la misma
 * carpeta.
 */
$frente_actual = (string) base_path();

/**
 * Hash corto y estable del frente. Hex, para que el nombre de la cookie siga siendo válido.
 *
 * Sin fallback a propósito. Acá había un `if ($frente_actual === '') { ... env('APP_URL') }` y se
 * sacó: `base_path()` sale de `dirname(__DIR__)` (bootstrap/app.php) y `dirname()` no devuelve
 * nunca cadena vacía, así que la rama era inalcanzable — y si alguien la alcanzara seteando
 * `APP_BASE_PATH=` vacío, caería en `APP_URL`, que es justo el valor que este archivo acaba de
 * descartar por medido: los dos frentes de `servian` lo tienen igual y ocho carpetas del
 * compartido no lo tienen. Con `APP_URL` ausente el sufijo sería `da39a3ee` (el sha1 de la cadena
 * vacía) para TODOS los frentes de TODOS los clientes: el bug original de vuelta, en silencio.
 * Un respaldo que solo puede empeorar es peor que no tener respaldo.
 */
$sufijo_de_frente = substr(sha1($frente_actual), 0, 8);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default session "driver" that will be used on
    | requests. By default, we will use the lightweight native driver but
    | you may specify any of the other wonderful drivers provided here.
    |
    | Supported: "file", "cookie", "database", "apc",
    |            "memcached", "redis", "dynamodb", "array"
    |
    */

    'driver' => env('SESSION_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Here you may specify the number of minutes that you wish the session
    | to be allowed to remain idle before it expires. If you want them
    | to immediately expire on the browser closing, set that option.
    |
    */

    'lifetime' => env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    |
    | This option allows you to easily specify that all of your session data
    | should be encrypted before it is stored. All encryption will be run
    | automatically by Laravel and you can use the Session like normal.
    |
    */

    'encrypt' => false,

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    |
    | When using the native session driver, we need a location where session
    | files may be stored. A default has been set for you but a different
    | location may be specified. This is only needed for file sessions.
    |
    */

    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    |
    | When using the "database" or "redis" session drivers, you may specify a
    | connection that should be used to manage these sessions. This should
    | correspond to a connection in your database configuration options.
    |
    */

    'connection' => env('SESSION_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    |
    | When using the "database" session driver, you may specify the table we
    | should use to manage the sessions. Of course, a sensible default is
    | provided for you; however, you are free to change this as needed.
    |
    */

    'table' => 'sessions',

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    |
    | While using one of the framework's cache driven session backends you may
    | list a cache store that should be used for these sessions. This value
    | must match with one of the application's configured cache "stores".
    |
    | Affects: "apc", "dynamodb", "memcached", "redis"
    |
    */

    'store' => env('SESSION_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    |
    | Some session drivers must manually sweep their storage location to get
    | rid of old sessions from storage. Here are the chances that it will
    | happen on a given request. By default, the odds are 2 out of 100.
    |
    */

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    |
    | Here you may change the name of the cookie used to identify a session
    | instance by ID. The name specified here will get used every time a
    | new session cookie is created by the framework for every driver.
    |
    */

    /**
     * El sufijo va sobre el nombre YA RESUELTO, no sobre el default: las instalaciones traen
     * SESSION_COOKIE cargado en su .env (comerciocity_session), así que sufijar solo el default
     * no cambiaría nada donde importa. Ver $sufijo_de_frente arriba para el porqué.
     */
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
    ).'_'.$sufijo_de_frente,

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    |
    | The session cookie path determines the path for which the cookie will
    | be regarded as available. Typically, this will be the root path of
    | your application but you are free to change this when necessary.
    |
    */

    'path' => '/',

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    |
    | Here you may change the domain of the cookie used to identify a session
    | in your application. This will determine which domains the cookie is
    | available to in your application. A sensible default has been set.
    |
    */

    'domain' => env('SESSION_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    |
    | By setting this option to true, session cookies will only be sent back
    | to the server if the browser has a HTTPS connection. This will keep
    | the cookie from being sent to you when it can't be done securely.
    |
    */

    'secure' => env('SESSION_SECURE_COOKIE', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    |
    | Setting this value to true will prevent JavaScript from accessing the
    | value of the cookie and the cookie will only be accessible through
    | the HTTP protocol. You are free to modify this option if needed.
    |
    */

    'http_only' => true,

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    |
    | This option determines how your cookies behave when cross-site requests
    | take place, and can be used to mitigate CSRF attacks. By default, we
    | will set this value to "lax" since this is a secure default value.
    |
    | Supported: "lax", "strict", "none", null
    |
    */

    'same_site' => 'lax',

];
