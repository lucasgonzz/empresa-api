<?php

namespace App\Http\Controllers\Helpers;

use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChatMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Las reacciones de WhatsApp (👍 / 👎 / 😡 …) como valoración de las respuestas de los agentes (misión
 * asistente-reacciones-wa, 22/11/2026).
 *
 * Pedido de Lucas: «quiero que los usuarios puedan reaccionar por WhatsApp a los mensajes del asistente, y si
 * reaccionan con un pulgar para abajo, con una cara enojada, quiero que analices ese mensaje para ver por qué no
 * les gustó y lo mejores». Este helper es lo que comparten los dos agentes:
 *
 *   - clasificar el emoji (`classify`) y limpiarlo de variantes (`normalize_emoji`);
 *   - leer la reacción del payload de Kapso (`extract_reaction`), en el formato nativo y en el legado;
 *   - aplicarla a la respuesta del agente que atiende a los CLIENTES del comercio (`apply_to_agent_message`).
 *
 * La del asistente personal del dueño la aplica `asistente_ia\ReaccionDelAsistenteHelper`, que usa la
 * clasificación de acá.
 *
 * 🔴 UNA REACCIÓN NUNCA ES UN MENSAJE. El webhook la desvía ANTES de `parse_inbound_message()` y de
 * `process_inbound()`: no crea mensaje entrante, no toca el chat y no despierta al agente.
 */
class WhatsappReactionHelper
{
    /** Clasificación: el emoji desaprueba la respuesta. */
    const NEGATIVA = 'negativa';

    /** Clasificación: el emoji aprueba la respuesta. */
    const POSITIVA = 'positiva';

    /** Clasificación: un emoji que no mide nada (risa, empatía, sorpresa, duda…). */
    const NEUTRA = 'neutra';

    /** Clasificación: la persona sacó la reacción (emoji vacío). */
    const QUITADA = 'quitada';

    /** Valores que se guardan en `valoracion`. */
    const ME_GUSTA = 'me_gusta';
    const NO_ME_GUSTA = 'no_me_gusta';

    /**
     * Emojis que cuentan como «no me gusta». Es criterio, no ciencia: ajustable acá, y el test unitario lo fija.
     * 😢 y 😮 NO están a propósito: vienen en la paleta por defecto de WhatsApp y se usan como empatía o
     * sorpresa, no como desaprobación.
     */
    const NEGATIVE_EMOJIS = [
        '👎', '😡', '😠', '🤬', '😤', '👿', '💢', '🙄', '😒', '😑', '😞', '😔', '😕', '🙁', '☹', '😩', '😫',
        '😖', '😣', '🤮', '🤢', '💩', '🤦', '❌', '🚫', '👊',
    ];

    /** Emojis que cuentan como «me gusta». */
    const POSITIVE_EMOJIS = [
        '👍', '❤', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💖', '💗', '💕', '😍', '🥰', '😘', '🙏',
        '👏', '🙌', '💪', '👌', '✅', '✔', '🔥', '💯', '⭐', '🌟', '😊', '😁', '😀', '😃', '😄', '😎', '🤩',
        '🥳', '🤗', '☺',
    ];

    /**
     * Deja al emoji en su base: sin espacios, sin selectores de variación (U+FE0F / U+FE0E), sin modificadores
     * de tono de piel (U+1F3FB–U+1F3FF) y sin el sufijo de género (ZWJ + ♀/♂). Así 👎🏽, ❤️ / ❤ y 🤦‍♂️ caen en
     * su emoji base.
     *
     * @param  string|null  $emoji  Emoji tal como llegó.
     * @return string  El emoji base ('' si no había nada).
     */
    public static function normalize_emoji($emoji)
    {
        if (is_null($emoji)) {
            return '';
        }

        // Espacios (también los unicode) de las puntas.
        $base = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $emoji);

        // El sufijo de género (ZWJ + ♀/♂, con su selector) va primero: sin el ZWJ queda un ♂ suelto.
        $base = preg_replace('/\x{200D}[\x{2640}\x{2642}]\x{FE0F}?/u', '', (string) $base);

        // Selectores de variación y tonos de piel.
        $base = preg_replace('/[\x{FE0F}\x{FE0E}\x{1F3FB}-\x{1F3FF}]/u', '', (string) $base);

        return is_null($base) ? '' : (string) $base;
    }

    /**
     * Qué dice la reacción sobre la respuesta.
     *
     * @param  string|null  $emoji  Emoji tal como llegó ('' = la persona sacó la reacción).
     * @return string  'negativa' | 'positiva' | 'neutra' | 'quitada'.
     */
    public static function classify($emoji)
    {
        $base = self::normalize_emoji($emoji);

        if ($base === '') {
            return self::QUITADA;
        }

        // Las listas también se normalizan: así no importa si la fuente trae o no el selector de variación.
        if (in_array($base, array_map([self::class, 'normalize_emoji'], self::NEGATIVE_EMOJIS), true)) {
            return self::NEGATIVA;
        }

        if (in_array($base, array_map([self::class, 'normalize_emoji'], self::POSITIVE_EMOJIS), true)) {
            return self::POSITIVA;
        }

        return self::NEUTRA;
    }

    /**
     * El comentario que queda en la valoración: lleva el emoji tal como llegó (sin normalizar).
     *
     * @param  string  $emoji
     * @return string
     */
    public static function comment_for($emoji)
    {
        return 'reacción ' . $emoji . ' por WhatsApp';
    }

    /**
     * Lee la reacción del payload de Kapso. Reconoce el formato nativo (`message.type = reaction`) y los tres
     * textos legados que Kapso usa a veces («Reacted with 👍 to message wamid.…», «Reacted 👍 to message
     * wamid.…», «Removed reaction from message wamid.…»; los mismos que ya entiende el admin para los leads).
     *
     * @param  array  $payload  Payload completo del webhook.
     * @return array|null  null si no es una reacción; si lo es: `from` (teléfono tal como llegó),
     *                     `target_wa_message_id` (wamid reaccionado), `emoji` ('' = la quitó) y
     *                     `reaction_wa_message_id` (wamid del evento, o null).
     */
    public static function extract_reaction(array $payload)
    {
        $message = isset($payload['message']) ? $payload['message'] : null;
        if (!is_array($message)) {
            return null;
        }

        // Teléfono de quien reacciona: el del mensaje y, si no viene, el de la conversación.
        $from = isset($message['from']) ? trim((string) $message['from']) : '';
        if ($from === '' && isset($payload['conversation']['phone_number'])) {
            $from = trim((string) $payload['conversation']['phone_number']);
        }

        $reaction_wa_message_id = isset($message['id']) && trim((string) $message['id']) !== ''
            ? trim((string) $message['id'])
            : null;

        $type = isset($message['type']) ? strtolower(trim((string) $message['type'])) : '';

        // Formato nativo.
        if ($type === 'reaction' && isset($message['reaction']) && is_array($message['reaction'])) {
            $target = isset($message['reaction']['message_id']) ? trim((string) $message['reaction']['message_id']) : '';

            if ($target !== '') {
                return [
                    'from'                   => $from,
                    'target_wa_message_id'   => $target,
                    'emoji'                  => isset($message['reaction']['emoji']) ? (string) $message['reaction']['emoji'] : '',
                    'reaction_wa_message_id' => $reaction_wa_message_id,
                ];
            }
        }

        // Formato legado: el texto vive en `kapso.content` o en el cuerpo del texto.
        $textos = [];
        if (isset($message['kapso']['content']) && is_string($message['kapso']['content'])) {
            $textos[] = $message['kapso']['content'];
        }
        if ($type === 'text' && isset($message['text']['body']) && is_string($message['text']['body'])) {
            $textos[] = $message['text']['body'];
        }

        foreach ($textos as $texto) {
            $legada = self::parse_legacy_text($texto);

            if (!is_null($legada)) {
                return [
                    'from'                   => $from,
                    'target_wa_message_id'   => $legada['target'],
                    'emoji'                  => $legada['emoji'],
                    'reaction_wa_message_id' => $reaction_wa_message_id,
                ];
            }
        }

        return null;
    }

    /**
     * Interpreta el texto legado de una reacción. Está anclado al principio y al final: un mensaje común que
     * nombra una reacción («te mandé un reacted with…») no es una reacción.
     *
     * @param  string  $texto
     * @return array|null  `emoji` y `target`, o null si el texto no es una reacción.
     */
    protected static function parse_legacy_text($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        // «Reacted with 👍 to message wamid.…» y la variante sin «with».
        if (preg_match('/^Reacted(?: with)? (.+) to message (wamid\.\S+)$/iu', $texto, $coincidencias)) {
            return ['emoji' => trim($coincidencias[1]), 'target' => trim($coincidencias[2])];
        }

        // «Removed reaction from message wamid.…»: sacó la reacción.
        if (preg_match('/^Removed reaction from message (wamid\.\S+)$/iu', $texto, $coincidencias)) {
            return ['emoji' => '', 'target' => trim($coincidencias[1])];
        }

        return null;
    }

    /**
     * Aplica la reacción de un cliente a la respuesta del agente de WhatsApp del comercio.
     *
     * Sólo cuenta si el wamid reaccionado es un saliente del agente (`source = ia`, `direction = out`) de un chat
     * de ESTE comercio (el de la configuración) y de ESTE teléfono: sólo el cliente de ese chat puede valorar,
     * y nunca un mensaje de un empleado (`manual`, `plantilla`): la valoración mide al agente, no a la persona.
     * Acá no hay valoración del sistema (no existe pantalla para ponerla), así que toda valoración de esta tabla
     * es de reacción y se puede reemplazar o limpiar sin más.
     *
     *   negativa → no_me_gusta · positiva → me_gusta · neutra o quitada → se limpia (WhatsApp admite UNA
     *   reacción por persona y mensaje: cambiar 👎 por 😂 es retirar el 👎).
     *
     * @param  WhatsappBotConfig  $config  Configuración activa del bot (define el comercio).
     * @param  array  $reaction  Lo que devuelve `extract_reaction()`.
     * @return WhatsappChatMessage|null  El mensaje valorado, o null si la reacción no era para el agente.
     */
    public static function apply_to_agent_message(WhatsappBotConfig $config, array $reaction)
    {
        $telefono = WhatsappPhoneHelper::normalize($reaction['from']);

        $mensaje = WhatsappChatMessage::where('wa_message_id', $reaction['target_wa_message_id'])
            ->where('direction', 'out')
            ->where('source', 'ia')
            ->whereHas('whatsapp_chat', function ($query) use ($config, $telefono) {
                $query->where('user_id', $config->user_id)->where('phone', $telefono);
            })
            ->first();

        if (is_null($mensaje)) {
            Log::channel('daily')->info('WhatsappReactionHelper: reacción a un mensaje que no es del agente; se ignora.', [
                'target' => $reaction['target_wa_message_id'],
            ]);

            return null;
        }

        $clase = self::classify($reaction['emoji']);

        if ($clase === self::NEGATIVA || $clase === self::POSITIVA) {
            $mensaje->valoracion = $clase === self::NEGATIVA ? self::NO_ME_GUSTA : self::ME_GUSTA;
            $mensaje->valoracion_comentario = self::comment_for($reaction['emoji']);
            $mensaje->valorado_at = Carbon::now();
        } else {
            $mensaje->valoracion = null;
            $mensaje->valoracion_comentario = null;
            $mensaje->valorado_at = null;
        }

        // Sin tocar updated_at: no cambia nada de lo que el agente respondió ni reordena la conversación.
        $mensaje->timestamps = false;
        $mensaje->save();
        $mensaje->timestamps = true;

        Log::channel('daily')->info('WhatsappReactionHelper: reacción aplicada al mensaje del agente.', [
            'whatsapp_chat_message_id' => $mensaje->id,
            'clasificacion'            => $clase,
        ]);

        return $mensaje;
    }
}
