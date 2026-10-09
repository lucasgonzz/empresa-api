<?php

namespace App\Http\Controllers\Pdf\Ticket;

/**
 * Las piezas con que se arma un ticket de comandera, antes de pasarlo a bytes o a texto (misión
 * diseno-ticket-comandera, 9/10/2026, §4 del plan).
 *
 * El motor (TicketComanderaEscPos) arma UNA lista de piezas y de esa misma lista salen las dos
 * salidas: los bytes ESC/POS para la impresora y los renglones de texto de la vista previa. Así lo
 * que se ve en "Ver cómo sale" es exactamente lo que imprime la comandera, menos los comandos.
 *
 * Una pieza es una de:
 * - ['tipo' => 'renglon', 'trozos' => [TROZO, ...]]: un renglón de la comandera;
 * - ['tipo' => 'logo', 'bytes' => string]: el logo ya pasado a bits de impresora (`GS v 0`);
 * - ['tipo' => 'qr', 'link' => string]: el QR de ARCA con ese contenido.
 *
 * TROZO: ['texto' => string (UTF-8 ya limpio), 'negrita' => bool|null, 'tamano' => string].
 * `negrita` null = "da igual" (los espacios de relleno: no cambian el estado de la impresora).
 * `tamano` es TextoDeTicket::NORMAL | ALTO | GRANDE; el relleno va siempre en NORMAL (un espacio en
 * grande ocuparía dos columnas).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class PiezasDeTicket
{
    /**
     * Un trozo de renglón.
     *
     * @param string    $texto   ya limpio (TextoDeTicket::limpiar()).
     * @param bool|null $negrita null = da igual (relleno).
     * @param string    $tamano
     * @return array
     */
    public static function trozo($texto, $negrita, $tamano = TextoDeTicket::NORMAL)
    {
        return [
            'texto' => (string) $texto,
            'negrita' => $negrita,
            'tamano' => $tamano,
        ];
    }

    /**
     * Espacios de relleno (tamaño normal, negrita indistinta).
     *
     * @param int $cantidad
     * @return array
     */
    public static function espacios($cantidad)
    {
        return self::trozo(str_repeat(' ', max(0, (int) $cantidad)), null);
    }

    /**
     * Columnas de la grilla que ocupa un trozo (grande cuenta doble).
     *
     * @param array $trozo
     * @return int
     */
    public static function columnas_del_trozo($trozo)
    {
        return TextoDeTicket::largo($trozo['texto']) * TextoDeTicket::factor($trozo['tamano']);
    }

    /**
     * Columnas que ocupa una lista de trozos.
     *
     * @param array $trozos
     * @return int
     */
    public static function columnas($trozos)
    {
        $total = 0;
        foreach ($trozos as $trozo) {
            $total += self::columnas_del_trozo($trozo);
        }

        return $total;
    }

    /**
     * Ubica un contenido dentro de un ancho según la alineación, con espacios a los costados
     * (a la derecha solo lo necesario para completar el ancho).
     *
     * @param array  $trozos     el contenido.
     * @param int    $ancho      columnas disponibles.
     * @param string $alineacion 'izquierda' | 'centro' | 'derecha'
     * @return array los trozos con el relleno, que suman exactamente $ancho columnas (o más, si el
     *               contenido ya era más ancho).
     */
    public static function alinear($trozos, $ancho, $alineacion)
    {
        $sobra = max(0, (int) $ancho - self::columnas($trozos));

        if ($sobra === 0) {
            return $trozos;
        }

        if ($alineacion === 'derecha') {
            return array_merge([self::espacios($sobra)], $trozos);
        }

        if ($alineacion === 'centro') {
            $izquierda = (int) floor($sobra / 2);

            return array_merge([self::espacios($izquierda)], $trozos, [self::espacios($sobra - $izquierda)]);
        }

        return array_merge($trozos, [self::espacios($sobra)]);
    }

    /**
     * Un texto partido por palabras en renglones del ancho, cada uno ya alineado.
     *
     * @param string $texto      ya limpio (puede traer saltos de línea).
     * @param int    $ancho      columnas.
     * @param bool   $negrita
     * @param string $tamano
     * @param string $alineacion
     * @return array<int, array> renglones (cada uno, una lista de trozos de $ancho columnas).
     */
    public static function texto_partido($texto, $ancho, $negrita, $tamano = TextoDeTicket::NORMAL, $alineacion = 'izquierda')
    {
        $factor = TextoDeTicket::factor($tamano);
        $renglones = [];

        foreach (TextoDeTicket::partir($texto, (int) floor($ancho / $factor)) as $parte) {
            $renglones[] = self::alinear([self::trozo($parte, $negrita, $tamano)], $ancho, $alineacion);
        }

        return $renglones;
    }

    /**
     * Una línea de guiones del ancho (la que separa bloques, como linea() del Ticket 2.0).
     *
     * @param int $ancho
     * @return array lista de trozos.
     */
    public static function guiones($ancho)
    {
        return [self::trozo(str_repeat('-', max(0, (int) $ancho)), null)];
    }

    /**
     * Pieza de renglón.
     *
     * @param array $trozos
     * @return array
     */
    public static function renglon($trozos)
    {
        return ['tipo' => 'renglon', 'trozos' => array_values($trozos)];
    }

    /**
     * Piezas de renglón para una lista de renglones de trozos.
     *
     * @param array<int, array> $renglones
     * @return array<int, array>
     */
    public static function renglones($renglones)
    {
        $piezas = [];
        foreach ($renglones as $trozos) {
            $piezas[] = self::renglon($trozos);
        }

        return $piezas;
    }

    /**
     * Pieza del logo.
     *
     * @param string $bytes `GS v 0` ya armado (SaleTicketRasterHelper).
     * @return array
     */
    public static function logo($bytes)
    {
        return ['tipo' => 'logo', 'bytes' => (string) $bytes];
    }

    /**
     * Pieza del QR.
     *
     * @param string $link
     * @return array
     */
    public static function qr($link)
    {
        return ['tipo' => 'qr', 'link' => (string) $link];
    }

    /**
     * Saca del final de un renglón los espacios de relleno que sobran (los del contenido, no).
     *
     * @param array $trozos
     * @return array
     */
    public static function sin_relleno_al_final($trozos)
    {
        while (count($trozos) > 0) {
            $ultimo = $trozos[count($trozos) - 1];

            if (! is_null($ultimo['negrita']) || trim($ultimo['texto']) !== '') {
                break;
            }

            array_pop($trozos);
        }

        return $trozos;
    }
}
