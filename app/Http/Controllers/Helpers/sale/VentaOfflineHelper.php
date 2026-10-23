<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Idempotencia de la subida de ventas hechas sin conexión (misión ingreso-sin-internet,
 * 23/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL CASO REAL
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Sin internet, VENDER guarda la venta en IndexedDB; cuando vuelve la red, `offline/sync_sales.js`
 *  las sube una por una con `POST /sale` y borra del equipo cada una que el servidor aceptó. Se
 *  midieron DOS pérdidas de datos en ese camino:
 *
 *   1. La guarda anti doble clic (`SaleController::venta_ya_cread()`: mismo comercio, cliente,
 *      empleado y total en ±5 segundos del `created_at`) descartaba la SEGUNDA de dos ventas
 *      offline idénticas subidas seguidas —dos ventas a consumidor final por el mismo total, lo más
 *      común en un mostrador—. Contestaba `200` con el cuerpo vacío, la SPA lo tomaba como
 *      aceptado y borraba la venta del equipo: venta perdida, sin rastro.
 *   2. Si el POST llegaba y se cortaba la RESPUESTA, la SPA la volvía a subir minutos después,
 *      fuera de la ventana de 5 segundos: venta duplicada, con el stock y la caja dos veces.
 *
 *  La guarda de 5 segundos no puede distinguir "la misma venta dos veces" de "dos ventas iguales":
 *  mira el contenido. El `offline_uuid` sí: lo genera la SPA UNA vez por venta y viaja igual en
 *  cada reintento. La SPA lo manda en DOS caminos, y por eso hay dos claves y no una:
 *
 *   - `offline_uuid` solo: el POST ONLINE de VENDER. Lo lleva para poder caer al guardado en el
 *     equipo si la respuesta no llega, sin duplicar cuando esa venta se suba después.
 *   - `offline_uuid` + `venta_offline` verdadero: la SUBIDA de una venta que estaba guardada en el
 *     equipo (`offline/sync_sales.js`). Es la única que manda `venta_offline`.
 *
 *  Con un uuid válido, `store()`:
 *
 *   - SIEMPRE busca primero una venta de este comercio con ese uuid, venga de donde venga, y si la
 *     hay no crea nada y devuelve ESA venta, con la misma forma y el mismo código que el alta (la
 *     SPA no distingue, y no tiene por qué);
 *   - si no la hay, crea la venta guardando el uuid. La guarda de 5 segundos (`venta_ya_cread()`)
 *     se SALTEA solo si además vino `venta_offline`: en la subida el uuid ya cubre los duplicados,
 *     y la guarda de contenido es justamente la que perdía ventas (caso 1). En el POST online la
 *     guarda SIGUE corriendo: un doble clic o el atajo de teclado generan DOS uuid distintos para
 *     la misma venta, y lo único que los frena es mirar el contenido, como hasta hoy.
 *
 *  Sin `offline_uuid` en el request (el asistente de IA, una SPA vieja) el comportamiento es
 *  exactamente el de antes, guarda de 5 segundos incluida.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LO QUE CAMBIA SOLO EN LA SUBIDA (uuid válido + `venta_offline`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una venta guardada en el equipo puede subirse horas después y con OTRO usuario logueado en ese
 *  equipo. Dos datos del alta salían del momento de la subida y no del de la venta:
 *
 *   - QUIÉN la cargó. `SaleHelper::getEmployeeId()` con `employee_id` 0 cae al usuario logueado
 *     del POST: una venta que cargó el DUEÑO y subió un EMPLEADO quedaba a nombre del empleado
 *     (empleado de la venta, vendedor y comisión). La SPA manda `cargada_por_duenio` verdadero
 *     cuando la cargó el dueño, y con eso `getEmployeeId()` devuelve null (venta del dueño). Ver
 *     `la_cargo_el_duenio()`.
 *   - LA HORA. `SaleHelper::resolver_created_at()` arma día elegido + hora del momento de subir. La
 *     SPA manda `capturada_at` (ISO 8601 con zona, el `new Date().toISOString()` del momento en que
 *     se guardó en el equipo) y la hora sale de ahí. Ver `capturada_at_valida()` y
 *     `SaleHelper::resolver_created_at_de_venta_offline()`.
 *
 *  El POST online NO usa ninguna de las dos aunque las mande: ahí el usuario logueado y el reloj
 *  del servidor son los del momento de la venta, que es lo correcto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  LAS TRES REDES CONTRA DOS REQUESTS SIMULTÁNEOS CON EL MISMO UUID
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   1. El candado `GET_LOCK('crear_venta_<user_id>')` de `store()` serializa el alta por comercio:
 *      el segundo request espera al primero y recién entonces busca el uuid (ya commiteado).
 *   2. Si el candado no se pudo tomar (timeout de 10 s: `store()` sigue sin serializar), el índice
 *      único `sales_user_offline_uuid_uq (user_id, offline_uuid)` rechaza el segundo INSERT.
 *   3. Ese rechazo (`QueryException` 23000 / 1062 sobre ESE índice) lo reconoce
 *      `venta_ya_subida_si_choco_el_indice()`: `store()` hace rollback, busca la venta que ganó y
 *      la devuelve como si nada.
 *
 * ⚠️ `withTrashed()` en la búsqueda, a propósito: una venta que ya subió y que después alguien borró
 * a mano NO se tiene que volver a crear porque el equipo la reintente. Se devuelve la borrada
 * (`Controller::fullModel()` ya la lee con `withTrashed()`), la SPA la saca de la cola y listo.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class VentaOfflineHelper {

    /** Largo máximo del uuid: el de la columna (`string(36)`, el largo de un UUID canónico). */
    const LARGO_MAXIMO = 36;

    /** Nombre del índice único: lo usa la migración y lo reconoce la captura del 1062. */
    const INDICE_UNICO = 'sales_user_offline_uuid_uq';

    /** Código de error de MySQL para "Duplicate entry" (SQLSTATE 23000). */
    const MYSQL_DUPLICATE_ENTRY = 1062;

    /** Margen hacia el futuro que se le tolera a `capturada_at` (reloj del equipo adelantado). */
    const MARGEN_FUTURO_CAPTURADA_HORAS = 24;

    /**
     * ISO 8601 con zona obligatoria: `YYYY-MM-DDTHH:MM:SS`, fracción opcional de 1 a 6 dígitos, y
     * `Z` o un desfasaje `±HH:MM` / `±HHMM`. Es lo que produce `new Date().toISOString()`.
     */
    const REGEX_ISO_CON_ZONA = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?(Z|[+-][0-9]{2}:?[0-9]{2})$/';

    /**
     * Normaliza el `offline_uuid` que vino en el request.
     *
     * Vale solo un string no vacío (después de `trim()`) de hasta 36 caracteres. Cualquier otra
     * cosa —ausente, null, número, array, string vacío o más largo que la columna— se IGNORA como
     * si no hubiera venido: la venta sigue el camino de siempre. No se rechaza con un 422 porque
     * la venta en sí es válida, y rechazarla haría que una SPA con un uuid mal armado no pudiera
     * subir nunca lo que vendió sin conexión.
     *
     * No se valida el formato UUID: el contrato es "un identificador estable por venta", y la
     * unicidad la da el índice, no la forma.
     *
     * @param  mixed  $valor  `$request->input('offline_uuid')`.
     * @return string|null
     */
    static function normalizar_offline_uuid($valor) {

        if (!is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if ($valor === '' || strlen($valor) > Self::LARGO_MAXIMO) {
            return null;
        }

        return $valor;
    }

    /**
     * Lee una clave del request sin suponer que es un `Illuminate\Http\Request`.
     *
     * `SaleHelper::getEmployeeId()` —que ahora pasa por `la_cargo_el_duenio()`— y
     * `SaleController::venta_ya_cread()` se llaman también con un objeto plano: el test
     * `Indices_De_Venta_Y_Vender_Test` arma un `stdClass` con `client_id`, `employee_id` y `total`
     * y lo pasa directo. Con `$request->input()` eso era un "Call to undefined method", así que acá
     * se lee con `input()` si es un Request de Laravel, como propiedad si es otro objeto, y como
     * clave si es un array. Lo que no está vale null (= "no vino").
     *
     * @param  mixed   $request
     * @param  string  $clave
     * @return mixed
     */
    static function valor_del_request($request, $clave) {

        if ($request instanceof Request) {
            return $request->input($clave);
        }

        if (is_object($request)) {
            return isset($request->{$clave}) ? $request->{$clave} : null;
        }

        if (is_array($request)) {
            return array_key_exists($clave, $request) ? $request[$clave] : null;
        }

        return null;
    }

    /**
     * ¿Un valor del request es un "sí"? Acepta `true`, `1`, `"1"` y `"true"` (sin importar
     * mayúsculas), que es como llega un booleano por JSON, por form-data o por query string.
     * Cualquier otra cosa —ausente, null, 0, "0", "false", basura— es "no".
     *
     * @param  mixed  $valor
     * @return bool
     */
    static function es_verdadero($valor) {

        if ($valor === true || $valor === 1) {
            return true;
        }

        if (is_string($valor)) {
            $valor = strtolower(trim($valor));

            return $valor === '1' || $valor === 'true';
        }

        return false;
    }

    /**
     * ¿El request es la SUBIDA de una venta que estaba guardada en el equipo?
     *
     * Hacen falta las dos claves: un `offline_uuid` válido (ver `normalizar_offline_uuid()`) y
     * `venta_offline` verdadero. Un `venta_offline` sin uuid no alcanza: sin uuid no hay con qué
     * reconocer un reintento, y saltear la guarda de 5 segundos ahí volvería a abrir los duplicados.
     *
     * No mira si la columna existe: lo que decide acá (quién la cargó, la hora) no depende de ella.
     * Saltear la guarda de 5 segundos sí depende, y por eso `store()` lo decide con
     * `saltear_guarda_de_cinco_segundos()`.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return bool
     */
    static function es_subida_de_venta_offline($request) {

        if (is_null($request)) {
            return false;
        }

        if (is_null(Self::normalizar_offline_uuid(Self::valor_del_request($request, 'offline_uuid')))) {
            return false;
        }

        return Self::es_verdadero(Self::valor_del_request($request, 'venta_offline'));
    }

    /**
     * ¿`store()` se puede saltear la guarda anti doble clic de 5 segundos?
     *
     * Solo en la subida de una venta offline (ver `es_subida_de_venta_offline()`) Y con el uuid
     * utilizable, o sea con la columna ya migrada (ver `offline_uuid_utilizable()`). En la ventana
     * del deploy no se puede buscar por uuid, y quedarse sin ninguna de las dos guardas sería peor
     * que lo de hoy: ahí corre la de 5 segundos, como antes de esta misión.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null               $offline_uuid  El que devolvió `offline_uuid_utilizable()`.
     * @return bool
     */
    static function saltear_guarda_de_cinco_segundos($request, $offline_uuid) {

        return !is_null($offline_uuid) && Self::es_subida_de_venta_offline($request);
    }

    /**
     * ¿La venta que se está subiendo la cargó el dueño en el equipo?
     *
     * Solo en la subida de una venta offline (ver `es_subida_de_venta_offline()`) y con
     * `cargada_por_duenio` verdadero. La lee `SaleHelper::getEmployeeId()` en su rama de
     * `employee_id` 0, que es la que caía al usuario logueado del momento de la subida.
     *
     * @param  \Illuminate\Http\Request|null  $request
     * @return bool
     */
    static function la_cargo_el_duenio($request) {

        if (!Self::es_subida_de_venta_offline($request)) {
            return false;
        }

        return Self::es_verdadero(Self::valor_del_request($request, 'cargada_por_duenio'));
    }

    /**
     * El instante en que la venta se guardó en el equipo, en la zona de la app, o null si no hay
     * que usarlo.
     *
     * Devuelve null —y el alta sigue con `SaleHelper::resolver_created_at()`, lo de hoy— si:
     *  - el request no es la subida de una venta offline (ver `es_subida_de_venta_offline()`);
     *  - `capturada_at` no es un ISO 8601 CON ZONA (`2026-10-23T14:05:09.123Z`,
     *    `2026-10-23T11:05:09-03:00`). Se exige la zona porque sin ella no se sabe en qué hora
     *    está, y convertir una hora ambigua corre la venta tres horas sin que nadie lo note. El
     *    regex va antes de `Carbon::parse()` a propósito: `parse()` acepta "tomorrow" o "+1 week";
     *  - no se puede parsear (`parse()` tira y se atrapa) o es una fecha u hora inexistente
     *    (`2026-02-31`, `25:00`), que PHP no rechaza sino que desborda al día siguiente;
     *  - está más de 24 horas en el futuro respecto del reloj del servidor: es un equipo con el
     *    reloj roto, y guardar una venta "de pasado mañana" la saca de la caja y del día de hoy.
     *
     * No se le pone techo hacia el pasado: una venta offline puede subir días después, y su hora
     * real es justamente la de captura.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Carbon\Carbon|null  En `config('app.timezone')`.
     */
    static function capturada_at_valida($request) {

        if (!Self::es_subida_de_venta_offline($request)) {
            return null;
        }

        $valor = Self::valor_del_request($request, 'capturada_at');

        if (!is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        if (preg_match(Self::REGEX_ISO_CON_ZONA, $valor) !== 1) {

            Log::info('store sale: capturada_at con formato invalido ('.substr($valor, 0, 40).'); se usa la hora del servidor.');

            return null;
        }

        try {
            $leida = Carbon::parse($valor);
        } catch (\Throwable $e) {

            Log::info('store sale: capturada_at no se pudo leer ('.$valor.'); se usa la hora del servidor.');

            return null;
        }

        /*
         * 🔴 PHP NO TIRA CON UNA FECHA INEXISTENTE: la DESBORDA. `2026-02-31T10:00:00Z` se lee
         * como el 3 de marzo, y `25:00:00` como la 1 del día siguiente, sin error. Por eso se
         * compara lo leído —todavía en la zona que trajo el valor, antes de convertir— con los 19
         * caracteres de fecha y hora que vinieron: si no coinciden, hubo desborde y el valor no
         * sirve.
         */
        if ($leida->format('Y-m-d\TH:i:s') !== substr($valor, 0, 19)) {

            Log::info('store sale: capturada_at con una fecha u hora inexistente ('.$valor.'); se usa la hora del servidor.');

            return null;
        }

        $capturada = $leida->copy()->setTimezone(config('app.timezone'));

        $techo = Carbon::now(config('app.timezone'))->addHours(Self::MARGEN_FUTURO_CAPTURADA_HORAS);

        if ($capturada->greaterThan($techo)) {

            Log::info('store sale: capturada_at '.$valor.' esta mas de '.Self::MARGEN_FUTURO_CAPTURADA_HORAS.' horas en el futuro; se usa la hora del servidor.');

            return null;
        }

        return $capturada;
    }

    /**
     * El uuid con el que `store()` va a trabajar, o null si tiene que comportarse como siempre.
     *
     * Devuelve null cuando el uuid no sirve (ver `normalizar_offline_uuid()`) Y TAMBIÉN cuando la
     * columna todavía no existe (ventana del deploy, ver `OfflineUuidEsquemaHelper`): sin columna no
     * se puede buscar ni guardar, y en ese caso lo correcto es volver a la guarda de 5 segundos de
     * hoy, no quedarse sin ninguna guarda.
     *
     * @param  mixed  $valor  `$request->input('offline_uuid')`.
     * @return string|null
     */
    static function offline_uuid_utilizable($valor) {

        $offline_uuid = Self::normalizar_offline_uuid($valor);

        if (is_null($offline_uuid)) {
            return null;
        }

        if (!OfflineUuidEsquemaHelper::hay_columna()) {

            Log::info('store sale: vino offline_uuid '.$offline_uuid.' pero la columna sales.offline_uuid todavia no existe; se usa la guarda de 5 segundos.');

            return null;
        }

        return $offline_uuid;
    }

    /**
     * La venta de este comercio que ya subió con este uuid, borradas incluidas, o null.
     *
     * Solo lee `id` y `num`: `store()` devuelve la venta completa con `fullModel()`, y `num` es
     * para el log. La consulta va por el índice único `(user_id, offline_uuid)`.
     *
     * @param  int          $user_id       `$this->userId()` del controlador (el dueño del comercio).
     * @param  string|null  $offline_uuid  Ya pasado por `offline_uuid_utilizable()`.
     * @return \App\Models\Sale|null
     */
    static function buscar_venta_ya_subida($user_id, $offline_uuid) {

        if (is_null($offline_uuid)) {
            return null;
        }

        return Sale::withTrashed()
                    ->select('id', 'num')
                    ->where('user_id', $user_id)
                    ->where('offline_uuid', $offline_uuid)
                    ->first();
    }

    /**
     * ¿La excepción es el índice único de `offline_uuid` rechazando un INSERT repetido?
     *
     * Mira las tres cosas a la vez —SQLSTATE 23000, código 1062 y el nombre del índice en el
     * mensaje— para no confundirlo con otra violación de integridad (otra clave única, un NOT NULL)
     * que tiene que seguir siendo un 500 con su `report()`.
     *
     * @param  \Throwable  $e
     * @return bool
     */
    static function es_duplicado_de_offline_uuid($e) {

        if (!($e instanceof QueryException)) {
            return false;
        }

        $error_info = $e->errorInfo;

        if (!is_array($error_info) || !isset($error_info[0]) || (string) $error_info[0] !== '23000') {
            return false;
        }

        if (!isset($error_info[1]) || (int) $error_info[1] !== Self::MYSQL_DUPLICATE_ENTRY) {
            return false;
        }

        return strpos($e->getMessage(), Self::INDICE_UNICO) !== false;
    }

    /**
     * Última red (la 3 del encabezado): si el alta murió porque el índice único rechazó el uuid,
     * devuelve la venta que lo tiene; si no, null y `store()` sigue con su 500 de siempre.
     *
     * Se llama DESPUÉS del `DB::rollBack()` del catch: la transacción de este request ya está
     * cerrada, así que la búsqueda ve la venta que commiteó el otro request.
     *
     * @param  \Throwable   $e
     * @param  int          $user_id
     * @param  string|null  $offline_uuid  Ya pasado por `offline_uuid_utilizable()`.
     * @return \App\Models\Sale|null
     */
    static function venta_ya_subida_si_choco_el_indice($e, $user_id, $offline_uuid) {

        if (is_null($offline_uuid) || !Self::es_duplicado_de_offline_uuid($e)) {
            return null;
        }

        $venta = Self::buscar_venta_ya_subida($user_id, $offline_uuid);

        if (!is_null($venta)) {
            Self::log_venta_ya_subida($venta, $offline_uuid, 'el indice unico rechazo el segundo alta');
        }

        return $venta;
    }

    /**
     * Deja dicho en el log que una venta offline se reconoció como ya subida y no se volvió a crear.
     *
     * @param  \App\Models\Sale  $venta
     * @param  string            $offline_uuid
     * @param  string            $como          Qué red la reconoció (búsqueda previa o índice único).
     * @return void
     */
    static function log_venta_ya_subida($venta, $offline_uuid, $como) {

        Log::info('store sale: la venta offline '.$offline_uuid.' ya habia subido como venta N° '.$venta->num.' (id '.$venta->id.'); no se vuelve a crear ('.$como.').');
    }
}
