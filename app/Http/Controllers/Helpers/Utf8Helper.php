<?php

namespace App\Http\Controllers\Helpers;

class Utf8Helper
{
    public static function convertir_utf8($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = self::convertir_utf8($val);
            }
            return $value;
        }

        if (is_string($value)) {
            $value = self::to_utf8_string($value);
            $value = self::limpiar_cadena($value);
            $value = str_replace("\\'", "", $value);
            return $value;
        }

        return $value;
    }

    /**
     * Convierte un string a UTF-8 de forma robusta.
     * Si ya es UTF-8 válido, lo deja como está.
     * Si no lo es, intenta Windows-1252 e ISO-8859-1.
     */
    private static function to_utf8_string(string $value): string
    {
        // Si ya es UTF-8 válido, no tocamos
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Intentamos detectar (priorizando Windows-1252 que es común en estos casos)
        $encoding = mb_detect_encoding($value, ['Windows-1252', 'ISO-8859-1', 'UTF-8'], true);

        if ($encoding) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        // Fallback súper tolerante: iconv ignorando bytes inválidos
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        // Último fallback: ISO-8859-1
        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        return $value;
    }

    /**
     * Limpia caracteres de control raros, pero preserva letras acentuadas.
     * OJO: esto debe correr con texto ya en UTF-8.
     */
    public static function limpiar_cadena(string $value): string
    {
        // Remueve controles (excepto tab, LF, CR)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        // Opcional: normaliza espacios raros
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}