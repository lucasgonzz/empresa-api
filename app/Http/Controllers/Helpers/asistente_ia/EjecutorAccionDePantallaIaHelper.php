<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Models\AiMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
 * 🔴 NO CHEQUEA TENENCIA POR SÍ MISMO, y hay que decirlo: los controllers de pantalla resuelven casi
 * todos por `Model::find($id)` sin filtrar por dueño (hallazgo del 16/9/2026 sobre ComboController).
 * El genérico lo tapa porque conoce la tabla y el `user_id`; acá la ruta puede ser cualquiera y el
 * id cualquier segmento, así que la acción de pantalla hace exactamente lo que haría la pantalla
 * con ese id — ni más ni menos. Es el mismo agujero que tiene la SPA, no uno nuevo, y se declara.
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
