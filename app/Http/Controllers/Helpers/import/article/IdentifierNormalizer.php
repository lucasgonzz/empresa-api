<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;

/**
 * Normaliza los valores que llegan del Excel para los campos identificadores
 * (bar_code, sku, provider_code, id).
 *
 * Los proveedores usan placeholders como "-" o "S/N" para indicar "este producto
 * no tiene codigo". Si se tratan como identificadores reales, todas esas filas
 * matchean contra el mismo articulo y lo sobreescriben una y otra vez.
 */
class IdentifierNormalizer
{
    /**
     * Valores que NO son identificadores. Comparacion case-insensitive
     * y con espacios/puntuacion colapsados.
     */
    protected static $placeholders = [
        '-', '--', '---', '.', '..', '...', '/', '//', '\\',
        's/n', 'sn', 'sin numero', 'sin número', 'sin codigo', 'sin código',
        'no tiene', 'ninguno', 'ninguna', 'n/a', 'na', 'null', 'nulo',
        'x', 'xx', 'xxx', '0', '00', '000', '-', '?', '??', '*', '**',
        'sin', 'no', 'nc', 's/c', 's/d', 'vacio', 'vacío',
    ];

    /**
     * Devuelve el valor limpio, o null si el valor es vacio o un placeholder.
     *
     * @param  mixed $value
     * @return string|null
     */
    public static function normalize($value)
    {
        if (is_null($value)) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return null;
        }

        /*
         * Casteo literal, sin perdida (grupo 229, prompt 07): un identificador
         * numerico (ej. SKU "504346" leido como float 504346.0 por el Excel) no
         * puede pasar por un (string) directo, porque eso produce "504346.0" o
         * notacion cientifica en codigos largos. scalarToLiteralString() es el
         * unico camino de casteo que usa todo el flujo de importacion.
         */
        $clean = trim((string) ImportHelper::scalarToLiteralString($value));

        if ($clean === '') {
            return null;
        }

        if (self::is_placeholder($clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * @param  string $value
     * @return bool
     */
    public static function is_placeholder($value)
    {
        $comparable = self::to_comparable($value);

        if ($comparable === '') {
            return true;
        }

        return in_array($comparable, self::$placeholders, true);
    }

    /**
     * Baja a minusculas, colapsa espacios internos y saca espacios de los bordes.
     * NO saca puntuacion: "S/N" tiene que seguir siendo "s/n".
     *
     * @param  string $value
     * @return string
     */
    protected static function to_comparable($value)
    {
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        $collapsed = preg_replace('/\s+/u', ' ', $lower);

        return trim($collapsed);
    }

    /**
     * Devuelve la lista de placeholders, para poder mostrarla en la UI
     * de pre-importacion.
     *
     * @return array
     */
    public static function placeholders()
    {
        return self::$placeholders;
    }
}
