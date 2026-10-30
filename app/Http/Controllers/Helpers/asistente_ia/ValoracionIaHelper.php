<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

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
         * Sin tocar updated_at: el polling de la SPA y de la app compara mensajes y un updated_at nuevo
         * no cambia nada de lo que el agente respondió.
         */
        $mensaje->timestamps = false;
        $mensaje->save();
        $mensaje->timestamps = true;

        return $mensaje;
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
