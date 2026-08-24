<?php

namespace App\Http\Controllers\Helpers;

use App\Models\AiTokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * Registro del consumo de tokens de las llamadas a la API de Anthropic.
 *
 * Regla de oro: registrar() NUNCA lanza. El metering es contabilidad de
 * fondo; una falla acá no puede voltear una respuesta que el usuario ya está
 * esperando (ni un resumen, ni un mensaje del chat, ni un título). Todo va
 * adentro de un try/catch que degrada a Log::warning.
 *
 * Sin UI en esta misión: la tabla ai_token_usages junta números para que
 * Lucas decida después con datos reales (hoy la IA va incluida en la
 * suscripción).
 */
class AiTokenUsageHelper
{
    /**
     * Graba una fila de consumo a partir de la respuesta de Anthropic.
     *
     * Claves aceptadas en $datos:
     * - user_id            (int, obligatorio: sin dueño no hay a quién imputar y no se graba)
     * - proceso            (string, obligatorio: 'chat_mensaje' | 'chat_titulo' | 'resumen_sugerencia_stock' | ...)
     * - body               (array, opcional: la respuesta JSON completa; de acá salen usage y model)
     * - usage              (array, opcional: el bloque usage suelto; pisa al de body)
     * - modelo             (string, opcional: pisa al model de body)
     * - auth_user_id       (int, opcional: la persona; null si lo disparó un job automático)
     * - ai_conversation_id (int, opcional)
     * - referencia_id      (int, opcional: p. ej. stock_suggestions.id)
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

            AiTokenUsage::create([
                'user_id'      => $user_id,
                'auth_user_id' => isset($datos['auth_user_id']) && ! is_null($datos['auth_user_id'])
                    ? (int) $datos['auth_user_id']
                    : null,

                // Recortes a la longitud de columna: el metering jamás puede romper por un string largo.
                'proceso' => mb_substr($proceso, 0, 40),
                'modelo'  => mb_substr($modelo, 0, 80),

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
}
