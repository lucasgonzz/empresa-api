<?php

namespace App\Services\ImageAssignment;

use App\Models\ImageServiceCall;
use Carbon\Carbon;
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
 * lee el admin. Los proveedores ya tapan su propia clave; esto es la segunda red.
 */
class ImageServiceCallLogger
{
    /**
     * Filas que se borran como mucho en una purga: acota lo que tarda crear una asignación la
     * primera vez que se purga una tabla con mucha historia (lo que quede sale en la próxima).
     */
    const MAXIMO_A_PURGAR_POR_VEZ = 20000;

    /** Orígenes y tipos válidos (lo demás se graba con el valor por defecto). */
    const ORIGENES = [ImageServiceCall::ORIGEN_ASIGNACION, ImageServiceCall::ORIGEN_VALIDACION_INDIVIDUAL];
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
                // una clave, quedaría un pedazo de ella que ya nadie reconoce.
                'error'       => isset($datos['error']) && !is_null($datos['error']) && trim((string) $datos['error']) !== ''
                    ? mb_substr(self::sin_claves((string) $datos['error']), 0, 500)
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
     * Borra las consultas de más de DIAS_DE_RETENCION días. Se llama al crear una asignación.
     *
     * Por dueño y no con un DELETE por fecha sobre la tabla entera: el índice es (user_id,
     * created_at), así que un borrado por fecha sola recorrería la tabla completa cada vez; por
     * dueño cada DELETE usa el índice. Los dueños salen del mismo índice (DISTINCT user_id). Y se
     * purgan TODOS los dueños de la base, no solo el que crea la asignación: los que solo usan la
     * búsqueda por código del asistente (validacion_individual) nunca crean una.
     *
     * Nunca lanza: si falla, la asignación se crea igual y la purga sale en la próxima.
     *
     * @return int  Filas borradas.
     */
    public static function purgar_viejas()
    {
        try {
            $limite   = Carbon::now()->subDays(ImageServiceCall::DIAS_DE_RETENCION);
            $borradas = 0;

            $duenos = DB::table('image_service_calls')->select('user_id')->distinct()->pluck('user_id');

            foreach ($duenos as $user_id) {
                $restantes = self::MAXIMO_A_PURGAR_POR_VEZ - $borradas;

                if ($restantes <= 0) {
                    break;
                }

                $borradas += (int) DB::table('image_service_calls')
                    ->where('user_id', (int) $user_id)
                    ->where('created_at', '<', $limite)
                    ->limit($restantes)
                    ->delete();
            }

            return $borradas;
        } catch (\Throwable $e) {
            Log::warning('[ImagenesInteligentes] No se pudo purgar image_service_calls: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * El texto sin credenciales: parámetros de URL con claves (`key=`, `cx=`, `api_key=`, `token=`),
     * claves con forma conocida sueltas (Anthropic `sk-ant-...`, Google `AIza...`) y las claves de
     * config tal cual.
     *
     * @param  string|null $texto
     * @return string|null
     */
    public static function sin_claves($texto)
    {
        if (is_null($texto)) {
            return null;
        }

        $limpio = (string) $texto;

        $limpio = (string) preg_replace('/([?&](?:key|cx|api_key|apikey|token|access_token)=)[^&\s"\'<>]*/i', '$1***', $limpio);
        $limpio = (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-***', $limpio);
        $limpio = (string) preg_replace('/AIza[0-9A-Za-z_\-]{10,}/', 'AIza***', $limpio);

        $claves = [
            config('services.serper.api_key'),
            config('services.anthropic.api_key'),
            config('services.google_search.api_key'),
            config('services.openai.api_key'),
        ];

        foreach ($claves as $clave) {
            $clave = trim((string) $clave);

            // Una "clave" de menos de 8 caracteres es un valor de prueba o basura: reemplazarla
            // taparía pedazos de palabras comunes del mensaje.
            if (strlen($clave) >= 8) {
                $limpio = str_replace($clave, '***', $limpio);
            }
        }

        return $limpio;
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
