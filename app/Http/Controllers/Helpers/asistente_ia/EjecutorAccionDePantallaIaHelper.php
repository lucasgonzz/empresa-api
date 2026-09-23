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
 * budgets); `{x_id}` → `xs` (`{article_id}` → articles, aunque la ruta sea `api/price-change/...`);
 * el parámetro del propio recurso (`{caja}` en `api/caja/{caja}`, `{cuotum}` en `api/cuota/...`) →
 * ese recurso; cualquier otro nombre → su propio plural, y nada más. Ese "nada más" es a propósito:
 * caer al primer segmento para `{ultimos_movimientos}` o `{value}` chequearía un contador contra
 * `stock_movements.id` y rechazaría lecturas válidas.
 *
 * Lo que la guarda NO decide, dicho en voz alta: una tabla que no existe con ese nombre, o que no
 * tiene `user_id` (afip_tickets, apertura_cajas, article_discounts, los estados y catálogos
 * globales), se deja pasar, y los ids que viajan en el CUERPO (un `sale_id` en un POST) no se
 * miran. Ahí la acción hace lo que haría la pantalla con ese id.
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
        self::verificar_tenencia($contexto, $declaracion['ruta'], $parametros);

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
     * Exige que cada {param} entero de la ruta sea una fila del dueño, cuando la tabla a la que
     * apunta se puede derivar y tiene `user_id` (ver el docblock de la clase). Lo llaman llamar()
     * antes del controller y la propuesta antes de armar la tarjeta.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $ruta  La ruta del catálogo, con sus {param}.
     * @param  array  $parametros  Los valores, por nombre.
     * @return void
     *
     * @throws AccionIaException  422 MENSAJE_AJENO si la fila no existe o es de otro dueño.
     */
    public static function verificar_tenencia(ContextoDeCargaIa $contexto, string $ruta, array $parametros)
    {
        $ruta = Catalogo::normalizar_ruta($ruta);

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\??\}/', $ruta, $m);

        foreach ($m[1] as $nombre) {

            if (!array_key_exists($nombre, $parametros)) {

                continue;
            }

            $valor = $parametros[$nombre];

            // Solo lo que es un id: fechas, códigos, nombres de modelo y flags no se miran.
            if (is_bool($valor) || !is_scalar($valor) || !ctype_digit((string) $valor)) {

                continue;
            }

            $tabla = self::tabla_del_parametro($ruta, $nombre);

            if (is_null($tabla)) {

                continue;
            }

            $user_id = DB::table($tabla)->where('id', (int) $valor)->value('user_id');

            if (is_null($user_id) || (int) $user_id !== (int) $contexto->owner_id) {

                throw new AccionIaException(422, self::MENSAJE_AJENO);
            }
        }
    }

    /**
     * La tabla scopeada por dueño a la que apunta un {param} de la ruta, o null si no se puede
     * decidir (no hay tabla con ese nombre, o la tabla no tiene `user_id`). Las reglas están en el
     * docblock de la clase.
     *
     * @param  string  $ruta  Ya normalizada.
     * @param  string  $nombre  El nombre del {param}.
     * @return string|null
     */
    protected static function tabla_del_parametro(string $ruta, string $nombre)
    {
        $segmentos = explode('/', $ruta);

        $segmento = isset($segmentos[1]) ? str_replace('-', '_', $segmentos[1]) : '';

        $recurso = $segmento === '' ? null : Str::plural($segmento);

        if ($nombre === 'id') {

            $candidatas = [$recurso];

        } elseif (Str::endsWith($nombre, '_id')) {

            $candidatas = [Str::plural(substr($nombre, 0, -3))];

        } else {

            $candidatas = [Str::plural($nombre)];

            // El parámetro del propio recurso, con el singular que inventó el inflector
            // (`{cuotum}` para `api/cuota`): va al recurso.
            if (!is_null($recurso) && Str::singular($segmento) === $nombre) {

                $candidatas[] = $recurso;
            }
        }

        foreach ($candidatas as $tabla) {

            if (is_null($tabla) || $tabla === '') {

                continue;
            }

            if (self::estado_de_la_tabla($tabla) === 'ok') {

                return $tabla;
            }
        }

        return null;
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
