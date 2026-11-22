<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\WhatsappReactionHelper;
use App\Models\AiMessage;
use Carbon\Carbon;

/**
 * La valoración del dueño sobre una respuesta del agente personal (misión agente-mejoras-uso-real,
 * 29/10/2026): me gusta / no me gusta y, con no me gusta, el comentario de por qué o qué mejorar.
 *
 * Pedido de Lucas: "que el usuario le pueda poner me gusta o no me gusta a los mensajes, y si le pone
 * no me gusta, que pueda explicar por qué o qué le gustaría mejorar", desde el chat del sistema y desde
 * ia.comerciocity.com. El análisis diario de las 22 h lee estas columnas y lista cada no me gusta.
 *
 * Reglas:
 *   - Solo se valoran las respuestas del agente (rol `assistant`): valorar lo que escribió el dueño no
 *     tiene sentido y la interfaz no lo ofrece.
 *   - `valoracion = null` saca la valoración (volver a tocar el mismo botón, como en ChatGPT) y borra el
 *     comentario.
 *   - Con `me_gusta` el comentario se borra: la interfaz solo pide comentario con no me gusta, y un
 *     comentario viejo de un no me gusta que el dueño cambió de opinión ya no describe lo que piensa.
 */
class ValoracionIaHelper
{
    /** Le gustó la respuesta. */
    const ME_GUSTA = 'me_gusta';

    /** No le gustó: puede venir con comentario. */
    const NO_ME_GUSTA = 'no_me_gusta';

    /** Largo máximo del comentario (lo corta la interfaz; acá es la defensa). */
    const MAX_COMENTARIO = 1000;

    /** La valoración la puso el dueño desde el chat del sistema o la app (PUT api/ai-mensajes/{id}/valoracion). */
    const ORIGEN_SISTEMA = 'sistema';

    /** La valoración salió de una reacción del dueño por WhatsApp (misión asistente-reacciones-wa). */
    const ORIGEN_REACCION = 'reaccion_whatsapp';

    /**
     * Valida lo que llega del request. Devuelve null si está bien, o el código de error para el 422.
     *
     * @param  mixed  $valoracion  me_gusta | no_me_gusta | null.
     * @param  mixed  $comentario  Texto libre o null.
     * @return string|null
     */
    public static function error_de_validacion($valoracion, $comentario)
    {
        if (!is_null($valoracion) && !in_array($valoracion, [self::ME_GUSTA, self::NO_ME_GUSTA], true)) {
            return 'valoracion_invalida';
        }

        if (!is_null($comentario) && !is_string($comentario)) {
            return 'comentario_invalido';
        }

        if (is_string($comentario) && mb_strlen($comentario, 'UTF-8') > self::MAX_COMENTARIO) {
            return 'comentario_muy_largo';
        }

        return null;
    }

    /**
     * Guarda la valoración en el mensaje del agente.
     *
     * @param  AiMessage  $mensaje  Respuesta del agente (rol assistant), ya validada su tenencia.
     * @param  string|null  $valoracion  me_gusta | no_me_gusta | null para sacarla.
     * @param  string|null  $comentario  Comentario del no me gusta (se ignora en los otros casos).
     * @return AiMessage
     */
    public static function valorar(AiMessage $mensaje, $valoracion, $comentario)
    {
        // El comentario solo vive con un no me gusta; vacío o con espacios cuenta como sin comentario.
        $comentario_limpio = is_string($comentario) ? trim($comentario) : '';

        $mensaje->valoracion = $valoracion;
        $mensaje->valoracion_comentario = ($valoracion === self::NO_ME_GUSTA && $comentario_limpio !== '')
            ? $comentario_limpio
            : null;
        $mensaje->valorado_at = is_null($valoracion) ? null : Carbon::now();
        /*
         * Quién la puso (misión asistente-reacciones-wa): lo que se escribe desde el sistema es `sistema`, y una
         * reacción de WhatsApp no puede pisar el comentario que el dueño escribió acá. Al sacar la valoración el
         * origen también se limpia.
         */
        $mensaje->valoracion_origen = is_null($valoracion) ? null : self::ORIGEN_SISTEMA;

        /*
         * Sin tocar updated_at: el polling de la SPA y de la app compara mensajes y un updated_at nuevo
         * no cambia nada de lo que el agente respondió.
         */
        $mensaje->timestamps = false;
        $mensaje->save();
        $mensaje->timestamps = true;

        return $mensaje;
    }

    /**
     * Aplica la reacción de WhatsApp del dueño (👍 / 👎 / 😡 …) a una respuesta del asistente.
     *
     * Tabla (misión asistente-reacciones-wa, §2.3 del plan):
     *   - Valoración del SISTEMA con comentario escrito por el dueño → no se toca, sea cual sea la reacción: lo
     *     que escribió a mano vale más que un emoji (`valoracion_del_sistema`). Una fila vieja con valoración y
     *     origen null cuenta como del sistema (protección conservadora).
     *   - Reacción negativa → `no_me_gusta`; positiva → `me_gusta`; con el comentario «reacción <emoji> por
     *     WhatsApp», `valorado_at` ahora y origen `reaccion_whatsapp`. Reemplaza una valoración de reacción
     *     anterior o una del sistema SIN comentario.
     *   - Quitada o neutra → limpia la valoración SI era de reacción (WhatsApp admite una sola reacción por
     *     persona y mensaje: cambiar 👎 por 😂 es retirar el 👎); si no había nada o era del sistema, no hace nada.
     *
     * @param  AiMessage  $mensaje  Respuesta del asistente (rol assistant) ya resuelta dentro del dueño.
     * @param  string  $emoji  Emoji tal como llegó ('' = la quitó).
     * @return array  `aplicada` (bool) y, si no se aplicó, `motivo` (`valoracion_del_sistema` | `sin_cambios`).
     */
    public static function valorar_por_reaccion(AiMessage $mensaje, $emoji)
    {
        $es_del_sistema = $mensaje->valoracion_origen === self::ORIGEN_SISTEMA
            || (is_null($mensaje->valoracion_origen) && !is_null($mensaje->valoracion));

        $tiene_comentario = trim((string) $mensaje->valoracion_comentario) !== '';

        if ($es_del_sistema && $tiene_comentario) {
            return ['aplicada' => false, 'motivo' => 'valoracion_del_sistema'];
        }

        $clase = WhatsappReactionHelper::classify($emoji);

        if ($clase === WhatsappReactionHelper::NEGATIVA || $clase === WhatsappReactionHelper::POSITIVA) {
            $mensaje->valoracion = $clase === WhatsappReactionHelper::NEGATIVA ? self::NO_ME_GUSTA : self::ME_GUSTA;
            $mensaje->valoracion_comentario = WhatsappReactionHelper::comment_for($emoji);
            $mensaje->valorado_at = Carbon::now();
            $mensaje->valoracion_origen = self::ORIGEN_REACCION;
        } elseif ($mensaje->valoracion_origen === self::ORIGEN_REACCION) {
            $mensaje->valoracion = null;
            $mensaje->valoracion_comentario = null;
            $mensaje->valorado_at = null;
            $mensaje->valoracion_origen = null;
        } else {
            return ['aplicada' => false, 'motivo' => 'sin_cambios'];
        }

        // Sin tocar updated_at, igual que `valorar()`: el polling compara mensajes y esto no cambia lo respondido.
        $mensaje->timestamps = false;
        $mensaje->save();
        $mensaje->timestamps = true;

        return ['aplicada' => true];
    }

    /**
     * Lo que devuelve el endpoint: solo los campos de la valoración (la interfaz ya tiene el resto).
     *
     * @param  AiMessage  $mensaje
     * @return array
     */
    public static function para_respuesta(AiMessage $mensaje)
    {
        return [
            'id'                    => $mensaje->id,
            'valoracion'            => $mensaje->valoracion,
            'valoracion_comentario' => $mensaje->valoracion_comentario,
            // Mismo formato que en el listado de mensajes (serialización de Laravel: ISO 8601 en UTC).
            'valorado_at'           => $mensaje->valorado_at ? $mensaje->valorado_at->toJSON() : null,
        ];
    }
}
