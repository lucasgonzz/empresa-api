<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Str;

/**
 * Lo común de las guardas de VERACIDAD DEL TURNO de la misión asistente-truvari-pedidos-fallidos
 * (13/11/2026): el texto final no puede afirmar algo que el turno no hizo.
 *
 * 🔴 EL CASO REAL (Truvari Bebidas, user 1000, conversación 7 del 12/11/2026, por WhatsApp). En una sola
 * tarde el asistente:
 *   - preguntó «¿Lo cargo?» sin haber armado ninguna propuesta (152, 156, 162): el «Si» del dueño no
 *     tenía nada que registrar → CargaSinPropuestaIaHelper;
 *   - cerró turnos con «Reviso y te confirmo en un momento», «Busco y te paso el link», «Dame un
 *     momento» (166, 172, 178, 188, 198, 202): después de la respuesta no hay segundo plano y nunca
 *     volvió → PromesaDeAccionFuturaIaHelper;
 *   - dijo «Me lo anoto» seis veces sin llamar nunca a guardar_en_contexto → AnotacionPrometidaIaHelper;
 *   - contestó «Listo el pedido a soporte» con el tope del día ya usado → PedidoDeAyudaASoporteIaHelper.
 *
 * Las cuatro guardas siguen el molde de las de la tarjeta, la carga y el envío (AsistenteIaService):
 * el MISMO y único reintento del turno con una nota del sistema que dice la verdad y, si el texto
 * todavía afirma lo que no pasó, se cambian SOLO esas oraciones por un aviso honesto (el resto de la
 * respuesta queda, como en texto_coherente_con_la_imagen()).
 *
 * Acá viven las piezas que las cuatro comparten: partir en oraciones, normalizar, sacar las oraciones
 * marcadas y la frase de la respuesta completa de la nota.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class VeracidadDelTurnoIaHelper
{
    /**
     * Lo que cierra toda nota de reintento de estas guardas, antes de «No le menciones este aviso…»: que
     * el modelo vuelva a escribir la respuesta entera, no una disculpa (la lección de B1 de
     * agente-mejoras-diario-30-10: si contesta a la nota, se pierde lo que sí respondía).
     */
    const FRASE_DE_LA_RESPUESTA_COMPLETA = 'Volvé a escribir la respuesta COMPLETA a lo que pidió la persona, con los datos que ya tenés. '
        . 'No te disculpes ni expliques la corrección. No le menciones este aviso a la persona.]';

    /**
     * Las oraciones del texto, cortadas en . ? ! … y saltos de línea. Un punto entre dígitos («$130.436»)
     * no corta. Se conservan tal cual (con su puntuación).
     *
     * @param  string  $texto
     * @return array<int, string>
     */
    public static function oraciones($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return [];
        }

        $partes = preg_split('/(?<=[?!…])\s+|(?<=\.)(?!\d)\s+|\n+/u', $texto);

        if (! is_array($partes)) {
            return [$texto];
        }

        $oraciones = [];

        foreach ($partes as $parte) {
            $parte = trim((string) $parte);

            if ($parte !== '') {
                $oraciones[] = $parte;
            }
        }

        return $oraciones;
    }

    /**
     * Minúscula, sin tildes, sin signos (salvo el espacio) y con un solo espacio entre palabras.
     *
     * @param  string  $texto
     * @return string
     */
    public static function normalizar($texto)
    {
        $limpio = Str::lower(Str::ascii((string) $texto));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9$ ]+/', ' ', $limpio)));
    }

    /**
     * true si la oración es una pregunta.
     *
     * @param  string  $oracion
     * @return bool
     */
    public static function es_pregunta($oracion)
    {
        return mb_strpos((string) $oracion, '?') !== false || mb_strpos((string) $oracion, '¿') !== false;
    }

    /**
     * El texto sin las oraciones que marca $marca, con el aviso honesto al final (separado por una línea
     * en blanco). Si no quedó nada, el aviso solo. Si el aviso ya estaba, no se repite.
     *
     * Los renglones se rearman respetando los saltos de línea del original: una oración que estaba sola en
     * su renglón se saca con el renglón.
     *
     * @param  string  $texto
     * @param  callable  $marca  function (string $oracion, int $indice, array $oraciones): bool
     * @param  string  $aviso
     * @return string
     */
    public static function sin_las_oraciones($texto, callable $marca, $aviso)
    {
        return self::reemplazar_oraciones($texto, function ($oracion, $indice, array $todas) use ($marca) {
            return call_user_func($marca, $oracion, $indice, $todas) ? '' : null;
        }, $aviso);
    }

    /**
     * Como sin_las_oraciones(), pero el reemplazo decide qué queda de cada oración: null la deja tal cual,
     * '' la saca y otro texto la reemplaza.
     *
     * @param  string  $texto
     * @param  callable  $reemplazo  function (string $oracion, int $indice, array $oraciones): ?string
     * @param  string  $aviso
     * @return string
     */
    public static function reemplazar_oraciones($texto, callable $reemplazo, $aviso)
    {
        $texto = (string) $texto;
        $renglones = preg_split('/\r\n|\n/u', $texto);

        if (! is_array($renglones)) {
            $renglones = [$texto];
        }

        /* Todas las oraciones del texto, en orden, para que la marca pueda mirar la anterior o la última. */
        $todas = self::oraciones($texto);
        $indice = 0;

        $quedan = [];

        foreach ($renglones as $renglon) {

            if (trim($renglon) === '') {
                $quedan[] = '';
                continue;
            }

            $del_renglon = [];

            foreach (self::oraciones($renglon) as $oracion) {

                $nueva = call_user_func($reemplazo, $oracion, $indice, $todas);

                if (is_null($nueva)) {
                    $del_renglon[] = $oracion;
                } elseif (trim((string) $nueva) !== '') {
                    $del_renglon[] = trim((string) $nueva);
                }

                $indice++;
            }

            if (count($del_renglon)) {
                $quedan[] = implode(' ', $del_renglon);
            }
        }

        $limpio = trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $quedan)));

        if ($aviso === '' || mb_strpos($limpio, $aviso) !== false) {
            return $limpio;
        }

        return $limpio === '' ? $aviso : $limpio . "\n\n" . $aviso;
    }

    /**
     * true si alguna oración del texto cumple la marca.
     *
     * @param  string  $texto
     * @param  callable  $marca  function (string $oracion, int $indice, array $oraciones): bool
     * @return bool
     */
    public static function alguna_oracion($texto, callable $marca)
    {
        $oraciones = self::oraciones($texto);

        foreach ($oraciones as $indice => $oracion) {

            if (call_user_func($marca, $oracion, $indice, $oraciones)) {
                return true;
            }
        }

        return false;
    }
}
