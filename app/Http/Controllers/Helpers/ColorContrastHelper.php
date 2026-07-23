<?php

namespace App\Http\Controllers\Helpers;

/**
 * Helper determinista de contraste de color (matematica pura, sin llamadas externas).
 *
 * Se usa como capa de validacion sobre las paletas que propone LogoPaletteAiService: la IA
 * (Claude con vision) es buena leyendo la identidad de una marca, pero no es confiable
 * garantizando contraste legible entre texto y fondo. Este helper aplica siempre la formula
 * oficial de WCAG 2.x para decidir, de forma reproducible, si un par de colores es legible.
 */
class ColorContrastHelper
{
    /**
     * Normaliza un valor de color a formato '#RRGGBB' en mayusculas.
     *
     * Tolera:
     * - Espacios alrededor del valor.
     * - Ausencia del '#' inicial.
     * - Formato corto '#RGB' (se expande duplicando cada digito: '#F0A' -> '#FF00AA').
     *
     * @param  string|null $value  Valor de color crudo (por ejemplo el que devuelve Claude).
     * @return string|null  '#RRGGBB' en mayusculas, o null si el valor no es un hex valido.
     */
    public static function normalize_hex($value)
    {
        if (!is_string($value)) {
            return null;
        }

        // Sacar espacios y el '#' inicial si vino, para trabajar siempre sobre el mismo formato.
        $value = trim($value);
        $value = ltrim($value, '#');
        $value = strtoupper($value);

        // Formato corto '#RGB': cada digito se duplica para llegar a 6 caracteres.
        if (preg_match('/^[0-9A-F]{3}$/', $value)) {
            $expanded = '';
            for ($i = 0; $i < 3; $i++) {
                $expanded .= $value[$i].$value[$i];
            }
            $value = $expanded;
        }

        // Unico formato aceptado a esta altura: exactamente 6 digitos hexadecimales.
        if (!preg_match('/^[0-9A-F]{6}$/', $value)) {
            return null;
        }

        return '#'.$value;
    }

    /**
     * Convierte un hex '#RRGGBB' a sus componentes RGB (0-255 cada uno).
     *
     * @param  string|null $hex
     * @return array|null  [r, g, b] con enteros 0-255, o null si el hex no es valido.
     */
    public static function hex_to_rgb($hex)
    {
        $normalized = self::normalize_hex($hex);

        if ($normalized === null) {
            return null;
        }

        $value = ltrim($normalized, '#');

        return [
            hexdec(substr($value, 0, 2)),
            hexdec(substr($value, 2, 2)),
            hexdec(substr($value, 4, 2)),
        ];
    }

    /**
     * Luminancia relativa de un color, segun la formula de WCAG 2.x.
     *
     * Cada canal (0-255) se linealiza dividiendo por 255 y aplicando la correccion gamma sRGB:
     * si el valor linealizado es <= 0.03928 se divide por 12.92 (tramo lineal cerca de 0), si no
     * se aplica pow((c+0.055)/1.055, 2.4) (tramo con correccion gamma). Estos numeros (0.03928,
     * 1.055, 2.4, y los pesos 0.2126/0.7152/0.0722) son parte de la especificacion de WCAG y no
     * se pueden "redondear": los pesos reflejan que el ojo humano percibe el verde como el canal
     * mas brillante y el azul como el menos brillante.
     *
     * @param  string|null $hex
     * @return float|null  0..1, o null si el hex no es valido.
     */
    public static function relative_luminance($hex)
    {
        $rgb = self::hex_to_rgb($hex);

        if ($rgb === null) {
            return null;
        }

        $linear = [];

        foreach ($rgb as $channel) {
            $c_srgb = $channel / 255;

            if ($c_srgb <= 0.03928) {
                $linear[] = $c_srgb / 12.92;
            } else {
                $linear[] = pow(($c_srgb + 0.055) / 1.055, 2.4);
            }
        }

        // Pesos perceptuales de WCAG: el ojo humano pondera mas el canal verde, luego el rojo,
        // y mucho menos el azul.
        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    /**
     * Ratio de contraste entre dos colores, segun la formula de WCAG 2.x.
     *
     * Formula: (L_claro + 0.05) / (L_oscuro + 0.05), donde L_claro y L_oscuro son las
     * luminancias relativas de los dos colores ordenadas de mayor a menor. El resultado va
     * siempre entre 1 (mismo color) y 21 (blanco puro contra negro puro).
     *
     * @param  string|null $hex_a
     * @param  string|null $hex_b
     * @return float|null  Entre 1 y 21, o null si alguno de los dos colores no es valido.
     */
    public static function contrast_ratio($hex_a, $hex_b)
    {
        $luminance_a = self::relative_luminance($hex_a);
        $luminance_b = self::relative_luminance($hex_b);

        if ($luminance_a === null || $luminance_b === null) {
            return null;
        }

        // El "+0.05" evita dividir por un numero muy cercano a cero cuando ambos colores son
        // muy oscuros; es parte de la formula oficial, no un ajuste propio.
        $lighter = max($luminance_a, $luminance_b);
        $darker  = min($luminance_a, $luminance_b);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Determina si un color es "oscuro" (luminancia relativa menor al punto medio 0.5).
     *
     * @param  string|null $hex
     * @return bool  true si es oscuro (o si el hex es invalido, por seguridad se asume oscuro
     *               para forzar la revision manual del caso en lugar de asumir que es claro).
     */
    public static function is_dark($hex)
    {
        $luminance = self::relative_luminance($hex);

        if ($luminance === null) {
            return true;
        }

        return $luminance < 0.5;
    }

    /**
     * Devuelve el color de texto (blanco o negro casi puro) con mayor contraste contra un fondo.
     *
     * Se usan '#FFFFFF' y '#111111' (en vez de negro puro '#000000') porque un negro casi puro
     * se ve mas prolijo en pantalla y sigue dando un ratio de contraste practicamente identico.
     *
     * @param  string $background_hex
     * @return string  '#FFFFFF' o '#111111'.
     */
    public static function best_text_on($background_hex)
    {
        $white_ratio = self::contrast_ratio($background_hex, '#FFFFFF');
        $black_ratio = self::contrast_ratio($background_hex, '#111111');

        // Si el fondo no es un hex valido, contrast_ratio devuelve null para ambos: en ese caso
        // se prioriza blanco como default seguro (mismo criterio historico del proyecto).
        if ($white_ratio === null || $black_ratio === null) {
            return '#FFFFFF';
        }

        return $white_ratio >= $black_ratio ? '#FFFFFF' : '#111111';
    }
}
