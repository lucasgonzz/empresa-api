<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Log;

/**
 * EL «SEGUÍ» DESPUÉS DE UN TURNO CORTADO RETOMA LO QUE QUEDÓ PENDIENTE (misión asistente-diario-20261119, rama B, M1).
 *
 * 🔴 EL CASO REAL (Ferre Total, conv 72, 18/11/2026, 723-725). El asistente dijo «Primero confirmame la activación en
 * la tienda de los 145, y después te confirmo el alta de fotos»; Pablo dijo «Si» y el turno registró la masiva…
 * y se cortó con «cURL error 28 … 60000 ms» contra el proveedor de IA. Al dueño le llegó «Quedó hecho: … No llegué a
 * terminar esta respuesta. Escribime «seguí» y sigo con lo que falta.». Las dos fotos (principal y paleta) nunca se
 * cargaron. Con un «seguí», el modelo solo veía ese texto: nada le decía qué era «lo que falta».
 *
 * Lo que hace este helper:
 *   - aplica(): el mensaje que se contesta es un «seguí» corto («seguí», «continuá», «dale, seguí con lo que
 *     falta»), y la respuesta anterior del asistente quedó CORTADA: su `error_mensaje` empieza con «turno sin
 *     terminar» (lo escribe AsistenteIaService::respuesta_sin_terminar() vía el job) o su texto trae el cierre de un
 *     turno sin terminar.
 *   - nota(): le dice al modelo que el turno anterior se cortó, cuál era el pedido que estaba resolviendo (el mensaje
 *     de la persona anterior al corte) y que retome lo anunciado y no hecho, mirando las líneas [Tarjeta …] para no
 *     repetir lo registrado.
 *
 * No ejecuta nada: es una nota. Lo que necesita el sí lo sigue necesitando.
 *
 * Nunca lanza: si algo falla, no hay nota y el turno sigue como antes.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class SeguiTrasUnCorteIaHelper
{
    /** Largo máximo, ya normalizado, de un «seguí». Uno de más casi siempre trae otro pedido adentro. */
    const LARGO_MAXIMO = 40;

    /** Cómo empieza un «seguí», ya normalizado (sin tildes ni signos). */
    const COMIENZOS = ['segui', 'sigue', 'segui nomas', 'continua', 'continuá', 'proseguí', 'segui por favor', 'dale segui', 'dale continua', 'ok segui', 'si segui'];

    /** Lo que identifica en el TEXTO un turno que no terminó (los dos cierres que existen). */
    const MARCAS_DE_CORTE = ['No llegué a terminar esta respuesta'];

    /** El prefijo con el que el job deja el detalle técnico de un turno sin terminar (respuesta_sin_terminar()). */
    const PREFIJO_DEL_DETALLE = 'turno sin terminar';

    /** Largo máximo del pedido que se cita en la nota. */
    const LARGO_DEL_PEDIDO = 400;

    /**
     * La nota para pegarle al «seguí», o null si no corresponde.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant_message  El assistant que se está generando.
     * @return string|null
     */
    public static function nota(AiConversation $conversation, AiMessage $assistant_message)
    {
        try {

            $pedido = AiMessage::where('ai_conversation_id', $conversation->id)
                                ->where('rol', 'user')
                                ->where('id', '<', $assistant_message->id)
                                ->orderBy('id', 'DESC')
                                ->first();

            if (is_null($pedido) || !self::es_segui($pedido->contenido)) {

                return null;
            }

            $cortado = AiMessage::where('ai_conversation_id', $conversation->id)
                                ->where('rol', 'assistant')
                                ->where('id', '<', $pedido->id)
                                ->orderBy('id', 'DESC')
                                ->first();

            if (is_null($cortado) || !self::quedo_cortado($cortado)) {

                return null;
            }

            $original = AiMessage::where('ai_conversation_id', $conversation->id)
                                    ->where('rol', 'user')
                                    ->where('id', '<', $cortado->id)
                                    ->orderBy('id', 'DESC')
                                    ->value('contenido');

            $original = trim((string) $original);

            return '[El sistema: tu respuesta anterior se CORTÓ antes de terminar (una falla de conexión con el proveedor de IA o el '
                . 'tiempo del turno) y la persona te pide que sigas.'
                . ($original === '' ? '' : ' Lo que estabas resolviendo era su mensaje «' . mb_substr($original, 0, self::LARGO_DEL_PEDIDO) . '».')
                . ' Retomá desde donde quedó: hacé lo que le habías anunciado y todavía no está hecho (por ejemplo, una carga que '
                . 'dijiste que ibas a armar «en un segundo paso»). Mirá las líneas [Tarjeta …] para no repetir lo que ya quedó '
                . 'registrado, y no le vuelvas a preguntar lo que ya te contestó. Si lo que falta necesita su sí, armá la propuesta '
                . 'en ESTE turno y pedíselo una sola vez.]';

        } catch (\Throwable $e) {

            Log::warning('SeguiTrasUnCorteIaHelper: no se pudo revisar el «seguí»; sigue sin nota.', [
                'ai_conversation_id' => (int) $conversation->id,
                'error'              => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * true si el texto es un «seguí» corto y nada más.
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function es_segui($texto)
    {
        if (preg_match('/[?¿]/u', (string) $texto)) {

            return false;
        }

        $normalizado = VeracidadDelTurnoIaHelper::normalizar((string) $texto);

        if ($normalizado === '' || mb_strlen($normalizado) > self::LARGO_MAXIMO) {

            return false;
        }

        foreach (self::COMIENZOS as $comienzo) {

            $comienzo = VeracidadDelTurnoIaHelper::normalizar($comienzo);

            if ($normalizado === $comienzo || mb_strpos($normalizado, $comienzo . ' ') === 0) {

                return true;
            }
        }

        return false;
    }

    /**
     * true si el mensaje del asistente es un turno que no terminó.
     *
     * @param  \App\Models\AiMessage  $mensaje
     * @return bool
     */
    public static function quedo_cortado(AiMessage $mensaje)
    {
        if (mb_strpos((string) $mensaje->error_mensaje, self::PREFIJO_DEL_DETALLE) === 0) {

            return true;
        }

        foreach (self::MARCAS_DE_CORTE as $marca) {

            if (mb_strpos((string) $mensaje->contenido, $marca) !== false) {

                return true;
            }
        }

        return false;
    }
}
