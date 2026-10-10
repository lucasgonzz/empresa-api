<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
 * Si no: un `Log::warning` (patrón de la ruta, método, IP y si trajo clave — NUNCA la clave ni la URL
 * completa, que la llevaría en el query) y `abort(404)`.
 *
 * 🔴 POR QUÉ 404 Y NO 403: es el mismo 404 de una ruta que no existe. Un 403 le confirmaría a quien
 * barre URLs que ahí hay algo, y le diría en cuál de las 67 rutas de mantenimiento vale la pena
 * insistir con claves.
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
 * historial) no la tenga. En un POST/PUT no se redirige (se perdería el cuerpo): sigue al controlador. Por
 * header tampoco: no queda en ningún lado y es lo que usa un script.
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
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return redirect()->to($this->url_sin_la_clave($request), 302);
            }

            return $next($request);
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
     * @return bool
     */
    protected function sesion_habilitada(Request $request)
    {
        if (!$request->hasSession()) {
            return false;
        }

        $hasta = $request->session()->get(self::SESION_HABILITADA_HASTA);

        // now() y no time(): el reloj de Carbon es el que mueven los tests con travel().
        return is_numeric($hasta) && (int) $hasta > now()->getTimestamp();
    }

    /**
     * Habilita la sesión por HORAS_DE_SESION desde ahora.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function habilitar_la_sesion(Request $request)
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

        $request->session()->put(self::SESION_HABILITADA_HASTA, now()->addHours(self::HORAS_DE_SESION)->getTimestamp());
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
     * La misma URL del request, con el resto del query intacto y sin el parámetro de la clave.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function url_sin_la_clave(Request $request)
    {
        $query = $request->query();

        unset($query[self::PARAMETRO_DE_LA_CLAVE]);

        $resto = http_build_query($query);

        return $request->url() . ($resto === '' ? '' : '?' . $resto);
    }
}
