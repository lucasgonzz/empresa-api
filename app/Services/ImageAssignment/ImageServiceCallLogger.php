<?php

namespace App\Services\ImageAssignment;

use App\Models\ImageServiceCall;
use Carbon\Carbon;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registro de las consultas a servicios externos del circuito de imágenes de artículos (misión
 * imagenes-catalogo-completo, agregado del 27/9/2026, plan §12.1): cada búsqueda a Serper / Google
 * del motor y cada llamada a la IA que valida imágenes. El admin lo muestra por cliente
 * (AdminSync\ImagenesController).
 *
 * Regla de oro, la misma de AiTokenUsageHelper: registrar() NUNCA lanza. Es contabilidad de fondo;
 * una falla acá no puede tumbar la búsqueda de una imagen ni el tramo de una asignación. Todo va
 * adentro de un try/catch que degrada a Log::warning.
 *
 * 🔴 NUNCA GUARDA CLAVES. El `error` pasa por sin_claves() antes de grabarse: un timeout de Guzzle
 * trae la URL entera del pedido (la de Custom Search lleva `?key=...&cx=...`), y este registro lo
 * lee el admin. Los proveedores ya tapan su propia clave; esto es la segunda red. Desde la misión
 * serper-en-user-setup (28/9/2026) la clave de Serper puede ser la del COMERCIO
 * (users.serper_api_key), que config no conoce: registrar() la tacha también (claves_del_dueno()).
 */
class ImageServiceCallLogger
{
    /**
     * Filas que se borran como mucho en una purga: acota lo que tarda crear una asignación la
     * primera vez que se purga una tabla con mucha historia (lo que quede sale en la próxima).
     */
    const MAXIMO_A_PURGAR_POR_VEZ = 20000;

    /** Orígenes y tipos válidos (lo demás se graba con el valor por defecto). */
    const ORIGENES = [
        ImageServiceCall::ORIGEN_ASIGNACION,
        ImageServiceCall::ORIGEN_VALIDACION_INDIVIDUAL,
        ImageServiceCall::ORIGEN_ASISTENTE_CODIGO_DE_BARRAS,
    ];
    const TIPOS    = [ImageServiceCall::TIPO_BUSQUEDA, ImageServiceCall::TIPO_VALIDACION_IA];

    /**
     * Graba una consulta.
     *
     * Claves aceptadas en $datos (todas opcionales salvo user_id, tipo y proveedor):
     *   user_id (int, obligatorio: sin dueño no hay a quién mostrársela), run_id, item_id,
     *   article_id, article_name, origen (asignacion | validacion_individual), tipo (busqueda |
     *   validacion_ia), proveedor (serper | google | anthropic), modelo, criterio, consulta,
     *   ok (bool), cobrada (bool), http_status, error, resultados, candidatas, resumen,
     *   usage (el bloque `usage` de Anthropic: de ahí salen los cuatro contadores de tokens),
     *   duracion_ms.
     *
     * @param  array $datos
     * @return \App\Models\ImageServiceCall|null  La fila, o null si no se grabó.
     */
    public static function registrar(array $datos)
    {
        try {
            $user_id   = isset($datos['user_id']) ? (int) $datos['user_id'] : 0;
            $tipo      = isset($datos['tipo']) ? trim((string) $datos['tipo']) : '';
            $proveedor = isset($datos['proveedor']) ? trim((string) $datos['proveedor']) : '';

            if ($user_id <= 0 || !in_array($tipo, self::TIPOS, true) || $proveedor === '') {
                // Una fila sin dueño, sin tipo o sin proveedor no la puede mostrar nadie: mejor un
                // warning que un registro mudo.
                Log::warning('[ImagenesInteligentes] ImageServiceCallLogger::registrar sin user_id, tipo o proveedor: no se graba.', [
                    'user_id'   => $user_id,
                    'tipo'      => $tipo,
                    'proveedor' => $proveedor,
                ]);

                return null;
            }

            $origen = isset($datos['origen']) ? trim((string) $datos['origen']) : '';

            if (!in_array($origen, self::ORIGENES, true)) {
                $origen = ImageServiceCall::ORIGEN_ASIGNACION;
            }

            $usage = isset($datos['usage']) && is_array($datos['usage']) ? $datos['usage'] : null;

            $ok = !empty($datos['ok']);

            /*
             * Retención de 180 días también desde acá (sexta pasada, S5 + A4): un dueño que solo usa
             * el asistente (búsqueda por código) o la validación individual nunca crea una
             * asignación, y sin esto su registro no se purgaba nunca. purgar_viejas() tiene su marca
             * diaria por dueño (una sola consulta a la caché el resto del día) y nunca lanza.
             */
            self::purgar_viejas($user_id);

            return ImageServiceCall::create([
                'user_id'      => $user_id,
                'run_id'       => self::entero_o_null($datos, 'run_id'),
                'item_id'      => self::entero_o_null($datos, 'item_id'),
                'article_id'   => self::entero_o_null($datos, 'article_id'),
                'article_name' => self::texto_o_null($datos, 'article_name', 255),

                'origen'    => $origen,
                'tipo'      => $tipo,
                'proveedor' => mb_substr($proveedor, 0, 20),
                'modelo'    => self::texto_o_null($datos, 'modelo', 80),
                'criterio'  => self::texto_o_null($datos, 'criterio', 20),
                'consulta'  => self::texto_o_null($datos, 'consulta', 255),

                'ok'          => $ok,
                // Por defecto, cobrada = respondió bien (la regla de siempre: una búsqueda que
                // respondió se cobra aunque venga vacía; lo que falla no se cobra).
                'cobrada'     => array_key_exists('cobrada', $datos) ? (bool) $datos['cobrada'] : $ok,
                'http_status' => self::estado_http($datos),
                // Primero se tapan las claves y DESPUÉS se recorta: si el corte cayera en el medio de
                // una clave, quedaría un pedazo de ella que ya nadie reconoce. Las del servidor y la
                // de Serper del dueño (se busca solo cuando hay un error que limpiar).
                'error'       => isset($datos['error']) && !is_null($datos['error']) && trim((string) $datos['error']) !== ''
                    ? mb_substr(self::utf8_valido(self::sin_claves((string) $datos['error'], self::claves_del_dueno($user_id))), 0, 500)
                    : null,

                'resultados' => self::entero_acotado($datos, 'resultados', 65535),
                'candidatas' => self::entero_acotado($datos, 'candidatas', 255),
                'resumen'    => self::texto_o_null($datos, 'resumen', 255),

                'tokens_entrada'         => is_null($usage) ? null : self::token($usage, 'input_tokens'),
                'tokens_salida'          => is_null($usage) ? null : self::token($usage, 'output_tokens'),
                'tokens_cache_escritura' => is_null($usage) ? null : self::token($usage, 'cache_creation_input_tokens'),
                'tokens_cache_lectura'   => is_null($usage) ? null : self::token($usage, 'cache_read_input_tokens'),

                'duracion_ms' => self::entero_acotado($datos, 'duracion_ms', 4294967295),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo registrar una consulta de imágenes.', [
                'error'   => $e->getMessage(),
                'tipo'    => isset($datos['tipo']) ? $datos['tipo'] : null,
                'user_id' => isset($datos['user_id']) ? $datos['user_id'] : null,
            ]);

            return null;
        }
    }

    /**
     * Borra las consultas de más de DIAS_DE_RETENCION días DE ESTE DUEÑO. Se llama al crear una
     * asignación (fuera de la transacción del catálogo) y en cada registrar() (sexta pasada: así
     * purga también el dueño que solo usa el asistente); la marca diaria hace que el DELETE salga
     * una sola vez por día.
     *
     * Solo del dueño que crea la asignación y como mucho UNA vez por día por dueño (revisión
     * independiente del 27/9/2026): en una base compartida, crear una asignación no tiene por qué
     * pagar el borrado de los demás comercios, y dos asignaciones el mismo día no tienen nada nuevo
     * que borrar. El DELETE va por el índice (user_id, created_at) y como mucho MAXIMO_A_PURGAR_POR_VEZ
     * filas (lo que quede sale al día siguiente).
     *
     * Nunca lanza: si falla, la asignación se crea igual y la purga sale en la próxima.
     *
     * @param  int $user_id
     * @return int  Filas borradas (0 si ya se purgó hoy).
     */
    public static function purgar_viejas($user_id)
    {
        try {
            $user_id = (int) $user_id;

            if ($user_id <= 0) {
                return 0;
            }

            // Una vez por día por dueño: la marca vence a la medianoche.
            $marca = 'imagenes-inteligentes:purga-del-registro:'.$user_id.':'.Carbon::now()->toDateString();

            if (!Cache::add($marca, true, Carbon::now()->endOfDay())) {
                return 0;
            }

            return (int) DB::table('image_service_calls')
                ->where('user_id', $user_id)
                ->where('created_at', '<', Carbon::now()->subDays(ImageServiceCall::DIAS_DE_RETENCION))
                ->limit(self::MAXIMO_A_PURGAR_POR_VEZ)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo purgar image_service_calls: '.$e->getMessage());

            return 0;
        }
    }

    /* ----------------------------------------------------------------------------------------
     * El estado HTTP de Custom Search (lo usan el proveedor de Google de las asignaciones y la
     * búsqueda por código de barras del asistente, que buscan por el trait BusquedaDeImagenesEnGoogle
     * y el trait no lo devuelve).
     * -------------------------------------------------------------------------------------- */

    /**
     * @var object|null El dispatcher de eventos donde ya está la escucha de respuestas (se anota UNA
     *                  vez por dispatcher: en un worker es uno solo; en los tests cambia con cada test).
     */
    protected static $dispatcher_con_escucha = null;

    /** @var int|null Estado HTTP de la última respuesta de Custom Search que vio la escucha. */
    protected static $ultimo_estado_de_custom_search = null;

    /**
     * Deja lista la escucha y borra el último estado: llamar justo antes de la búsqueda.
     *
     * Por qué un evento (ResponseReceived del cliente HTTP de Laravel) y no un middleware de Guzzle
     * sobre el cliente del trait: Laravel pone los middlewares propios ADENTRO del que responde los
     * Http::fake, así que en los tests nunca correrían. El evento sale igual con respuestas reales y
     * falsas, y el trait no se toca (lo usan el job viejo y el de categorías).
     *
     * Nunca lanza: sin escucha, el registro queda sin estado HTTP y la búsqueda sigue igual.
     *
     * @return void
     */
    public static function antes_de_buscar_en_custom_search()
    {
        self::$ultimo_estado_de_custom_search = null;

        try {
            $dispatcher = app('events');

            if (self::$dispatcher_con_escucha === $dispatcher) {
                return;
            }

            self::$dispatcher_con_escucha = $dispatcher;

            $dispatcher->listen(ResponseReceived::class, function ($evento) {
                if (isset($evento->request, $evento->response)
                    && strpos((string) $evento->request->url(), 'googleapis.com/customsearch') !== false) {
                    self::$ultimo_estado_de_custom_search = (int) $evento->response->status();
                }
            });
        } catch (\Throwable $e) {
            self::$dispatcher_con_escucha = null;
        }
    }

    /**
     * El estado HTTP de la última respuesta de Custom Search (null si no llegó a responder).
     *
     * @return int|null
     */
    public static function estado_de_custom_search()
    {
        return self::$ultimo_estado_de_custom_search;
    }

    /**
     * Un texto con UTF-8 válido (lo que venga de cabeceras o de un sitio puede traer bytes sueltos
     * que después rompen el json de la respuesta).
     *
     * @param  string $texto
     * @return string
     */
    public static function utf8_valido($texto)
    {
        $texto = (string) $texto;

        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return (string) mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    }

    /**
     * El texto sin credenciales: parámetros de URL con claves (`key=`, `cx=`, `api_key=`, `token=`),
     * claves con forma conocida sueltas (Anthropic `sk-ant-...`, Google `AIza...`), las claves de
     * config tal cual y las que pase quien llama.
     *
     * @param  string|null $texto
     * @param  array       $claves_extra  Claves que config no conoce y que también se tachan tal cual:
     *                                    la de Serper del dueño (claves_del_dueno()) o la que tenga
     *                                    el proveedor que armó el mensaje. Misión serper-en-user-setup.
     * @return string|null
     */
    public static function sin_claves($texto, array $claves_extra = [])
    {
        if (is_null($texto)) {
            return null;
        }

        $limpio = (string) $texto;

        $limpio = (string) preg_replace('/([?&](?:key|cx|api_key|apikey|token|access_token)=)[^&\s"\'<>]*/i', '$1***', $limpio);
        $limpio = (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $limpio);
        $limpio = (string) preg_replace('/AIza[0-9A-Za-z_\-]{10,}/', 'AIza***', $limpio);

        $claves = [];

        foreach (array_merge([
            config('services.serper.api_key'),
            config('services.anthropic.api_key'),
            config('services.google_search.api_key'),
            config('services.openai.api_key'),
        ], $claves_extra) as $clave) {
            // Una clave que no es texto no puede estar en el mensaje.
            if (!is_null($clave) && !is_scalar($clave)) {
                continue;
            }

            $clave = trim((string) $clave);

            // Una "clave" de menos de 8 caracteres es un valor de prueba o basura: reemplazarla
            // taparía pedazos de palabras comunes del mensaje.
            if (strlen($clave) >= 8) {
                $claves[$clave] = strlen($clave);
            }
        }

        // De la más larga a la más corta (misión serper-en-user-setup, con varias claves de un
        // payload): si una clave está contenida en otra y se tapara primero la corta, de la larga
        // quedaría a la vista el pedazo que no coincide.
        arsort($claves);

        foreach (array_keys($claves) as $clave) {
            $limpio = str_replace((string) $clave, '***', $limpio);
        }

        return $limpio;
    }

    /**
     * Las claves propias de un dueño que sin_claves() tiene que tachar además de las de config: hoy,
     * su clave de Serper (users.serper_api_key, misión serper-en-user-setup, 28/9/2026). Con ella
     * busca el proveedor (ImageSearchProviderFactory::clave_serper_para()) y config no la conoce.
     *
     * Lee la base (una consulta por id) y por eso se llama solo cuando hay un texto que limpiar: un
     * error, no cada búsqueda que salió bien.
     *
     * Nunca lanza: si la columna todavía no existe (el código llegó antes que la migración) o la
     * base falla, devuelve [] y quedan tachadas las de config, como antes. Nunca devuelve una clave
     * de menos de 8 caracteres (sin_claves() tampoco la reemplazaría).
     *
     * @param  int|null $user_id  El DUEÑO (el user_id de la asignación o del registro).
     * @return array
     */
    public static function claves_del_dueno($user_id)
    {
        try {
            $user_id = (int) $user_id;

            if ($user_id <= 0) {
                return [];
            }

            $clave = trim((string) DB::table('users')->where('id', $user_id)->value('serper_api_key'));

            return strlen($clave) >= 8 ? [$clave] : [];
        } catch (\Throwable $e) {
            // El mensaje de una consulta que falló trae el SQL, no la clave (se la está leyendo, no
            // se la manda): puede ir al log y dice por qué falló ("Unknown column", conexión...).
            Log::warning('[ImagenesInteligentes] No se pudo leer la clave de Serper del dueño para taparla: se tapan solo las del servidor.', [
                'user_id' => $user_id,
                'error'   => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ¿El mensaje de una excepción de conexión es un tiempo de espera agotado? (cURL error 28 /
     * "timed out"). Lo usan los proveedores de búsqueda y la IA para decirle al usuario "no respondió
     * a tiempo" en vez del mensaje crudo de cURL (plan §13, S2: el crudo va solo al log).
     *
     * @param  string|null $mensaje
     * @return bool
     */
    public static function es_timeout($mensaje)
    {
        $mensaje = strtolower((string) $mensaje);

        return strpos($mensaje, 'curl error 28') !== false
            || strpos($mensaje, 'timed out') !== false
            || strpos($mensaje, 'timeout') !== false;
    }

    /**
     * Milisegundos transcurridos desde un microtime(true).
     *
     * @param  float $inicio
     * @return int
     */
    public static function milisegundos_desde($inicio)
    {
        return (int) max(0, round((microtime(true) - (float) $inicio) * 1000));
    }

    /**
     * @param  array  $datos
     * @param  string $clave
     * @return int|null
     */
    protected static function entero_o_null(array $datos, $clave)
    {
        return isset($datos[$clave]) && is_numeric($datos[$clave]) && (int) $datos[$clave] > 0
            ? (int) $datos[$clave]
            : null;
    }

    /**
     * Un entero entre 0 y el máximo de su columna, o null si no vino.
     *
     * @param  array  $datos
     * @param  string $clave
     * @param  int    $maximo
     * @return int|null
     */
    protected static function entero_acotado(array $datos, $clave, $maximo)
    {
        if (!isset($datos[$clave]) || !is_numeric($datos[$clave])) {
            return null;
        }

        return (int) min($maximo, max(0, (int) $datos[$clave]));
    }

    /**
     * Un texto recortado al largo de su columna, o null si no vino o vino vacío.
     *
     * @param  array  $datos
     * @param  string $clave
     * @param  int    $largo
     * @return string|null
     */
    protected static function texto_o_null(array $datos, $clave, $largo)
    {
        if (!isset($datos[$clave]) || !is_scalar($datos[$clave])) {
            return null;
        }

        $texto = trim((string) $datos[$clave]);

        return $texto === '' ? null : mb_substr($texto, 0, $largo);
    }

    /**
     * Un estado HTTP válido (100..599), o null.
     *
     * @param  array $datos
     * @return int|null
     */
    protected static function estado_http(array $datos)
    {
        if (!isset($datos['http_status']) || !is_numeric($datos['http_status'])) {
            return null;
        }

        $estado = (int) $datos['http_status'];

        return $estado >= 100 && $estado <= 599 ? $estado : null;
    }

    /**
     * Un contador de tokens del bloque `usage` (0 si no vino).
     *
     * @param  array  $usage
     * @param  string $clave
     * @return int
     */
    protected static function token(array $usage, $clave)
    {
        return isset($usage[$clave]) && is_numeric($usage[$clave]) ? max(0, (int) $usage[$clave]) : 0;
    }
}
