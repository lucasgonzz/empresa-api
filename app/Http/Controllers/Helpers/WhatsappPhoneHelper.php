<?php

namespace App\Http\Controllers\Helpers;

/**
 * Normalización y comparación de números de teléfono para el módulo de WhatsApp
 * (grupo 137). Los números que llegan de Kapso/Meta y los que carga el usuario en
 * `clients.phone` pueden traer prefijos distintos para el mismo número argentino real
 * (código de país `54`, prefijo de celular `9`, `0` inicial de larga distancia, `15` de
 * celular local), por eso la comparación se hace por los últimos 10 dígitos y no por
 * igualdad estricta.
 */
class WhatsappPhoneHelper
{
    /**
     * Deja solo los dígitos de un número de teléfono, descartando espacios, guiones,
     * paréntesis, el símbolo `+`, etc.
     *
     * @param  string|null  $raw  Teléfono en cualquier formato.
     * @return string  Solo dígitos (puede ser cadena vacía si no había ninguno).
     */
    public static function normalize($raw)
    {
        if (is_null($raw)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $raw);

        return is_null($digits) ? '' : $digits;
    }

    /**
     * Indica si dos teléfonos corresponden al mismo número, comparando los últimos 10
     * dígitos (así absorbe las variantes `54`, `549`, `0` y `15` de los números argentinos).
     * Si alguno de los dos, ya normalizado, tiene menos de 10 dígitos (números cortos /
     * internos), se compara por igualdad exacta de dígitos como fallback.
     *
     * @param  string|null  $a  Primer teléfono (cualquier formato).
     * @param  string|null  $b  Segundo teléfono (cualquier formato).
     * @return bool
     */
    public static function matches($a, $b)
    {
        $digits_a = self::normalize($a);
        $digits_b = self::normalize($b);

        if ($digits_a === '' || $digits_b === '') {
            return false;
        }

        // Con menos de 10 dígitos no hay "últimos 10" confiables: comparamos exacto.
        if (strlen($digits_a) < 10 || strlen($digits_b) < 10) {
            return $digits_a === $digits_b;
        }

        $last10_a = substr($digits_a, -10);
        $last10_b = substr($digits_b, -10);

        return $last10_a === $last10_b;
    }
}
