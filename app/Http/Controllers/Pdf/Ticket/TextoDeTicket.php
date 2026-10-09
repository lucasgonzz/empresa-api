<?php

namespace App\Http\Controllers\Pdf\Ticket;

/**
 * El texto de un ticket de comandera: limpiarlo, medirlo, partirlo y pasarlo a los bytes que
 * entiende la impresora (misión diseno-ticket-comandera, 9/10/2026, §4 del plan).
 *
 * Una comandera imprime en una grilla de caracteres de ancho fijo: N caracteres por renglón
 * (48 en 80 mm). Todo se mide en COLUMNAS de esa grilla:
 * - un carácter en tamaño normal o alto doble ocupa una columna;
 * - en tamaño grande (doble alto Y doble ancho, `GS ! 0x11`) ocupa dos.
 *
 * 🔴 EL TEXTO SE LIMPIA ANTES DE MEDIRLO. La impresora recibe CP850 (`ESC t 2`, decisión D11): cada
 * carácter tiene que ser UN byte de esa tabla, o el renglón sale corrido. limpiar() deja cada
 * carácter como uno que existe en CP850 (los que no, por su transliteración ASCII —"€" → "EUR"— o
 * por "?"), así lo que se mide es exactamente lo que se imprime. Y saca los caracteres de control:
 * una comilla tipográfica mal convertida o un nombre pegado desde otro lado con un 0x1D adentro
 * (GS) se leería como un COMANDO de la impresora (es lo que el Ticket 2.0 de siempre documenta en
 * contenido_a_base64()).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class TextoDeTicket
{
    /** Tamaño normal (`GS ! 0x00`). */
    const NORMAL = 'normal';

    /** Alto doble (`GS ! 0x01`): mismo ancho que el normal. */
    const ALTO = 'alto';

    /** Grande: doble alto y doble ancho (`GS ! 0x11`): cada carácter ocupa dos columnas. */
    const GRANDE = 'grande';

    /** Desde este tamaño (pt) la letra es alto doble (decisión D7). */
    const TAMANO_ALTO = 12;

    /** Desde este tamaño (pt) la letra es grande (decisión D7). */
    const TAMANO_GRANDE = 18;

    /**
     * Carácter UTF-8 => byte CP850, memo por proceso (iconv por carácter es caro).
     *
     * @var array<string, string>
     */
    private static $bytes_cp850 = [];

    /**
     * Carácter UTF-8 => lo que queda después de limpiarlo (uno o más caracteres que existen en CP850).
     *
     * @var array<string, string>
     */
    private static $limpios = [];

    /**
     * El tamaño de la comandera para un tamaño en puntos del diseño: menos de 12 normal, de 12 a 17
     * alto doble, 18 o más grande.
     *
     * @param mixed $tamano
     * @return string self::NORMAL | self::ALTO | self::GRANDE
     */
    public static function clase_de_tamano($tamano)
    {
        $tamano = (int) $tamano;

        if ($tamano >= self::TAMANO_GRANDE) {
            return self::GRANDE;
        }

        if ($tamano >= self::TAMANO_ALTO) {
            return self::ALTO;
        }

        return self::NORMAL;
    }

    /**
     * Cuántas columnas ocupa cada carácter en ese tamaño.
     *
     * @param string $clase
     * @return int 1 o 2
     */
    public static function factor($clase)
    {
        return $clase === self::GRANDE ? 2 : 1;
    }

    /**
     * Deja el texto listo para medir e imprimir: UTF-8 válido, cada carácter uno que existe en CP850 y
     * sin caracteres de control. Los saltos de línea se conservan solo si se pide (un campo largo los
     * usa para partir párrafos); si no, pasan a ser espacios.
     *
     * @param mixed $texto
     * @param bool  $con_saltos
     * @return string
     */
    public static function limpiar($texto, $con_saltos = false)
    {
        if (is_null($texto) || is_array($texto) || is_object($texto)) {
            return '';
        }

        $texto = str_replace(["\r\n", "\r"], "\n", (string) $texto);

        /** Un texto que no es UTF-8 válido (un dato viejo en Latin-1) se lee como Latin-1. */
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        /**
         * Forma compuesta (NFC): una "é" escrita como "e" + tilde combinable (U+0301, la que dejan
         * algunos teclados de celular o un texto pegado desde una Mac) pasa a ser UNA letra, que existe
         * en CP850. Sin esto la tilde suelta salía "?". Solo si el servidor tiene la extensión intl.
         */
        if (class_exists('Normalizer')) {
            $compuesto = \Normalizer::normalize($texto, \Normalizer::FORM_C);
            if (is_string($compuesto)) {
                $texto = $compuesto;
            }
        }

        $caracteres = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($caracteres)) {
            return '';
        }

        $limpio = '';
        foreach ($caracteres as $caracter) {
            if ($caracter === "\n") {
                $limpio .= $con_saltos ? "\n" : ' ';
                continue;
            }

            $limpio .= self::caracter_limpio($caracter);
        }

        return $limpio;
    }

    /**
     * Largo en caracteres de un texto ya limpio.
     *
     * @param string $texto
     * @return int
     */
    public static function largo($texto)
    {
        return mb_strlen((string) $texto, 'UTF-8');
    }

    /**
     * Las primeras $ancho letras de un texto ya limpio.
     *
     * @param string $texto
     * @param int    $ancho
     * @return string
     */
    public static function cortar($texto, $ancho)
    {
        return mb_substr((string) $texto, 0, max(0, (int) $ancho), 'UTF-8');
    }

    /**
     * Parte un texto ya limpio en renglones de hasta $ancho caracteres, cortando en los espacios.
     * Una palabra más larga que el renglón se corta. Respeta los saltos de línea (un párrafo vacío es
     * un renglón vacío).
     *
     * @param string $texto
     * @param int    $ancho caracteres (no columnas: el que llama ya dividió por el factor del tamaño).
     * @return array<int, string>
     */
    public static function partir($texto, $ancho)
    {
        $ancho = max(1, (int) $ancho);
        $renglones = [];

        foreach (explode("\n", (string) $texto) as $parrafo) {
            $actual = '';

            foreach (explode(' ', $parrafo) as $palabra) {
                $candidata = $actual === '' ? $palabra : $actual.' '.$palabra;

                if (self::largo($candidata) <= $ancho) {
                    $actual = $candidata;
                    continue;
                }

                /** La palabra no entra en lo que queda del renglón: el renglón se cierra. */
                if ($actual !== '') {
                    $renglones[] = $actual;
                    $actual = '';
                }

                /** Una palabra más larga que el renglón se corta en pedazos del ancho. */
                while (self::largo($palabra) > $ancho) {
                    $renglones[] = self::cortar($palabra, $ancho);
                    $palabra = mb_substr($palabra, $ancho, null, 'UTF-8');
                }

                $actual = $palabra;
            }

            $renglones[] = $actual;
        }

        return $renglones;
    }

    /**
     * Parte un texto ya limpio por caracteres, sin buscar espacios (un número que no entra en su
     * columna no se corta: sigue en el renglón de abajo).
     *
     * @param string $texto
     * @param int    $ancho
     * @return array<int, string>
     */
    public static function partir_por_caracteres($texto, $ancho)
    {
        $ancho = max(1, (int) $ancho);
        $renglones = [];

        do {
            $renglones[] = self::cortar($texto, $ancho);
            $texto = mb_substr((string) $texto, $ancho, null, 'UTF-8');
        } while (self::largo($texto) > 0);

        return $renglones;
    }

    /**
     * Los bytes CP850 de un texto ya limpio (cada carácter, un byte).
     *
     * @param string $texto
     * @return string
     */
    public static function a_cp850($texto)
    {
        $caracteres = preg_split('//u', (string) $texto, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($caracteres)) {
            return '';
        }

        $bytes = '';
        foreach ($caracteres as $caracter) {
            $bytes .= self::byte_cp850($caracter);
        }

        return $bytes;
    }

    /**
     * Un carácter limpio: él mismo si existe en CP850, su transliteración ASCII si no (puede ser más
     * de un carácter: "€" → "EUR"), "?" si no tiene ninguna, y un espacio si es de control.
     *
     * @param string $caracter un carácter UTF-8.
     * @return string
     */
    private static function caracter_limpio($caracter)
    {
        if (isset(self::$limpios[$caracter])) {
            return self::$limpios[$caracter];
        }

        $codigo = mb_ord($caracter, 'UTF-8');

        if ($codigo === false || $codigo < 0x20 || $codigo === 0x7F || ($codigo >= 0x80 && $codigo < 0xA0)) {
            $limpio = ' ';
        } elseif ($codigo < 0x80) {
            $limpio = $caracter;
        } else {
            $bytes = @iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $caracter);

            if ($bytes === false || $bytes === '') {
                $limpio = '?';
            } elseif (strlen($bytes) === 1) {
                $limpio = $caracter;
                self::$bytes_cp850[$caracter] = $bytes;
            } elseif (preg_match('/^[\x20-\x7E]+$/', $bytes)) {
                /** Transliteración ASCII de varios caracteres ("EUR"): se imprime eso y se mide eso. */
                $limpio = $bytes;
            } else {
                $limpio = '?';
            }
        }

        self::$limpios[$caracter] = $limpio;

        return $limpio;
    }

    /**
     * El byte CP850 de un carácter ya limpio.
     *
     * @param string $caracter
     * @return string
     */
    private static function byte_cp850($caracter)
    {
        if (strlen($caracter) === 1) {
            return $caracter;
        }

        if (! isset(self::$bytes_cp850[$caracter])) {
            $bytes = @iconv('UTF-8', 'CP850//IGNORE', $caracter);
            self::$bytes_cp850[$caracter] = ($bytes !== false && strlen($bytes) === 1) ? $bytes : '?';
        }

        return self::$bytes_cp850[$caracter];
    }
}
