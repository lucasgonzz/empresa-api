<?php

namespace App\Http\Controllers\Helpers;

/**
 * Colores de los mails de la tienda: el fondo del botón principal siempre tiene que verse activo
 * sobre la tarjeta blanca del mail, aunque el color primario del comercio sea casi blanco.
 */
class ColorDeMailHelper
{
    /** Fondo neutro que se usa cuando ningún color del comercio contrasta lo suficiente. */
    const NEUTRAL = '#111827';

    /** Superficie sobre la que se apoya el botón: la tarjeta del mail es blanca fija. */
    const SURFACE = '#ffffff';

    /** Contraste mínimo del fondo del botón contra la tarjeta (WCAG 1.4.11, componentes de interfaz). */
    const MIN_BUTTON_CONTRAST = 3;

    /**
     * Elige el fondo y el texto del botón principal del mail.
     *
     * Candidatos en orden: el valor dado (color primario), los de $fallbacks (botón de agregar al
     * carrito y color secundario) y por último el neutro. Gana el primero que es un hex válido y
     * contrasta al menos 3:1 contra la tarjeta blanca; así un primario casi blanco (ej. #E9E7EA) no
     * deja el botón "Ver artículo" o "Volver a la tienda" parecido a uno desactivado.
     *
     * @param mixed $value Color primario del comercio (hex #RGB o #RRGGBB; cualquier otra cosa se ignora).
     * @param array $fallbacks Otros colores candidatos, en orden de preferencia.
     * @return array ['background' => hex del fondo, 'text' => '#000000' o '#ffffff' según el mayor contraste]
     */
    public static function palette($value, array $fallbacks = array())
    {
        // Lista completa de candidatos; el neutro va al final y siempre contrasta.
        $candidates = array_merge(array($value), array_values($fallbacks), array(self::NEUTRAL));
        $color = self::NEUTRAL;
        foreach ($candidates as $candidate) {
            $normalized = self::normalize($candidate);
            if ($normalized !== null && self::contrast($normalized, self::SURFACE) >= self::MIN_BUTTON_CONTRAST) {
                $color = $normalized;
                break;
            }
        }
        // Texto: el de mayor contraste sobre el fondo elegido (siempre >= 4,5:1 con un fondo que da >= 3:1 contra blanco).
        $text = self::contrast($color, '#ffffff') >= self::contrast($color, '#000000') ? '#ffffff' : '#000000';
        return ['background' => $color, 'text' => $text];
    }

    /**
     * Valida un color y lo lleva a la forma #RRGGBB.
     *
     * @param mixed $value Valor leído de la configuración.
     * @return string|null El hex de 6 dígitos o null si no es un color válido.
     */
    private static function normalize($value)
    {
        $color = is_string($value) ? trim($value) : '';
        if (preg_match('/^#[a-f0-9]{3}$/i', $color)) {
            $color = '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        }
        return preg_match('/^#[a-f0-9]{6}$/i', $color) ? $color : null;
    }

    /**
     * Relación de contraste WCAG entre dos colores #RRGGBB.
     *
     * @param string $first Primer color.
     * @param string $second Segundo color.
     * @return float Valor entre 1 y 21.
     */
    private static function contrast($first, $second)
    {
        $a = self::luminance($first);
        $b = self::luminance($second);
        return (max($a, $b) + .05) / (min($a, $b) + .05);
    }

    /**
     * Luminancia relativa WCAG de un color #RRGGBB.
     *
     * @param string $color Color en hex de 6 dígitos.
     * @return float Luminancia entre 0 y 1.
     */
    public static function luminance($color)
    {
        $parts = [];
        foreach ([1, 3, 5] as $offset) {
            $v = hexdec(substr($color, $offset, 2)) / 255;
            $parts[] = $v <= .04045 ? $v / 12.92 : pow(($v + .055) / 1.055, 2.4);
        }
        return .2126 * $parts[0] + .7152 * $parts[1] + .0722 * $parts[2];
    }
}
