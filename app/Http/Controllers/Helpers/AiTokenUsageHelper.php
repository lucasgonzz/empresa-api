<?php

namespace App\Http\Controllers\Helpers;

use App\Models\AiTokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * Registro del consumo de tokens de las llamadas a las APIs de IA
 * (Anthropic y, desde la misión tokens-por-cliente, también OpenAI).
 *
 * Regla de oro: registrar() NUNCA lanza. El metering es contabilidad de
 * fondo; una falla acá no puede voltear una respuesta que el usuario ya está
 * esperando (ni un resumen, ni un mensaje del chat, ni un título). Todo va
 * adentro de un try/catch que degrada a Log::warning.
 *
 * La tabla ai_token_usages dejó de ser write-only: la lee
 * AdminSync\ConsumoIaController, que es de donde el admin trae el consumo de
 * cada cliente para mostrarlo con su costo estimado.
 */
class AiTokenUsageHelper
{
    /** Proveedor que se asume cuando el llamador no dice cuál es. */
    const PROVEEDOR_POR_DEFECTO = 'anthropic';

    /**
     * Los cuatro contadores que se esperan en el bloque `usage`. Si no viene NINGUNO, la fila
     * se graba igual pero con un warning: ver el comentario de `avisar_si_el_usage_vino_vacio()`.
     */
    const CLAVES_DE_USAGE = [
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
    ];

    /**
     * Graba una fila de consumo a partir de la respuesta de la API de IA.
     *
     * Claves aceptadas en $datos:
     * - user_id            (int, obligatorio: sin dueño no hay a quién imputar y no se graba)
     * - proceso            (string, obligatorio: 'chat_mensaje' | 'chat_titulo' | 'resumen_sugerencia_stock' | ...)
     * - body               (array, opcional: la respuesta JSON completa; de acá salen usage y model)
     * - usage              (array, opcional: el bloque usage suelto; pisa al de body)
     * - modelo             (string, opcional: pisa al model de body)
     * - proveedor          (string, opcional: 'anthropic' por defecto; 'openai' para los embeddings)
     * - auth_user_id       (int, opcional: la persona; null si lo disparó un job automático)
     * - ai_conversation_id (int, opcional)
     * - referencia_id      (int, opcional: p. ej. stock_suggestions.id)
     *
     * Sobre `proveedor`: tiene default porque los 9 llamadores que existían antes de la
     * misión tokens-por-cliente son todos de Anthropic y no lo pasan. No se deduce del
     * nombre del modelo a propósito — ver el comentario de la migración.
     *
     * @param  array  $datos
     * @return void
     */
    public static function registrar(array $datos)
    {
        try {
            $user_id = isset($datos['user_id']) ? (int) $datos['user_id'] : 0;
            $proceso = isset($datos['proceso']) ? trim((string) $datos['proceso']) : '';

            if ($user_id <= 0 || $proceso === '') {
                // Una fila sin dueño o sin proceso es basura contable: mejor un warning que un registro mudo.
                Log::warning('AiTokenUsageHelper::registrar - llamada sin user_id o sin proceso, no se graba.', [
                    'user_id' => $user_id,
                    'proceso' => $proceso,
                ]);

                return;
            }

            $body = isset($datos['body']) && is_array($datos['body']) ? $datos['body'] : [];

            $usage = [];
            if (isset($datos['usage']) && is_array($datos['usage'])) {
                $usage = $datos['usage'];
            } elseif (isset($body['usage']) && is_array($body['usage'])) {
                $usage = $body['usage'];
            }

            $modelo = '';
            if (isset($datos['modelo']) && (string) $datos['modelo'] !== '') {
                $modelo = (string) $datos['modelo'];
            } elseif (isset($body['model'])) {
                $modelo = (string) $body['model'];
            }

            $proveedor = isset($datos['proveedor']) ? trim((string) $datos['proveedor']) : '';
            if ($proveedor === '') {
                $proveedor = self::PROVEEDOR_POR_DEFECTO;
            }

            self::avisar_si_el_usage_vino_vacio($usage, $proceso, $proveedor);

            AiTokenUsage::create([
                'user_id'      => $user_id,
                'auth_user_id' => isset($datos['auth_user_id']) && ! is_null($datos['auth_user_id'])
                    ? (int) $datos['auth_user_id']
                    : null,

                // Recortes a la longitud de columna: el metering jamás puede romper por un string largo.
                'proceso'   => mb_substr($proceso, 0, 40),
                'proveedor' => mb_substr($proveedor, 0, 20),
                'modelo'    => mb_substr($modelo, 0, 80),

                'input_tokens'                => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0,
                'output_tokens'               => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0,
                'cache_creation_input_tokens' => isset($usage['cache_creation_input_tokens']) ? (int) $usage['cache_creation_input_tokens'] : 0,
                'cache_read_input_tokens'     => isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : 0,

                'ai_conversation_id' => isset($datos['ai_conversation_id']) && ! is_null($datos['ai_conversation_id'])
                    ? (int) $datos['ai_conversation_id']
                    : null,
                'referencia_id' => isset($datos['referencia_id']) && ! is_null($datos['referencia_id'])
                    ? (int) $datos['referencia_id']
                    : null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('AiTokenUsageHelper::registrar - no se pudo registrar el consumo de tokens.', [
                'error'   => $exception->getMessage(),
                'proceso' => isset($datos['proceso']) ? $datos['proceso'] : null,
                'user_id' => isset($datos['user_id']) ? $datos['user_id'] : null,
            ]);
        }
    }

    /**
     * Deja un warning cuando el bloque `usage` no trajo NINGUNO de los cuatro contadores.
     *
     * 🔴 POR QUÉ ESTO NO ES RUIDO. La fila se graba igual, con los cuatro contadores en cero —
     * y ese es justamente el problema: un cero es indistinguible de un comercio que no usó la
     * IA. Si mañana una de las dos APIs deja de mandar `usage`, o le cambia el nombre a las
     * claves, el síntoma no va a ser un error: va a ser que el gasto de TODOS los clientes baja
     * a cero de un día para el otro, en silencio, y que el admin muestra costo 0 sin que nada
     * lo denuncie. Esta línea es la diferencia entre una falla muda y una que se encuentra
     * buscando en el log.
     *
     * No se pregunta por las cuatro: las dos de caché faltan legítimamente en muchas respuestas
     * de Anthropic, y el mapeo de OpenAI llena las cuatro a mano (ahí el guard equivalente vive
     * en `ArticleEmbeddingService::registrar_consumo()`, que es donde todavía se puede ver el
     * `prompt_tokens` original). El umbral es "ninguna de las cuatro".
     *
     * @param  array   $usage
     * @param  string  $proceso
     * @param  string  $proveedor
     * @return void
     */
    protected static function avisar_si_el_usage_vino_vacio(array $usage, $proceso, $proveedor)
    {
        foreach (self::CLAVES_DE_USAGE as $clave) {

            if (array_key_exists($clave, $usage)) {

                return;
            }
        }

        Log::warning(
            'AiTokenUsageHelper::registrar - la respuesta vino sin bloque usage: se graba una fila en cero. '
            . 'Si esto se repite, el consumo de este proceso quedó sin medir.',
            [
                'proceso'         => $proceso,
                'proveedor'       => $proveedor,
                'claves_recibidas' => array_keys($usage),
            ]
        );
    }
}
