<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * La reacción del DUEÑO (👍 / 👎 / 😡 …) a una respuesta de su asistente por WhatsApp (misión
 * asistente-reacciones-wa, 22/11/2026): lógica de `POST api/admin-sync/asistente/reacciones`.
 *
 * La reacción entra por el número de ComercioCity, o sea por el webhook del ADMIN, que sabe el wamid de cada
 * respuesta que mandó (en empresa NO existe: `ai_messages.whatsapp_message_id` guarda el wamid del ENTRANTE del
 * dueño, en las dos filas del turno). El admin reenvía acá la reacción con el wamid reaccionado y, si lo tiene,
 * el `ai_message_id` de la respuesta.
 *
 * Contrato del cuerpo (compatible hacia atrás: todo lo opcional es opcional de verdad):
 *   - `whatsapp_message_id` string ≤ 128 — obligatorio: wamid del mensaje REACCIONADO.
 *   - `emoji` string ≤ 32 — obligatorio, puede ser '' (= quitó la reacción).
 *   - `ai_message_id` int — opcional: el id de la respuesta que el admin ya tenía asociada.
 *   - `reaction_message_id` string ≤ 128 — opcional: wamid del evento de reacción (sólo para el log).
 *
 * 🔴 NUNCA es un turno: no crea `AiMessage`, no despacha al asistente y no toca `ai_conversations.last_message_at`.
 * 🔴 SIEMPRE dentro del dueño: sólo se valoran respuestas (`rol = assistant`) del canal WhatsApp de una
 *    conversación de ESTE dueño; un id de otro dueño o de lo que escribió el propio dueño es «mensaje desconocido».
 */
class ReaccionDelAsistenteHelper
{
    /** Largo máximo del wamid (columna `whatsapp_message_id` string 128). */
    const MAX_WAMID = 128;

    /** Largo máximo del emoji (con tonos de piel y secuencias ZWJ cabe de sobra). */
    const MAX_EMOJI = 32;

    /**
     * Valida el cuerpo. Devuelve null si está bien, o el mensaje del 422.
     *
     * @param  array  $datos  `whatsapp_message_id`, `emoji` (con `array_key_exists`), `ai_message_id`, `reaction_message_id`.
     * @return string|null
     */
    public static function error_de_validacion(array $datos)
    {
        $wamid = isset($datos['whatsapp_message_id']) ? $datos['whatsapp_message_id'] : null;

        if (!is_string($wamid) || trim($wamid) === '' || mb_strlen($wamid, 'UTF-8') > self::MAX_WAMID) {
            return 'Falta whatsapp_message_id (texto de hasta ' . self::MAX_WAMID . ' caracteres).';
        }

        // El emoji vacío es válido (quitó la reacción); lo que no vale es que no venga o que no sea texto.
        // 🔴 Laravel convierte '' en null en el JSON de entrada (ConvertEmptyStringsToNull): null cuenta como ''.
        if (!array_key_exists('emoji', $datos) || (!is_null($datos['emoji']) && !is_string($datos['emoji']))) {
            return 'El emoji tiene que ser texto (vacío si quitó la reacción).';
        }

        if (is_string($datos['emoji']) && mb_strlen($datos['emoji'], 'UTF-8') > self::MAX_EMOJI) {
            return 'El emoji supera los ' . self::MAX_EMOJI . ' caracteres.';
        }

        return null;
    }

    /**
     * Aplica la reacción a la respuesta del asistente que corresponda.
     *
     * @param  User  $dueno  Dueño de la instancia (`AsistenteCanalHelper::dueno()`).
     * @param  array  $datos  Cuerpo ya validado (ver `error_de_validacion`).
     * @return array  `ok`, `aplicada`, y según el caso `motivo` (`mensaje_desconocido` | `valoracion_del_sistema` |
     *                `sin_cambios`), `ai_message_id` y `valoracion`.
     */
    public static function aplicar(User $dueno, array $datos)
    {
        $emoji = is_string($datos['emoji']) ? $datos['emoji'] : '';
        $wamid = trim((string) $datos['whatsapp_message_id']);
        $ai_message_id = isset($datos['ai_message_id']) ? (int) $datos['ai_message_id'] : 0;

        $mensaje = self::resolver_mensaje($dueno, $wamid, $ai_message_id);

        if (is_null($mensaje)) {
            Log::channel('daily')->info('ReaccionDelAsistenteHelper: reacción a un mensaje que no es del asistente; se ignora.', [
                'whatsapp_message_id' => $wamid,
                'ai_message_id'       => $ai_message_id,
                'reaction_message_id' => isset($datos['reaction_message_id']) ? $datos['reaction_message_id'] : null,
            ]);

            return ['ok' => true, 'aplicada' => false, 'motivo' => 'mensaje_desconocido'];
        }

        $resultado = ValoracionIaHelper::valorar_por_reaccion($mensaje, $emoji);

        Log::channel('daily')->info('ReaccionDelAsistenteHelper: reacción del dueño procesada.', [
            'ai_message_id'       => $mensaje->id,
            'aplicada'            => $resultado['aplicada'],
            'motivo'              => isset($resultado['motivo']) ? $resultado['motivo'] : null,
            'reaction_message_id' => isset($datos['reaction_message_id']) ? $datos['reaction_message_id'] : null,
        ]);

        return array_merge(['ok' => true], $resultado, [
            'ai_message_id' => (int) $mensaje->id,
            'valoracion'    => $mensaje->valoracion,
        ]);
    }

    /**
     * Resuelve la respuesta del asistente reaccionada, siempre dentro del dueño.
     *
     * Con `ai_message_id` (> 0) es ese id y nada más: si no es una respuesta del asistente de este dueño, no hay
     * mensaje (no se cae al wamid: un id ajeno es una señal de que algo no cuadra). Sin él, por el wamid, pero
     * descartando las filas cuyo wamid también lo tiene una fila del dueño (`rol = user`) de la misma
     * conversación: ese es el wamid del ENTRANTE del dueño que `guardar_turno` repite en las dos filas, y
     * reaccionar a lo que uno mismo escribió no valora la respuesta. Lo que sí resuelve por wamid son los avisos
     * que el asistente manda solo, que guardan el wamid del saliente.
     *
     * @param  User  $dueno
     * @param  string  $wamid
     * @param  int  $ai_message_id
     * @return AiMessage|null
     */
    protected static function resolver_mensaje(User $dueno, $wamid, $ai_message_id)
    {
        // Sólo respuestas del asistente, del canal de WhatsApp, de una conversación de este dueño.
        $del_dueno = AiMessage::where('rol', 'assistant')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->whereHas('conversation', function ($query) use ($dueno) {
                $query->where('user_id', $dueno->id)->where('auth_user_id', $dueno->id);
            });

        if ($ai_message_id > 0) {
            return $del_dueno->where('id', $ai_message_id)->first();
        }

        $candidatos = $del_dueno->where('whatsapp_message_id', $wamid)->orderBy('id', 'desc')->get();

        foreach ($candidatos as $candidato) {
            $es_el_entrante_del_dueno = AiMessage::where('ai_conversation_id', $candidato->ai_conversation_id)
                ->where('rol', 'user')
                ->where('whatsapp_message_id', $wamid)
                ->exists();

            if (!$es_el_entrante_del_dueno) {
                return $candidato;
            }
        }

        return null;
    }
}
