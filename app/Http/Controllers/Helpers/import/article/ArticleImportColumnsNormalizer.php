<?php

namespace App\Http\Controllers\Helpers\import\article;

/**
 * Normaliza las claves del mapeo de columnas de importación de artículos.
 *
 * La importación clásica (multipart) recibe prop_* y PHP convierte espacios a guiones bajos,
 * por lo que "Codigo de proveedor" llega como codigo_de_proveedor. La importación con IA envía
 * JSON directo con alias cortos (codigo_proveedor, codigo_barras) que deben alinearse al contrato
 * que usa ArticleImport / ProcessRow.
 */
class ArticleImportColumnsNormalizer
{
    /**
     * Alias de claves del frontend o de Claude hacia las claves canónicas del importador.
     *
     * @var array<string, string>
     */
    protected static $property_aliases = [
        'codigo_proveedor' => 'codigo_de_proveedor',
        'codigo_barras'    => 'codigo_de_barras',
    ];

    /**
     * Normaliza una sola clave de propiedad (p. ej. respuesta de Claude o select del frontend).
     *
     * @param  string|null $property_key  Clave sugerida por la IA o el usuario
     * @return string|null                 Clave canónica o null si la entrada es vacía
     */
    public static function normalize_property_key($property_key)
    {
        if ($property_key === null || trim((string) $property_key) === '') {
            return null;
        }

        $lookup_key = strtolower(trim((string) $property_key));

        return self::$property_aliases[$lookup_key] ?? $property_key;
    }

    /**
     * Convierte el array de columnas al formato esperado por ImportHelper::getColumnValue.
     *
     * @param  array $columns  Mapa propiedad => índice de columna (0-based)
     * @return array           Mismo mapa con claves canónicas y fallbacks de negocio
     */
    public static function normalize(array $columns): array
    {
        /* Resultado acumulado; no sobrescribimos una clave canónica ya definida. */
        $normalized_columns = [];

        foreach ($columns as $property_key => $column_position) {
            /* Clave canónica según alias del importador de artículos. */
            $canonical_key = self::normalize_property_key($property_key) ?? $property_key;

            if (!isset($normalized_columns[$canonical_key])) {
                $normalized_columns[$canonical_key] = $column_position;
            }
        }

        /*
         * Respaldo si el mapeo quedó en "descripcion" sin "nombre" (la IA y el front
         * deberían enviar ya "nombre" con interpretation_note; esto evita filas omitidas).
         */
        if (
            !isset($normalized_columns['nombre'])
            && isset($normalized_columns['descripcion'])
        ) {
            $normalized_columns['nombre'] = $normalized_columns['descripcion'];
            unset($normalized_columns['descripcion']);
        }

        return $normalized_columns;
    }
}
