<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * La regla de acceso de las rutas de PDF y de exportación de `routes/web.php` (misión
 * pdf-de-venta-publico, 10/10/2026). Un solo lugar para todas.
 *
 * Hasta esta misión esas rutas eran públicas: el grupo `web` no tiene ninguna capa de autenticación,
 * los controladores hacen `find($id)` pelado y los exports sin id usan `$this->userId()`, que sin
 * sesión cae en `config('app.USER_ID')`. En una base compartida, desde el frente de cualquier
 * comercio se leían los comprobantes de todos.
 *
 * Uso (alias `descarga.comercio` en `app/Http/Kernel.php`):
 *   ->middleware('descarga.comercio:sale,id')            tipo del recurso, nombre del parámetro
 *   ->middleware('descarga.comercio:sale,id,tienda')     ídem, y además habilita `?origin=tienda`
 *                                                        (SOLO `sale/pdf/{id}`, ver el paso 3)
 *   ->middleware('descarga.comercio:articles,ids')       lista 12-15-40 de artículos
 *   ->middleware('descarga.comercio:cuenta_corriente,credit_account_id,months_ago')
 *   ->middleware('descarga.comercio:comercio,company_name') reportes por nombre de comercio
 *   ->middleware('descarga.comercio:sesion')             rutas sin id (exports por fecha, listados)
 *
 * Con id, se sirve si se cumple la PRIMERA de estas que aplique:
 *   1. Sesión del dueño: `UserHelper::user()` (el dueño de la sesión, SIN el fallback a USER_ID de
 *      `userId()`) es el dueño del recurso (para `articles`: de todos). Cubre todo lo que abre la
 *      SPA, sin tocar ningún botón: la cookie de sesión viaja cuando la SPA abre el PDF.
 *   2. Token: el tipo admite token y `?t=` es válido para ESE tipo y ESE id (PdfLinkHelper). Con
 *      sesión de OTRO comercio se llega acá y no se corta: en una base compartida un comercio le
 *      manda un link a otro.
 *   3. Tienda: con la opción `tienda` en el middleware (y `?origin=tienda` en el pedido) sigue al
 *      controlador, que exige su `SalePdfAccessToken` de un solo uso. Eso no se toca.
 *      🔴 LA OPCIÓN VA SOLO EN `sale/pdf/{id}`: `SaleController@pdf` es el ÚNICO controlador que
 *      mira `origin=tienda`. Las otras rutas de tipo `sale` (ticket, ticket-raw, artículos
 *      entregados, etiqueta de envío) ignoran ese parámetro y servían el PDF a cualquiera que lo
 *      agregara (medido en vivo el 10/10/2026 contra la API del slot: 200 con el PDF sin sesión).
 *      Por eso el paso 3 no se decide por el tipo ni por el path, sino por una opción explícita
 *      puesta en la ruta que tiene el candado.
 *   4. Ventana de transición: `users.pdf_links_legacy_until` del dueño del recurso es posterior a
 *      ahora → se sirve como antes y queda un `Log::info` (ver registrar_uso_de_la_ventana()).
 *   5. Si no: 404.
 * Sin id (`sesion`): solo 1 y 4. El dueño para la ventana es el de `config('app.USER_ID')`, que es a
 * quien hoy caen esas rutas sin sesión.
 *
 * 🔴 SIEMPRE 404, NUNCA 403, Y EL MISMO 404 PARA "NO EXISTE" Y PARA "NO ES TUYO": un 403 o un mensaje
 * distinto le confirmaría a quien enumera ids que el comprobante existe. Por eso no se usa el
 * middleware `auth` de Laravel: en `web.php` no hay ruta `login` y `Authenticate` daría 500.
 *
 * ⚠️ Lo que este middleware NO hace: no cambia lo que el controlador busca ni cómo arma el PDF. Un
 * export sin id que pasa por la ventana sigue usando `userId()` adentro del controlador, igual que
 * hoy.
 */
class DescargaDelComercio
{
    /** Modo de las rutas sin id de recurso. */
    const MODO_SESION = 'sesion';

    /**
     * Opción (tercer argumento) que habilita el camino `?origin=tienda` del paso 3. Solo la lleva
     * `sale/pdf/{id}`, la única ruta cuyo controlador exige el `SalePdfAccessToken` de la tienda.
     */
    const OPCION_TIENDA = 'tienda';

    /**
     * Tipo especial de `current-acount/pdf/{credit_account_id}/{months_ago}/{type?}`: el mismo
     * parámetro es el id de una CUENTA corriente si la cantidad es mayor a cero, o el id de UN
     * movimiento si es cero (así lo lee `CurrentAcountController@pdfFromModel`).
     */
    const TIPO_CUENTA_CORRIENTE = 'cuenta_corriente';

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $tipo               Tipo del recurso, o 'sesion' para las rutas sin id.
     * @param  string|null  $parametro          Nombre del parámetro de la ruta con el id.
     * @param  string|null  $extra              Tercer argumento, según el tipo:
     *                                          - `cuenta_corriente`: el parámetro de la ruta con la
     *                                            cantidad de movimientos.
     *                                          - `sale`: OPCION_TIENDA ('tienda') habilita el paso 3,
     *                                            solo en `sale/pdf/{id}`.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $tipo = null, $parametro = null, $extra = null)
    {
        if (is_null($tipo) || $tipo === self::MODO_SESION) {
            return $this->sin_recurso($request, $next);
        }

        $model_id = is_null($parametro) ? null : $request->route($parametro);

        if ($tipo === self::TIPO_CUENTA_CORRIENTE) {
            // Sin el nombre del parámetro, `route(null)` devolvería la ruta entera y no un valor.
            $cantidad = is_null($extra) ? null : $request->route($extra);

            $tipo = $this->tipo_de_cuenta_corriente($cantidad);
        }

        $duenios = PdfLinkHelper::duenios_del_recurso($tipo, $model_id);

        // No existe (o el id no es un id): el mismo 404 que "no es tuyo".
        if (is_null($duenios)) {
            abort(404);
        }

        $sesion = UserHelper::user();

        // 1. Sesión del dueño de todo lo pedido.
        if (!is_null($sesion) && $this->son_todos_de($duenios, (int) $sesion->id)) {
            return $next($request);
        }

        // 2. Token del link, para ese tipo y ese id.
        if (PdfLinkHelper::admite_token($tipo) && PdfLinkHelper::token_valido($tipo, $model_id, $request->query('t'))) {
            return $next($request);
        }

        // 3. La tienda, SOLO en la ruta que lleva la opción: su controlador exige el token de un solo
        //    uso (SalePdfAccessToken). En las demás rutas de venta `origin=tienda` no abre nada.
        if ($tipo === 'sale' && $extra === self::OPCION_TIENDA && $request->query('origin') === 'tienda') {
            return $next($request);
        }

        // 4. Ventana de transición del dueño del recurso.
        if ($this->ventana_abierta_para_todos($duenios)) {
            $this->registrar_uso_de_la_ventana($request, $tipo, $model_id, $duenios, !is_null($sesion));

            return $next($request);
        }

        abort(404);
    }

    /**
     * Rutas sin id de recurso (exports por fecha, libros de IVA, gráficos): sesión, o la ventana de
     * transición del dueño de la instalación (`config('app.USER_ID')`).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    protected function sin_recurso(Request $request, Closure $next)
    {
        if (!is_null(UserHelper::user())) {
            return $next($request);
        }

        $duenio_de_la_instalacion = (int) config('app.USER_ID');

        if ($duenio_de_la_instalacion > 0 && $this->ventana_abierta_para_todos([$duenio_de_la_instalacion])) {
            $this->registrar_uso_de_la_ventana($request, self::MODO_SESION, null, [$duenio_de_la_instalacion], false);

            return $next($request);
        }

        abort(404);
    }

    /**
     * El tipo real de `current-acount/pdf/{credit_account_id}/{months_ago}`.
     *
     * Se compara EXACTAMENTE como `pdfFromModel()` (`$cantidad_movimientos > 0`, con el valor crudo
     * de la ruta): si el middleware y el controlador interpretaran distinto el mismo parámetro, el
     * permiso se chequearía sobre un recurso y el PDF saldría de otro.
     *
     * @param  mixed  $cantidad
     * @return string  'credit_account' o 'current_acount'.
     */
    protected function tipo_de_cuenta_corriente($cantidad)
    {
        return $cantidad > 0 ? 'credit_account' : 'current_acount';
    }

    /**
     * ¿Todos los dueños del recurso son este usuario?
     *
     * @param  int[]  $duenios  Nunca vacío (PdfLinkHelper devuelve null antes que un array vacío).
     * @param  int    $user_id
     * @return bool
     */
    protected function son_todos_de(array $duenios, $user_id)
    {
        if (!count($duenios)) {
            return false;
        }

        foreach ($duenios as $duenio) {
            if ((int) $duenio !== (int) $user_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿La ventana de transición está abierta para todos los dueños del recurso?
     *
     * Normalmente es uno solo. Con una lista de artículos de comercios distintos (que la SPA nunca
     * arma: solo un pedido a mano) se exige la de todos, para que la ventana de uno no abra lo del
     * otro.
     *
     * @param  int[]  $duenios
     * @return bool
     */
    protected function ventana_abierta_para_todos(array $duenios)
    {
        if (!count($duenios)) {
            return false;
        }

        $ahora = Carbon::now();

        $ventanas = User::whereIn('id', $duenios)->pluck('pdf_links_legacy_until', 'id');

        foreach ($duenios as $duenio) {

            $hasta = isset($ventanas[$duenio]) ? $ventanas[$duenio] : null;

            if (is_null($hasta) || !Carbon::parse($hasta)->greaterThan($ahora)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deja registrado cada uso de la ventana de transición: es lo que permite cerrarla sabiendo qué
     * links sin token se siguen abriendo y desde dónde (la SPA de un frente atrasado, un WhatsApp
     * viejo, alguien enumerando ids).
     *
     * 🔴 Nunca el token ni la IP: solo el host del `Referer`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $tipo
     * @param  mixed   $model_id
     * @param  int[]   $duenios
     * @param  bool    $con_sesion  Si había una sesión (de otro comercio, si llegó hasta acá).
     * @return void
     */
    protected function registrar_uso_de_la_ventana(Request $request, $tipo, $model_id, array $duenios, $con_sesion)
    {
        $referer = $request->headers->get('referer');

        $referer_host = is_string($referer) && $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;

        $ruta = $request->route();

        Log::info('Descarga servida por la ventana de transición de links sin token', [
            'ruta'         => is_null($ruta) ? null : $ruta->uri(),
            'tipo'         => $tipo,
            'id'           => is_scalar($model_id) ? mb_substr((string) $model_id, 0, 100) : null,
            'duenios'      => $duenios,
            'con_sesion'   => (bool) $con_sesion,
            'trajo_token'  => $request->query('t') !== null,
            'referer_host' => $referer_host ? $referer_host : null,
        ]);
    }
}
