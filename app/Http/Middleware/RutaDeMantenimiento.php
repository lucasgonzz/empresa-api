<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * La puerta de las rutas de mantenimiento, debug y administración interna de `routes/mantenimiento.php`
 * (misión cerrar-rutas-web-publicas, 10/10/2026). Alias `solo.mantenimiento` en `app/Http/Kernel.php`.
 *
 * QUÉ AGUJERO CIERRA
 * ------------------
 * `routes/web.php` se monta con el grupo `web`, que no tiene ninguna capa de autenticación. Ahí vivían,
 * abiertas a cualquiera que escribiera la URL, rutas que borran y reescriben datos: `helpers/{method}`
 * (ejecuta CUALQUIERA de los ~146 métodos de HelperController: borrar clientes, pisar códigos de barras,
 * iniciar sesión como un usuario fijo...), `cambiar-bbdd/*` (copia y BORRA en la base que diga la URL),
 * `register-user` (crea un dueño con contraseña `123`), `POST /demo-setup` (migrate:fresh), el
 * configurador de extensiones, la mensualidad, el catálogo entero para n8n, etc. Se cierran por la
 * PUERTA, no controlador por controlador: ninguno de esos controladores se tocó, y una ruta nueva de este
 * tipo queda cerrada con solo ponerla en `routes/mantenimiento.php` (todo ese archivo pasa por acá).
 *
 * Deja pasar si se cumple la PRIMERA de estas:
 *   1. `app()->environment('testing')`: la suite. Se mira el entorno de la APLICACIÓN (`$app['env']`), no
 *      `config('app.env')`: hay tests que simulan "una instancia de cliente" con
 *      `config(['app.env' => 'production'])` para probar OTRA guarda y siguen necesitando llegar al
 *      controlador (GuardaDeInstanciaDelDemoSetupTest). Los tests de esta puerta fijan
 *      `$this->app['env'] = 'production'`.
 *   2. `config('app.rutas_de_mantenimiento') === true` (env `RUTAS_DE_MANTENIMIENTO=true`): una variable
 *      EXPLÍCITA para el `empresa.local` de Lucas. 🔴 NO se abre por `APP_ENV=local`, a propósito: no se
 *      pudo medir que ningún cliente ni demo de producción tenga `APP_ENV=local` en su `.env` (decisión de
 *      Lucas, 10/10/2026). Una variable que nadie tiene puesta por accidente no abre nada por accidente.
 *   3. La ruta lleva el parámetro `demo` (`solo.mantenimiento:demo`) y la instancia es una demo
 *      (`config('app.FOR_USER') === 'demo'`, el mismo marcador que ya protege el demo-setup). Existe por
 *      el configurador de extensiones: la filmación del centro de recursos lo usa HOY sobre las demos, sin
 *      login, por Playwright (`_video/centro9`, `GRABACION.md:208`), y el admin no tiene otra vía para
 *      prender una extensión. Fuera de una demo, ese configurador pide la clave como todo lo demás. Solo
 *      lo llevan esas tres rutas: `helpers/` y el resto siguen cerrados también en las demos.
 *   4. La sesión ya está habilitada: `session('mantenimiento_habilitado_hasta')` es posterior a ahora.
 *   5. Viene la clave maestra, en el header `X-Clave-Mantenimiento` o en el query `clave`, y su sha256
 *      coincide con `config('app.mantenimiento_clave_sha256')` (hash_equals). Habilita la sesión por 2 h.
 *
 *   6. EL ACCESO DEL ADMIN (misión extensiones-desde-el-admin, 15/10/2026), SOLO en las rutas con `:demo` (el
 *      configurador). El admin de ComercioCity no tiene la clave maestra pero sí la clave de admin del cliente:
 *      con ella pide a `POST api/admin-sync/extensiones/acceso` (ExtensionesAccesoController) un token de un
 *      solo uso (64 hex, en cache solo su sha256, vence a los 120 s) y manda al navegador a
 *      `/extensiones?acceso=<token>`. Acá se canjea: `Cache::pull` (un solo uso: el segundo canje, o uno
 *      vencido, es 404), se habilita la sesión con `acceso_extensiones_hasta` (+2 h, id de sesión nuevo) y se
 *      redirige (302 relativo) a la misma URL sin `acceso`.
 *      🔴 ALCANCE ACOTADO, a diferencia de la clave maestra: ese flag abre SOLO `extensiones`,
 *      `users.extencions.edit` y `users.extencions.update` (nunca `helpers/*`, copias de bases ni la
 *      mensualidad, aunque algún día lleven `:demo`), y si la ruta trae `{user_id}`, solo el de la instancia
 *      (`config('app.USER_ID')`): el acceso es "configurar ESTE comercio", no cualquiera de una base compartida.
 *      El token nunca se loguea ni se guarda en claro.
 *
 * Si no: un `Log::warning` (patrón de la ruta, método, IP y si trajo clave — NUNCA la clave ni la URL
 * completa, que la llevaría en el query) y `abort(404)`.
 *
 * 🔴 POR QUÉ 404 Y NO 403: el CUERPO es el mismo de una ruta que no existe. Un 403 le confirmaría a quien
 * barre URLs que ahí hay algo, y le diría en cuál de las 67 rutas de mantenimiento vale la pena
 * insistir con claves. Los HEADERS no son idénticos, y es sabido: la puerta corre DENTRO del grupo
 * `web`, que ya puso el `Set-Cookie` de la sesión y el de XSRF-TOKEN (un 404 de "ninguna ruta coincide"
 * no los trae). Se acepta porque la lista de rutas no es un secreto (está en un repo público) y la puerta
 * necesita la sesión para la ventana de 2 horas; lo que protege es la clave, no que no se sepa la URL.
 *
 * 🔴 POR QUÉ NO `auth`: el `Authenticate` de Laravel, en una ruta de `web.php` que no espera JSON, llama a
 * `route('login')`, y en este proyecto no hay ninguna ruta con ese nombre: el request muere en 500 (y se
 * reporta como error). Además estas rutas no son de un comercio: son de quien mantiene la instalación, y
 * una sesión de cualquier dueño no tiene que abrir `helpers/` ni `cambiar-bbdd/`.
 *
 * POR QUÉ LA SESIÓN DURA 2 HORAS: el uso real es a mano y por un rato (abrir el configurador, prender
 * algo, mirar la mensualidad, correr un helper y su verificación). Pedir la clave en cada request sería
 * pegarla en cada URL; dejarla abierta para siempre haría de una cookie robada una llave permanente. El
 * vencimiento es fijo desde que se ingresó la clave (no se estira con el uso).
 *
 * POR QUÉ SE REDIRIGE SIN `clave` EN UN GET: la clave en el query queda en el historial del navegador, en
 * los logs de acceso del servidor y viaja en el Referer de lo que cargue la página. Con la sesión ya
 * habilitada, el 302 a la misma URL sin `clave` hace que la página que el usuario ve (y la que queda en el
 * historial) no la tenga. Pasa lo mismo si la sesión YA estaba habilitada y el GET igual trae `clave`. La
 * redirección es RELATIVA (ver redirigir_sin_la_clave()). En un POST/PUT no se redirige (se perdería el
 * cuerpo): sigue al controlador. Por header tampoco: no queda en ningún lado y es lo que usa un script.
 *
 * POR QUÉ CORRE DESPUÉS DEL GRUPO `web`: necesita la sesión (StartSession). Consecuencia: un POST sin token
 * CSRF recibe el 419 de VerifyCsrfToken antes de llegar acá; sigue estando cerrado, solo que con otro
 * código. Y nada de lo que corre antes toca datos del negocio (solo la sesión, en su driver).
 *
 * POR QUÉ SHA256 Y NO BCRYPT (decisión técnica del plan): la clave es un secreto aleatorio de 256 bits que
 * genera un script (`generar-clave-maestra.ps1`), no una contraseña humana; con ese azar un hash rápido no
 * se puede revertir. bcrypt le costaría ~60 ms de CPU a CADA intento fallido en un grupo `web` sin
 * throttle: un vector de carga gratis. FALLA CERRADO: con el hash vacío, de un largo distinto de 64 o que
 * no sea hexadecimal, o con la clave vacía, no matchea nunca.
 */
class RutaDeMantenimiento
{
    /** Parámetro del middleware (`solo.mantenimiento:demo`) que abre la ruta en una instancia de demo. */
    const OPCION_DEMO = 'demo';

    /** Clave de la sesión con el timestamp (segundos) hasta el que la sesión queda habilitada. */
    const SESION_HABILITADA_HASTA = 'mantenimiento_habilitado_hasta';

    /** Header por el que puede venir la clave maestra (scripts). */
    const HEADER_DE_LA_CLAVE = 'X-Clave-Mantenimiento';

    /** Parámetro del query por el que puede venir la clave maestra (a mano, desde el navegador). */
    const PARAMETRO_DE_LA_CLAVE = 'clave';

    /** Horas que la sesión queda habilitada después de ingresar la clave. */
    const HORAS_DE_SESION = 2;

    /** Clave de la sesión con el timestamp hasta el que el acceso del admin al configurador sigue vigente. */
    const SESION_ACCESO_EXTENSIONES_HASTA = 'acceso_extensiones_hasta';

    /** Parámetro del query por el que viene el token de acceso del admin (`?acceso=<64 hex>`). */
    const PARAMETRO_DEL_ACCESO = 'acceso';

    /** Prefijo de la clave de cache con la que se guarda el sha256 de cada token de acceso emitido. */
    const PREFIJO_DE_CACHE_DEL_ACCESO = 'extensiones_acceso:';

    /** Nombres de las únicas rutas que abre el acceso del admin: las tres del configurador de extensiones. */
    const RUTAS_DEL_ACCESO_DEL_ADMIN = ['extensiones', 'users.extencions.edit', 'users.extencions.update'];

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $opcion  `demo` para las rutas que se abren en una instancia de demo.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $opcion = null)
    {
        // 1. La suite de tests (el entorno de la aplicación, no config('app.env'): ver el docblock).
        if (app()->environment('testing')) {
            return $next($request);
        }

        // 2. La variable explícita del entorno local de Lucas. Nunca por APP_ENV=local.
        if (config('app.rutas_de_mantenimiento') === true) {
            return $next($request);
        }

        // 3. Solo las rutas que llevan el parámetro `demo`, y solo en una instancia de demo.
        if ($opcion === self::OPCION_DEMO && config('app.FOR_USER') === 'demo') {
            return $next($request);
        }

        // 4. La sesión ya habilitada por una clave válida, mientras no venza.
        if ($this->sesion_habilitada($request)) {
            // Aunque ya no haga falta, si el GET trae `clave` en la URL se la saca igual (sin validarla:
            // la sesión ya está abierta): si no, la clave quedaría en el historial y en los logs.
            if ($this->es_get_o_head($request) && array_key_exists(self::PARAMETRO_DE_LA_CLAVE, $request->query())) {
                return $this->redirigir_sin_la_clave($request);
            }

            return $next($request);
        }

        // 4b. El acceso del admin ya canjeado: vale solo para el configurador y para el comercio de la instancia.
        if ($opcion === self::OPCION_DEMO
            && $this->sesion_habilitada($request, self::SESION_ACCESO_EXTENSIONES_HASTA)
            && $this->ruta_del_acceso_del_admin($request)) {
            return $next($request);
        }

        // 5. La clave maestra: primero el header (scripts), después el query (a mano).
        $clave_por_header = $this->como_texto($request->header(self::HEADER_DE_LA_CLAVE));
        $clave_por_query  = $this->como_texto($request->query(self::PARAMETRO_DE_LA_CLAVE));

        if ($this->clave_valida($clave_por_header)) {
            $this->habilitar_la_sesion($request);

            return $next($request);
        }

        if ($this->clave_valida($clave_por_query)) {
            $this->habilitar_la_sesion($request);

            // En un GET se saca la clave de la URL antes de mostrar nada (ver el docblock).
            if ($this->es_get_o_head($request)) {
                return $this->redirigir_sin_la_clave($request);
            }

            return $next($request);
        }

        // 6. El token de acceso que emitió el admin, solo en el configurador (`:demo`) y solo en un GET.
        if ($opcion === self::OPCION_DEMO && $request->isMethod('GET') && $this->canjear_el_acceso($request)) {
            return $this->redirigir_sin_la_clave($request);
        }

        // Ni la clave ni la URL completa: la URL la llevaría en el query. Se loguea el PATRÓN de la ruta.
        Log::warning('Ruta de mantenimiento cerrada', [
            'ruta'      => is_null($request->route()) ? null : $request->route()->uri(),
            'metodo'    => $request->getMethod(),
            'ip'        => $request->ip(),
            'con_clave' => $clave_por_header !== '' || $clave_por_query !== '',
        ]);

        abort(404);
    }

    /**
     * ¿La sesión de este request ya fue habilitada por una clave válida, y sigue vigente?
     *
     * Sin sesión (la ruta montada sin el grupo `web`) no hay nada que mirar: cerrado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $clave_de_sesion  Qué flag de la sesión mirar (por defecto el de la clave maestra).
     * @return bool
     */
    protected function sesion_habilitada(Request $request, $clave_de_sesion = self::SESION_HABILITADA_HASTA)
    {
        if (!$request->hasSession()) {
            return false;
        }

        $hasta = $request->session()->get($clave_de_sesion);

        // now() y no time(): el reloj de Carbon es el que mueven los tests con travel().
        return is_numeric($hasta) && (int) $hasta > now()->getTimestamp();
    }

    /**
     * Habilita la sesión por HORAS_DE_SESION desde ahora.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $clave_de_sesion  Qué flag de la sesión poner (por defecto el de la clave maestra).
     * @return void
     */
    protected function habilitar_la_sesion(Request $request, $clave_de_sesion = self::SESION_HABILITADA_HASTA)
    {
        if (!$request->hasSession()) {
            return;
        }

        /*
         * Id de sesión nuevo, como en un login: la sesión pasa a tener un privilegio que antes no tenía, y
         * un id que alguien haya conseguido ANTES (fijado en el navegador) no tiene que heredarlo.
         * `regenerate()` conserva los datos (el token CSRF incluido).
         */
        $request->session()->regenerate();

        $request->session()->put($clave_de_sesion, now()->addHours(self::HORAS_DE_SESION)->getTimestamp());
    }

    /**
     * ¿La clave recibida es la clave maestra? Compara el sha256 con hash_equals (tiempo constante).
     *
     * Falla cerrado: hash configurado vacío, de un largo distinto de 64 o no hexadecimal, o clave vacía
     * → false. Sin el chequeo de la clave vacía, una instancia con el hash de '' cargado por error se
     * abriría con `?clave=`.
     *
     * @param  string  $clave
     * @return bool
     */
    protected function clave_valida($clave)
    {
        if ($clave === '') {
            return false;
        }

        $hash_esperado = strtolower(trim((string) config('app.mantenimiento_clave_sha256')));

        if (strlen($hash_esperado) !== 64 || !ctype_xdigit($hash_esperado)) {
            return false;
        }

        return hash_equals($hash_esperado, hash('sha256', $clave));
    }

    /**
     * La clave de cache de un token de acceso: el prefijo más su sha256. En cache nunca está el token.
     * Pública y estática porque la usa también ExtensionesAccesoController al emitirlo.
     *
     * @param  string  $token
     * @return string
     */
    public static function clave_de_cache_del_acceso($token)
    {
        return self::PREFIJO_DE_CACHE_DEL_ACCESO . hash('sha256', (string) $token);
    }

    /**
     * Intenta canjear el `?acceso=` del request. Si el token es válido lo CONSUME (`Cache::pull`: un solo
     * uso) y habilita la sesión para el configurador.
     *
     * Falla cerrado: sin sesión, sin parámetro, con algo que no sea texto de 64 hexadecimales, o con un
     * token desconocido / vencido / ya usado → false. Se mira el formato ANTES de tocar la cache. Se busca
     * por el sha256 del token, así que no hay comparación de texto en claro que filtre tiempos.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function canjear_el_acceso(Request $request)
    {
        if (!$request->hasSession()) {
            return false;
        }

        $token = $this->como_texto($request->query(self::PARAMETRO_DEL_ACCESO));

        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return false;
        }

        // Minúsculas: el token se emite en minúsculas, y así `AB..` y `ab..` no son dos tokens distintos.
        /*
         * Un solo uso, también bajo carrera: `Cache::pull` es un `get` seguido de un `forget` sin lock, y dos
         * canjes simultáneos podían pasar los dos. `forget` devuelve true UNA sola vez (DEL de redis es atómico y
         * el segundo `unlink` del store de archivos da false), así que el que no logra borrarla no canjea.
         */
        $clave_en_cache = self::clave_de_cache_del_acceso(strtolower($token));

        if (!Cache::has($clave_en_cache) || !Cache::forget($clave_en_cache)) {
            return false;
        }

        $this->habilitar_la_sesion($request, self::SESION_ACCESO_EXTENSIONES_HASTA);

        return true;
    }

    /**
     * ¿La ruta de este request es una de las tres que abre el acceso del admin, y, si trae `{user_id}`, es el
     * del comercio de esta instancia? La comparación del id es de tiempo constante y como texto (la ruta lo
     * entrega como string; la config puede ser int).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function ruta_del_acceso_del_admin(Request $request)
    {
        $ruta = $request->route();

        if (is_null($ruta) || !in_array($ruta->getName(), self::RUTAS_DEL_ACCESO_DEL_ADMIN, true)) {
            return false;
        }

        $user_id = $ruta->parameter('user_id');

        if (is_null($user_id)) {
            return true;
        }

        $user_id_de_la_instancia = config('app.USER_ID');

        if (!is_scalar($user_id_de_la_instancia) || (string) $user_id_de_la_instancia === '') {
            return false;
        }

        return hash_equals((string) $user_id_de_la_instancia, (string) $user_id);
    }

    /**
     * Lo que vino en un header o en el query, como texto. Un array (`?clave[]=x`) o cualquier otra cosa
     * cuenta como "sin clave": que no llegue a hash() y termine en un 500.
     *
     * @param  mixed  $valor
     * @return string
     */
    protected function como_texto($valor)
    {
        return is_string($valor) ? $valor : '';
    }

    /**
     * ¿Es un GET (o un HEAD, que Laravel trata igual)? Solo ahí se redirige: en un POST/PUT se perdería
     * el cuerpo.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function es_get_o_head(Request $request)
    {
        return $request->isMethod('GET') || $request->isMethod('HEAD');
    }

    /**
     * 302 a la misma URL sin el parámetro de la clave, con una URL RELATIVA.
     *
     * 🔴 RELATIVA A PROPÓSITO, Y SIN `redirect()->to()`: `redirect()` la vuelve absoluta con el host del
     * request, y con `TrustProxies::$proxies = '*'` ese host sale del header `X-Forwarded-Host`, que pone
     * cualquiera: el 302 de después de la clave podría mandar al navegador a otro dominio. Con una URL que
     * empieza con `/` el navegador se queda en el mismo host por el que entró.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirigir_sin_la_clave(Request $request)
    {
        return new RedirectResponse($this->url_relativa_sin_la_clave($request), 302);
    }

    /**
     * El path del request (con el base path: en el shared es `/public/...`) más el resto del query, sin
     * el parámetro de la clave ni el del acceso del admin (`acceso`): ninguno de los dos tiene que quedar
     * en el historial ni en el Referer.
     *
     * Las barras (y las invertidas) del principio se colapsan en una sola: `//otro-host/...` o `/\otro-host`
     * los navegadores los leen como una URL a otro dominio.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function url_relativa_sin_la_clave(Request $request)
    {
        $path = '/' . ltrim($request->getBaseUrl() . $request->getPathInfo(), '/\\');

        $query = $request->query();

        unset($query[self::PARAMETRO_DE_LA_CLAVE], $query[self::PARAMETRO_DEL_ACCESO]);

        $resto = http_build_query($query);

        return $path . ($resto === '' ? '' : '?' . $resto);
    }
}
