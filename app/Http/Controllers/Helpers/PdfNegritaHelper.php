<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PdfColumnOption;

/**
 * Negrita en el valor de lo que imprime un diseño de PDF (misión pdf-negrita-perfil, 20/10/2026).
 *
 * El usuario puede pedir negrita en TRES lugares, todos opcionales (ausente / null = el PDF de
 * siempre, byte a byte):
 *
 *  A. Columnas de la tabla: `pdf_column_profiles.bold_columns`, lista de ids de
 *     `pdf_column_options` cuyo VALOR va en negrita (el encabezado de la tabla ya es negrita).
 *  B. Renglones del encabezado del catálogo de artículos: clave `bold` (bool) de cada renglón de
 *     `catalog_header_layout` (ver CatalogHeaderLayoutHelper).
 *  C. Datos del encabezado de venta / presupuesto / pedido: clave `bold` de `header_layout`, lista
 *     de keys de chips (`['cuit', 'cliente_nombre']`) cuyo valor va en negrita.
 *
 * Este helper normaliza lo que llega del cliente (store / update del perfil) y resuelve lo que
 * leen los renderers. No hace nada de dibujo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, propiedades tipadas ni arrow functions.
 */
class PdfNegritaHelper
{
    /**
     * Keys de los chips del encabezado que se pueden poner en negrita (catálogo del diseñador del
     * encabezado, ver PdfColumnProfile::$casts['header_layout']): datos del emisor y del cliente.
     * Cualquier otra clave se descarta al guardar.
     *
     * @var array<int, string>
     */
    const CLAVES_DE_ENCABEZADO = [
        // Emisor.
        'razon_social',
        'domicilio_comercial',
        'condicion_iva',
        'punto_venta',
        'cuit',
        'ingresos_brutos',
        'inicio_actividades',
        'numero_comprobante',
        'fecha_emision',
        'web',
        'telefono',
        'email',
        // Receptor (remito, presupuesto y pedido).
        'cliente_nombre',
        'cliente_telefono',
        'cliente_localidad',
        'cliente_direccion',
        'cliente_cuit',
        'cliente_condicion_iva',
        'vendedor',
        'empleado',
    ];

    /**
     * Normaliza `bold_columns` tal como llega del cliente.
     *
     * Acepta array, string JSON o null. Devuelve una lista de enteros únicos, ordenada, de los ids
     * que EXISTEN en el catálogo de columnas del `model_name` del perfil (los ajenos o inventados
     * se descartan). Lista vacía, valor sin forma de lista o null → null (ninguna en negrita).
     *
     * @param  mixed       $value      array, string JSON o null.
     * @param  string|null $model_name 'sale' | 'budget' | 'order' | 'article' (el del perfil que queda).
     * @return array<int, int>|null
     */
    public static function normalize_bold_columns($value, $model_name)
    {
        // Un string JSON se decodifica; cualquier otra cosa que no sea array no es una lista.
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || empty($value)) {
            return null;
        }

        // Solo enteros positivos (acepta "7" y 7.0); el resto se descarta sin romper.
        $ids = [];

        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item)) || (is_float($item) && floor($item) == $item)) {
                $id = (int) $item;

                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        if (empty($ids)) {
            return null;
        }

        // Solo los ids que existen en el catálogo del tipo de diseño del perfil.
        $validos = PdfColumnOption::where('model_name', $model_name)
            ->whereIn('id', array_values($ids))
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        sort($validos);

        return empty($validos) ? null : array_values($validos);
    }

    /**
     * Ids de columnas en negrita de un perfil, leídos con defensa: el atributo puede no existir
     * (modelo armado a mano en un test), llegar null (la columna recién migrada) o traer basura.
     *
     * @param  mixed $profile PdfColumnProfile o null.
     * @return array<int, int>
     */
    public static function bold_option_ids($profile)
    {
        if (is_null($profile)) {
            return [];
        }

        $value = $profile->getAttribute('bold_columns');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }

        return $ids;
    }

    /**
     * Normaliza la clave `bold` de un `header_layout`: lista de strings únicas, solo keys del
     * catálogo de chips (CLAVES_DE_ENCABEZADO), en el orden en que vinieron. Vacía o sin forma de
     * lista → null (el que llama omite la clave).
     *
     * @param  mixed $value
     * @return array<int, string>|null
     */
    public static function normalize_header_bold($value)
    {
        if (! is_array($value)) {
            return null;
        }

        $keys = [];

        foreach ($value as $item) {
            if (is_string($item) && in_array($item, self::CLAVES_DE_ENCABEZADO, true) && ! in_array($item, $keys, true)) {
                $keys[] = $item;
            }
        }

        return empty($keys) ? null : $keys;
    }

    /**
     * Las keys del encabezado en negrita de un `header_layout` efectivo (el guardado o el default).
     * Sin la clave, o con basura, devuelve [] y el encabezado sale como siempre.
     *
     * @param  mixed $layout header_layout (array) o null.
     * @return array<int, string>
     */
    public static function header_bold_keys($layout)
    {
        if (! is_array($layout) || ! isset($layout['bold']) || ! is_array($layout['bold'])) {
            return [];
        }

        $keys = [];

        foreach ($layout['bold'] as $item) {
            if (is_string($item)) {
                $keys[] = $item;
            }
        }

        return $keys;
    }
}
