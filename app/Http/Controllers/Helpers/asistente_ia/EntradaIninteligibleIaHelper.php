<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Un mensaje del dueño que no se entiende NO es un «sí» (misión asistente-diario-20261116, rama C, B2 y
 * 30/10 #14).
 *
 * 🔴 LOS CASOS REALES. Pesca (14/11/2026, mensaje 83): un audio se transcribió como «吉博好» y el asistente lo
 * tomó como la respuesta a una carga pendiente. Ferre Total (29/10, mensaje 167): un audio de Pablo se
 * transcribió en télugu («See, check చేయాలి»). Y por WhatsApp un audio que no se pudo transcribir llega como
 * «[Audio sin transcripción]» (WhatsappBotController). Ninguno de los tres dice nada: no pueden confirmar una
 * carga, y lo honesto es pedirle a la persona que lo repita.
 *
 * es_ininteligible() es la única definición; la usan la guarda de ConfirmacionPorTextoIaHelper::rechazo()
 * (el modelo no puede confirmar con eso) y la nota que AsistenteIaService le pega al mensaje (que pida
 * repetirlo). La confirmación determinista ya no lo toma: normalizado queda vacío.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class EntradaIninteligibleIaHelper
{
    /** Lo que deja WhatsappBotController cuando un audio no se pudo transcribir. */
    const AUDIO_SIN_TRANSCRIPCION = '[Audio sin transcripción]';

    /**
     * Desde qué proporción de letras de OTRA escritura (no latina) el mensaje se toma como ininteligible: el
     * 40 %. «See, check చేయాలి» tiene 8 latinas y 6 en télugu (43 %); un nombre propio suelto en otra
     * escritura dentro de una frase en castellano queda muy por debajo.
     */
    const PROPORCION_NO_LATINA = 0.4;

    /** Cuántas letras de otra escritura hacen falta como mínimo (un símbolo suelto no cuenta). */
    const MINIMO_NO_LATINAS = 2;

    /** Lo que se le contesta al modelo si intenta confirmar con un mensaje así. */
    const MENSAJE_NO_CONFIRMA = 'No confirmé nada: el último mensaje de la persona no se entiende (un audio que no se '
        . 'pudo pasar a texto o que salió en otro idioma), así que no es un sí. Pedile que lo repita o que lo escriba.';

    /** La nota que viaja pegada a un mensaje así. */
    const NOTA = '[Nota del sistema: este mensaje no se entiende (un audio que no se pudo pasar a texto o que salió en '
        . 'otro idioma). No lo tomes como respuesta a ninguna pregunta ni confirmes nada con él: decile en una línea '
        . 'que no le entendiste y pedile que lo repita o lo escriba. Contestá en español.]';

    /**
     * true si el texto es un audio sin transcribir o es mayormente de otra escritura (no latina).
     *
     * @param  string|null  $texto
     * @return bool
     */
    public static function es_ininteligible($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return false;
        }

        if (mb_strpos($texto, self::AUDIO_SIN_TRANSCRIPCION) !== false
            && trim(str_replace(self::AUDIO_SIN_TRANSCRIPCION, '', $texto)) === '') {

            return true;
        }

        $latinas = preg_match_all('/\p{Latin}/u', $texto);

        // Letras y marcas (las vocales del télugu son marcas) que no son latinas.
        $no_latinas = preg_match_all('/(?!\p{Latin})[\p{L}\p{M}]/u', $texto);

        if (!$no_latinas || $no_latinas < self::MINIMO_NO_LATINAS) {

            return false;
        }

        return $no_latinas >= self::PROPORCION_NO_LATINA * ($latinas + $no_latinas);
    }
}
