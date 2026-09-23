<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Models\AiMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Ejecuta una acción de pantalla: llama a la misma ruta y al mismo controller que llama la pantalla,
 * autenticado como la persona (misión asistente-mcp, 22/9/2026, constructor B).
 *
 * Es el mismo movimiento que EjecutorGenericoIaHelper hizo para el ABM —armar el request de la SPA
 * y llamar al controller— llevado a cualquier ruta del catálogo (CatalogoDeAccionesDePantallaIaHelper).
 * La diferencia es que acá no hay una entidad ni un payload curado: la ruta se resuelve con el
 * matcher del router, los {param} se reemplazan por sus valores, el cuerpo va tal cual lo armó el
 * modelo, y la respuesta del controller es lo que la pantalla habría recibido.
 *
 * Lo que este ejecutor pone alrededor, que el controller no hace:
 *
 *   - 🔴 El catálogo se vuelve a consultar al ejecutar, no solo al proponer. Una tarjeta guarda la
 *     ruta con sus {param}; si esa ruta salió del catálogo (una exclusión nueva, un cambio de
 *     rutas) entre la propuesta y el clic, se corta con 422 y no se llama a nada.
 *   - 🔴 La extensión se verifica sobre el DUEÑO (PermisosIaHelper::tiene_extencion), que es lo que
 *     haría `check_extencion_empresa` en la ruta. Los middlewares de la ruta NO corren: la sesión
 *     la garantiza Auth (ver abajo) y `SubstituteBindings` lo reemplaza ImplicitRouteBinding.
 *   - La autenticación se verifica y no se supone: `Auth::id()` tiene que ser la persona del
 *     contexto, porque `$this->userId()` de los controllers sale de ahí. Los tres caminos que llegan
 *     acá (el clic por sanctum, la confirmación por texto y la consulta GET en el acto) la ponen
 *     con Auth::setUser() antes.
 *   - La respuesta del controller se interpreta: un status >= 400 es un rechazo de negocio (422 con
 *     su `message`), no una falla técnica; y el cuerpo viaja recortado a LARGO_MAXIMO caracteres,
 *     porque la respuesta más pesada del sistema (el índice de artículos) mide megabytes.
 *
 * 🔴 TENENCIA DE LOS IDS DE LA RUTA (verificar_tenencia()). Los controllers de pantalla resuelven
 * casi todos por `Model::find($id)` sin filtrar por dueño (hallazgo del 16/9/2026 sobre
 * ComboController; acá mismo `CajaController::destroy()` y `UserController::set_eliminar_articulos_offline()`).
 * Una persona nunca manda un id ajeno desde su pantalla; un modelo sí puede inventarlo, y en las
 * bases compartidas (51 comercios en `u767360347_empresa`) eso es tocar el negocio de otro. Por eso,
 * antes de llamar al controller —y también al proponer, para avisar en el acto—, cada {param} de la
 * ruta cuyo valor es un entero se resuelve a su tabla como lo hace CatalogoDeEscrituraIaHelper
 * (`Str::plural(str_replace('-', '_', <primer segmento después de api/>))`) y, si esa tabla existe
 * y tiene `user_id`, la fila tiene que ser del dueño; si no existe o es ajena, 422 MENSAJE_AJENO.
 *
 * Cómo se elige la tabla de cada {param}, y por qué así (medido sobre las 520 acciones con
 * parámetros de este router): `{id}` → el recurso del primer segmento (`api/budget/{id}/anular` →
 * budgets), y si ese slug no es una tabla, la del MODELO del controller
 * (`cc-payment-method-discount` → CurrentAcountPaymentMethodDiscountController →
 * current_acount_payment_method_discounts); `{x_id}` → `xs` (`{article_id}` → articles, aunque la
 * ruta sea `api/price-change/...`); el parámetro del propio recurso (`{caja}` en `api/caja/{caja}`,
 * `{cuotum}` en `api/cuota/...`) → ese recurso; cualquier otro nombre → su propio plural, y nada
 * más. Ese "nada más" es a propósito: caer al primer segmento para `{ultimos_movimientos}` o
 * `{value}` chequearía un contador contra `stock_movements.id` y rechazaría lecturas válidas.
 *
 * 🔴 Y LA FILA SE VERIFICA AUNQUE SU TABLA NO TENGA `user_id` (verificador de la misión, 23/9/2026:
 * `PUT api/article-variant/{id}` cambió el precio de una variante ajena y
 * `POST api/apertura-caja/reabrir/{id}` reabrió la caja de otro). Sin `user_id` se lee la fila y se
 * sube por sus columnas `*_id` hasta una tabla que sí lo tenga, dos saltos como máximo
 * (`apertura_caja_id` → apertura_cajas → `caja_id` → cajas): esa fila padre tiene que ser del
 * dueño. Si ninguna columna llega a un dueño —un catálogo global como current_acount_payment_methods
 * o unidad_medidas, compartido por todos los comercios de la base— una ESCRITURA se rechaza con
 * MENSAJE_NO_VERIFICABLE y una lectura pasa.
 *
 * Los ids del CUERPO (`id`, `*_id` de primer nivel) también se verifican: `PUT api/cheque/rechazar`
 * con el `cheque_id` de otro dueño lo dejaba rechazado. Ahí lo no verificable se deja pasar (son
 * referencias a catálogos como `moneda_id` o `afip_tipo_comprobante_id`), y 0, null y '' son "sin
 * valor". Lo que la guarda sigue sin decidir: ids anidados en el cuerpo (los renglones de una
 * venta) y tablas que no existen con el nombre derivado.
 *
 * Sus dos tipos van en EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS: un controller de pantalla
 * puede abrir su propia transacción, soltar un candado, disparar un job o un broadcast, y nada de
 * eso se revierte con el rollback de la transacción del ejecutor.
 */
class EjecutorAccionDePantallaIaHelper
{
    const MENSAJE_SIN_AUTENTICAR = 'No se pudo autenticar a la persona para ejecutar la acción.';

    const MENSAJE_NO_DISPONIBLE = 'Esa acción no está disponible desde el asistente.';

    const MENSAJE_RECHAZO = 'La pantalla rechazó la acción.';

    const MENSAJE_SIN_REGISTRO = 'No existe el registro que pide la ruta.';

    const MENSAJE_AJENO = 'Ese registro no es de este negocio o no existe.';

    const MENSAJE_NO_VERIFICABLE = 'No se puede verificar que ese registro sea de este negocio.';

    /** @var array<string, string>  [tabla => 'ok' | 'sin_user_id' | 'no_existe'], por proceso. */
    protected static $tablas = [];

    /**
     * Largo máximo (en caracteres) del JSON de la respuesta que viaja al modelo y queda en la
     * tarjeta. Pasado eso se corta el texto y se marca `recortado: true`.
     */
    const LARGO_MAXIMO = 30000;

    /**
     * Ejecuta la acción de una tarjeta (accion_pantalla o borrado_pantalla) al confirmarla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta: null, params, metodo, uri, status, respuesta, recortado}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $metodo = Catalogo::normalizar_metodo(isset($datos['metodo']) ? $datos['metodo'] : '');
        $ruta = isset($datos['ruta']) ? (string) $datos['ruta'] : '';
        $parametros = isset($datos['parametros']) && is_array($datos['parametros']) ? $datos['parametros'] : [];
        $cuerpo = isset($datos['cuerpo']) && is_array($datos['cuerpo']) ? $datos['cuerpo'] : [];

        /*
         * El tipo de la tarjeta y el método tienen que coincidir: una tarjeta `accion_pantalla`
         * (que en "directo" se ejecuta sola) no puede terminar corriendo un DELETE, ni una de
         * borrado un POST. Es la defensa contra una tarjeta forjada a mano en la base.
         */
        $metodos_del_tipo = [
            AiMessageAction::TIPO_ACCION_PANTALLA  => ['POST', 'PUT'],
            AiMessageAction::TIPO_BORRADO_PANTALLA => ['DELETE'],
        ];

        $tipo = (string) $accion->tipo;

        if (!isset($metodos_del_tipo[$tipo]) || !in_array($metodo, $metodos_del_tipo[$tipo], true)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $llamada = self::llamar($contexto, $metodo, $ruta, $parametros, $cuerpo);

        return [
            'texto'     => 'Hecho: '.$metodo.' '.$llamada['uri'],
            'ruta'      => null,
            'params'    => new \stdClass(),
            'metodo'    => $metodo,
            'uri'       => $llamada['uri'],
            'status'    => $llamada['status'],
            'respuesta' => $llamada['cuerpo'],
            'recortado' => $llamada['recortado'],
        ];
    }

    /**
     * Llama a la ruta como la llamaría la pantalla y devuelve lo que respondió.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $metodo  GET | POST | PUT | DELETE (PATCH se pliega en PUT).
     * @param  string  $uri  La ruta del catálogo, con sus {param}.
     * @param  array  $parametros  Los valores de los {param}, por nombre.
     * @param  array  $cuerpo  El cuerpo del request (o la query string, si es GET).
     * @return array{status: int, cuerpo: array, recortado: bool, uri: string}
     *
     * @throws AccionIaException
     */
    public static function llamar(ContextoDeCargaIa $contexto, string $metodo, string $uri, array $parametros, array $cuerpo): array
    {
        $metodo = Catalogo::normalizar_metodo($metodo);

        if (!in_array($metodo, Catalogo::METODOS, true)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $faltan = Catalogo::parametros_que_faltan(Catalogo::normalizar_ruta($uri), $parametros);

        if (count($faltan)) {

            throw new AccionIaException(422, 'Falta el parámetro '.$faltan[0].' de la ruta.');
        }

        $uri_concreta = Catalogo::uri_concreta($uri, $parametros);

        $declaracion = Catalogo::resolver_ruta($metodo, $uri_concreta);

        if (is_null($declaracion)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_extencion(str_replace('_', ' ', $declaracion['extension'])));
        }

        // Se vuelve a verificar acá y no solo al proponer: el registro pudo cambiar de dueño entre
        // la tarjeta y el clic, y una tarjeta se puede forjar.
        self::verificar_tenencia($contexto, $declaracion, $parametros, $cuerpo);

        if (is_null($contexto->persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $contexto->persona->id) {

            throw new AccionIaException(500, self::MENSAJE_SIN_AUTENTICAR);
        }

        $request = Request::create('/'.$uri_concreta, $metodo, $cuerpo);

        $request->headers->set('Accept', 'application/json');

        $request->setUserResolver(function () {
            return Auth::user();
        });

        try {

            $ruta = Route::getRoutes()->match($request);

        } catch (HttpExceptionInterface $e) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        /*
         * Defensa en profundidad: lo que matcheó tiene que ser la ruta del catálogo que se resolvió
         * arriba. Si no coincide, algo cambió entre las dos consultas y no se corre nada.
         */
        if ($ruta->uri() !== $declaracion['ruta']) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $ruta->bind($request);

        // request() y la fachada Request tienen que ver ESTE request mientras corre el controller;
        // después vuelve el original, que es el del clic (o ninguno, en el job).
        $request_anterior = app()->bound('request') ? app('request') : null;

        app()->instance('request', $request);

        try {

            // Lo que haría SubstituteBindings si el método tipara un modelo (en la práctica los
            // controllers reciben ids, pero se resuelve igual por si acaso).
            ImplicitRouteBinding::resolveForRoute(app(), $ruta);

            $respuesta = $ruta->run();

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            throw new AccionIaException(422, self::MENSAJE_SIN_REGISTRO);

        } catch (ValidationException $e) {

            throw new AccionIaException(422, self::primer_mensaje($e->errors()));

        } catch (AccionIaException $e) {

            throw $e;

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionDePantallaIaHelper: el controller lanzó al ejecutar una acción de pantalla', [
                'accion' => $declaracion['accion'],
                'metodo' => $metodo,
                'uri'    => $uri_concreta,
                'error'  => $e->getMessage(),
            ]);

            throw $e;

        } finally {

            if (!is_null($request_anterior)) {

                app()->instance('request', $request_anterior);
            }
        }

        list($status, $cuerpo_respuesta) = self::interpretar($respuesta);

        list($cuerpo_respuesta, $recortado) = self::recortar($cuerpo_respuesta);

        return [
            'status'    => $status,
            'cuerpo'    => $cuerpo_respuesta,
            'recortado' => $recortado,
            'uri'       => $uri_concreta,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // Tenencia
    // -------------------------------------------------------------------------------------------

    /**
     * Exige que cada id que viaja en la ruta, y cada `id` / `*_id` de primer nivel del cuerpo (o de
     * la query, si es GET), sea una fila del dueño (ver el docblock de la clase). Lo llaman llamar()
     * antes del controller y la propuesta antes de armar la tarjeta.
     *
     * Los ids de la ruta son el REGISTRO SOBRE EL QUE SE ACTÚA: si su tabla no tiene `user_id` y
     * tampoco se llega al dueño por sus padres, una escritura se rechaza como no verificable. Los
     * ids del cuerpo son referencias (a qué venta, a qué proveedor): un catálogo global sin padre
     * (`moneda_id`, `iva_id`, `afip_tipo_comprobante_id`) no se puede rechazar sin romper cargas
     * legítimas, así que ahí lo no verificable se deja pasar. Y 0, null y '' en el cuerpo son "sin
     * valor" (la SPA los manda así para una relación vacía), no un id.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion  La fila del catálogo (ruta, metodo, accion).
     * @param  array  $parametros  Los valores de los {param}, por nombre.
     * @param  array  $cuerpo  El cuerpo del request, o la query string si es GET.
     * @return void
     *
     * @throws AccionIaException  422 MENSAJE_AJENO si una fila no existe o es de otro dueño;
     *                            422 MENSAJE_NO_VERIFICABLE si el registro sobre el que se escribe
     *                            no se puede atribuir a ningún dueño.
     */
    public static function verificar_tenencia(ContextoDeCargaIa $contexto, array $declaracion, array $parametros, array $cuerpo = [])
    {
        $ruta = Catalogo::normalizar_ruta(isset($declaracion['ruta']) ? $declaracion['ruta'] : '');
        $escritura = Catalogo::normalizar_metodo(isset($declaracion['metodo']) ? $declaracion['metodo'] : 'GET') !== 'GET';
        $tabla_del_modelo = self::tabla_del_modelo_del_controller(isset($declaracion['accion']) ? (string) $declaracion['accion'] : '');

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\??\}/', $ruta, $m);

        foreach ($m[1] as $nombre) {

            if (!array_key_exists($nombre, $parametros)) {

                continue;
            }

            // Sin tabla derivable (fechas, códigos, contadores) no hay qué decidir.
            $tabla = self::tabla_del_parametro($ruta, $nombre, $tabla_del_modelo);

            if (is_null($tabla)) {

                continue;
            }

            /*
             * 🔴 Con tabla derivable, el valor TIENE que ser un entero. Antes un valor que no era
             * solo dígitos se salteaba "porque no es un id", y el verificador de la misión abrió la
             * caja de otro dueño con `caja_id = <ajeno>x`: el router matchea igual y MySQL castea
             * '161x' a 161 en el find() del controller. Lo que no es un id, con tabla, se rechaza.
             */
            self::verificar_fila($contexto, $tabla, $parametros[$nombre], $escritura);
        }

        foreach ($cuerpo as $clave => $valor) {

            if (!is_string($clave) || !is_scalar($valor) || is_bool($valor)) {

                continue;
            }

            // 0, null y '' son "sin valor" en el cuerpo, no un id que verificar.
            if (trim((string) $valor) === '' || trim((string) $valor) === '0') {

                continue;
            }

            if ($clave === 'id') {

                $tabla = self::tabla_del_parametro($ruta, 'id', $tabla_del_modelo);

            } elseif (Str::endsWith($clave, '_id')) {

                $tabla = self::tabla_si_existe(Str::plural(substr($clave, 0, -3)));

            } else {

                continue;
            }

            if (!is_null($tabla)) {

                self::verificar_fila($contexto, $tabla, $valor, false);
            }
        }
    }

    /**
     * Una fila tiene que ser del dueño: por su `user_id` si la tabla lo tiene, y si no, por el
     * `user_id` de sus padres (`*_id` → tabla padre), con dos saltos como máximo
     * (`apertura_caja_id` → apertura_cajas → `caja_id` → cajas).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tabla  Una tabla que existe.
     * @param  mixed  $valor  El id.
     * @param  bool  $exigir_verificable  true para el registro sobre el que se ESCRIBE: si ningún
     *                                    padre resuelve la tenencia (catálogo global), se rechaza.
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_fila(ContextoDeCargaIa $contexto, string $tabla, $valor, bool $exigir_verificable)
    {
        if (!self::es_id($valor)) {

            throw new AccionIaException(422, self::MENSAJE_AJENO);
        }

        if (self::estado_de_la_tabla($tabla) === 'ok') {

            $user_id = DB::table($tabla)->where('id', (int) $valor)->value('user_id');

            if (is_null($user_id) || (int) $user_id !== (int) $contexto->owner_id) {

                throw new AccionIaException(422, self::MENSAJE_AJENO);
            }

            return;
        }

        $fila = DB::table($tabla)->where('id', (int) $valor)->first();

        if (is_null($fila)) {

            throw new AccionIaException(422, self::MENSAJE_AJENO);
        }

        if (!self::tenencia_por_padres($contexto, (array) $fila, 2) && $exigir_verificable) {

            throw new AccionIaException(422, self::MENSAJE_NO_VERIFICABLE);
        }
    }

    /**
     * true si al menos un padre de la fila resolvió la tenencia (y todos los que resolvieron son
     * del dueño); false si ninguna columna `*_id` llegó a una tabla con `user_id`. Un padre de otro
     * dueño corta con MENSAJE_AJENO.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $fila
     * @param  int  $saltos  Cuántos niveles hacia arriba quedan por mirar.
     * @return bool
     *
     * @throws AccionIaException
     */
    protected static function tenencia_por_padres(ContextoDeCargaIa $contexto, array $fila, int $saltos): bool
    {
        $alguna = false;

        foreach ($fila as $columna => $valor) {

            if ($columna === 'user_id' || !Str::endsWith((string) $columna, '_id') || is_null($valor) || !self::es_id($valor)) {

                continue;
            }

            $padre = Str::plural(substr($columna, 0, -3));

            $estado = self::estado_de_la_tabla($padre);

            if ($estado === 'no_existe') {

                continue;
            }

            if ($estado === 'ok') {

                $user_id = DB::table($padre)->where('id', (int) $valor)->value('user_id');

                if (is_null($user_id) || (int) $user_id !== (int) $contexto->owner_id) {

                    throw new AccionIaException(422, self::MENSAJE_AJENO);
                }

                $alguna = true;

                continue;
            }

            if ($saltos > 1) {

                $fila_padre = DB::table($padre)->where('id', (int) $valor)->first();

                if (!is_null($fila_padre) && self::tenencia_por_padres($contexto, (array) $fila_padre, $saltos - 1)) {

                    $alguna = true;
                }
            }
        }

        return $alguna;
    }

    /**
     * La tabla del modelo del controller (`CajaController` → App\Models\Caja → cajas), o null si no
     * hay un modelo con ese nombre. Es el fallback para las rutas cuyo slug no coincide con la
     * tabla (`cc-payment-method-discount` → current_acount_payment_method_discounts).
     *
     * @param  string  $accion  Clase@metodo sin namespace.
     * @return string|null
     */
    protected static function tabla_del_modelo_del_controller(string $accion)
    {
        $clase = strtok($accion, '@');

        if (!is_string($clase) || $clase === '') {

            return null;
        }

        $modelo = 'App\Models\\'.preg_replace('/Controller$/', '', class_basename($clase));

        if (!class_exists($modelo) || !is_subclass_of($modelo, 'Illuminate\Database\Eloquent\Model')) {

            return null;
        }

        try {

            $tabla = (new $modelo())->getTable();

        } catch (\Throwable $e) {

            return null;
        }

        return self::tabla_si_existe($tabla);
    }

    /**
     * La tabla si existe (con o sin `user_id`), o null.
     *
     * @param  string|null  $tabla
     * @return string|null
     */
    protected static function tabla_si_existe($tabla)
    {
        if (!is_string($tabla) || $tabla === '') {

            return null;
        }

        return self::estado_de_la_tabla($tabla) === 'no_existe' ? null : $tabla;
    }

    /**
     * true si el valor es un id: un entero positivo escrito solo con dígitos, sin signo, sin
     * decimales y sin nada pegado ('161x' no es un id aunque MySQL lo castee a 161).
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function es_id($valor): bool
    {
        if (is_bool($valor) || !is_scalar($valor)) {

            return false;
        }

        return preg_match('/^[1-9][0-9]*$/', (string) $valor) === 1;
    }

    /**
     * La tabla a la que apunta un {param} de la ruta (exista o no su `user_id`: eso lo resuelve
     * verificar_fila()), o null si no se puede decidir porque no hay tabla con ese nombre. Las
     * reglas están en el docblock de la clase.
     *
     * Para el registro del propio recurso (`{id}`, `{caja}`, `{cuotum}`) se prueba PRIMERO la
     * tabla del slug de la URI y recién después la del modelo del controller: en
     * `PUT api/article/{id}/variants-disponibilidad` el controller es ArticleVariantController pero
     * el {id} es el del ARTÍCULO, y al revés (`cc-payment-method-discount`) el slug no es tabla y el
     * modelo sí.
     *
     * @param  string  $ruta  Ya normalizada.
     * @param  string  $nombre  El nombre del {param}.
     * @param  string|null  $tabla_del_modelo  La tabla del modelo del controller, si existe.
     * @return string|null
     */
    protected static function tabla_del_parametro(string $ruta, string $nombre, $tabla_del_modelo)
    {
        $segmentos = explode('/', $ruta);

        $segmento = isset($segmentos[1]) ? str_replace('-', '_', $segmentos[1]) : '';

        $recurso = $segmento === '' ? null : Str::plural($segmento);

        if (Str::endsWith($nombre, '_id')) {

            return self::tabla_si_existe(Str::plural(substr($nombre, 0, -3)));
        }

        // El propio recurso: `{id}`, o el parámetro con el nombre (o el singular del inflector,
        // `{cuotum}` para `api/cuota`) del primer segmento.
        $es_el_recurso = $nombre === 'id'
            || (!is_null($recurso) && (Str::plural($nombre) === $recurso || Str::singular($segmento) === $nombre));

        if ($es_el_recurso) {

            $tabla = self::tabla_si_existe($recurso);

            return is_null($tabla) ? self::tabla_si_existe($tabla_del_modelo) : $tabla;
        }

        return self::tabla_si_existe(Str::plural($nombre));
    }

    /**
     * 'ok' si la tabla existe y tiene `user_id`, 'sin_user_id' si existe sin la columna,
     * 'no_existe' si no. Cacheado por proceso: information_schema no se consulta dos veces por la
     * misma tabla.
     *
     * @param  string  $tabla
     * @return string
     */
    protected static function estado_de_la_tabla(string $tabla): string
    {
        if (!isset(self::$tablas[$tabla])) {

            if (!Schema::hasTable($tabla)) {

                self::$tablas[$tabla] = 'no_existe';

            } else {

                self::$tablas[$tabla] = Schema::hasColumn($tabla, 'user_id') ? 'ok' : 'sin_user_id';
            }
        }

        return self::$tablas[$tabla];
    }

    // -------------------------------------------------------------------------------------------
    // La respuesta del controller
    // -------------------------------------------------------------------------------------------

    /**
     * El status y el cuerpo decodificado de lo que devolvió el controller. Una respuesta con status
     * >= 400 es un rechazo de negocio y corta con 422 y su mensaje. Lo que no es JSON viaja como
     * `{texto}`; `Route::run()` ya convirtió una HttpResponseException en su respuesta.
     *
     * @param  mixed  $respuesta
     * @return array{0: int, 1: array}
     *
     * @throws AccionIaException
     */
    protected static function interpretar($respuesta): array
    {
        if ($respuesta instanceof Response) {

            $status = $respuesta->getStatusCode();

            if ($status >= 400) {

                throw new AccionIaException(422, self::mensaje_de_rechazo($respuesta));
            }

            if ($respuesta instanceof JsonResponse || $respuesta instanceof \Symfony\Component\HttpFoundation\JsonResponse) {

                $datos = json_decode((string) $respuesta->getContent(), true);

                return [$status, is_array($datos) ? $datos : ['texto' => (string) $respuesta->getContent()]];
            }

            if ($respuesta instanceof BinaryFileResponse || $respuesta instanceof StreamedResponse) {

                return [$status, ['texto' => '(la pantalla devolvió un archivo)']];
            }

            $contenido = (string) $respuesta->getContent();

            if (trim($contenido) === '') {

                return [$status, ['texto' => '']];
            }

            $decodificado = json_decode($contenido, true);

            return [$status, is_array($decodificado) ? $decodificado : ['texto' => $contenido]];
        }

        if (is_null($respuesta)) {

            return [200, ['texto' => '']];
        }

        if (is_scalar($respuesta)) {

            return [200, ['texto' => (string) $respuesta]];
        }

        // Un modelo, una colección, un array: lo que Laravel habría convertido en JSON.
        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $decodificado = $json === false ? null : json_decode($json, true);

        return [200, is_array($decodificado) ? $decodificado : ['texto' => $json === false ? '' : $json]];
    }

    /**
     * El cuerpo tal cual si su JSON entra en LARGO_MAXIMO; si no, el JSON cortado como texto y la
     * marca `recortado`.
     *
     * @param  array  $cuerpo
     * @return array{0: array, 1: bool}
     */
    protected static function recortar(array $cuerpo): array
    {
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {

            return [['texto' => 'La pantalla respondió con un contenido que no se pudo leer.'], false];
        }

        if (mb_strlen($json) <= self::LARGO_MAXIMO) {

            return [$cuerpo, false];
        }

        return [['texto' => mb_substr($json, 0, self::LARGO_MAXIMO), 'recortado' => true], true];
    }

    /**
     * El mensaje para la persona de una respuesta de rechazo: `message`, el primer error de
     * validación, `error`, o el genérico. Copiado de EjecutorGenericoIaHelper.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @return string
     */
    protected static function mensaje_de_rechazo(Response $respuesta): string
    {
        $datos = json_decode((string) $respuesta->getContent(), true);

        if (is_array($datos)) {

            if (isset($datos['message']) && is_string($datos['message']) && trim($datos['message']) !== '') {

                return trim($datos['message']);
            }

            if (isset($datos['errors']) && is_array($datos['errors'])) {

                $primero = self::primer_mensaje($datos['errors']);

                if ($primero !== self::MENSAJE_RECHAZO) {

                    return $primero;
                }
            }

            if (isset($datos['error']) && is_string($datos['error']) && trim($datos['error']) !== '') {

                return trim($datos['error']);
            }
        }

        return self::MENSAJE_RECHAZO;
    }

    /**
     * El primer mensaje de un array de errores de validación.
     *
     * @param  array  $errores
     * @return string
     */
    protected static function primer_mensaje(array $errores): string
    {
        foreach ($errores as $mensajes) {

            if (is_array($mensajes)) {

                foreach ($mensajes as $mensaje) {

                    if (is_string($mensaje) && trim($mensaje) !== '') {

                        return trim($mensaje);
                    }
                }

            } elseif (is_string($mensajes) && trim($mensajes) !== '') {

                return trim($mensajes);
            }
        }

        return self::MENSAJE_RECHAZO;
    }
}
