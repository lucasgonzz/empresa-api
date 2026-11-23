<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Http\Controllers\Helpers\import\excel\EncabezadosRepetidos;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Models\Article;
use App\Models\Provider;
use Illuminate\Support\Facades\Log;

/**
 * Misión historial-precios-proveedor: detecta, ANTES de importar, qué filas del Excel corresponden
 * (por código de barras o SKU) a artículos que el dueño ya tiene cargados con OTRO proveedor.
 *
 * Con ese dato el paso 3 del importador de 4 pasos decide si hace las dos preguntas nuevas
 * ("¿actualizo el costo de los que ya tengo con otro proveedor?" y "¿pasan a ser de este proveedor?")
 * o si no hay nada que preguntar.
 *
 * Reglas:
 * - Sólo se cruza por código de barras y SKU (no por nombre ni por código de proveedor: el cruce por
 *   código de proveedor tiene su propia pregunta). Es un piso ("al menos N"): la importación también
 *   puede cruzar por nombre exacto.
 * - Cada fila se identifica por su PRIMER identificador no vacío (bar_code; si no hay, sku), igual que
 *   la cadena de la importación (IdentifierNormalizer: sin placeholders).
 * - Una fila cuenta si entre los artículos que coinciden NINGUNO es del proveedor elegido y alguno
 *   tiene `provider_id` de otro proveedor.
 * - Los valores se consultan como STRING (strval) y por tandas de 1000: un entero dentro del IN de una
 *   columna string tumbó la importación de Servian (ver ExcelDuplicateStats).
 * - Sin proveedor elegido o sin columnas de barras/SKU mapeadas: todo en cero.
 *
 * Es de sólo lectura: nunca escribe en la base.
 */
class OtherProviderArticlesInExcel
{
    /** Tamaño de las tandas del whereIn (mismo criterio que ExcelDuplicateStats). */
    const TANDA_DE_CONSULTA = 1000;

    /** Cantidad máxima de ejemplos que se devuelven. */
    const MAXIMO_DE_EJEMPLOS = 5;

    /**
     * Resultado vacío (la clave siempre viaja con la bandera, aunque sea en cero).
     *
     * @return array
     */
    public static function empty_result()
    {
        return [
            'articles'    => 0,
            'rows'        => 0,
            'by_provider' => [],
            'examples'    => [],
        ];
    }

    /**
     * Cuenta las filas y artículos del Excel que ya existen con otro proveedor.
     *
     * @param string $excel_path Ruta absoluta al Excel guardado en storage
     * @param array $column_mapping Mapeo de columnas confirmado (con 'excel_column_index')
     * @param int|null $provider_id Proveedor elegido en el paso 1
     * @param int $user_id Dueño de los artículos
     * @param int $sheet_index Hoja 0-based elegida
     * @param int|null $header_row Fila 1-based del encabezado (null o 1 = numeración de siempre)
     * @return array ['articles' => int, 'rows' => int, 'by_provider' => [...], 'examples' => [...]]
     */
    public static function count(string $excel_path, array $column_mapping, $provider_id, int $user_id, $sheet_index = 0, $header_row = null): array
    {
        $result = self::empty_result();

        try {
            if (empty($provider_id) || (int) $provider_id <= 0) {
                return $result;
            }

            // Índices 0-based de las columnas identificadoras (null si no están mapeadas).
            $bar_code_index = self::column_index($column_mapping, 'codigo_de_barras');
            $sku_index      = self::column_index($column_mapping, 'sku');
            $name_index     = self::column_index($column_mapping, 'nombre');

            if (is_null($bar_code_index) && is_null($sku_index)) {
                return $result;
            }

            // Filas del Excel con su identificador: [['row' => int, 'field' => 'bar_code'|'sku', 'value' => string, 'name' => string]]
            $excel_rows = self::read_identifiers($excel_path, $bar_code_index, $sku_index, $name_index, $sheet_index, $header_row);

            if (empty($excel_rows)) {
                return $result;
            }

            // Artículos del dueño que coinciden: ['bar_code'|'sku' => [valor => [[id, name, provider_id], ...]]]
            $matches = self::find_articles($excel_rows, $user_id);

            // Nombres de los proveedores de otros: se resuelven con UNA consulta al final.
            $detected_articles   = [];   // article_id => provider_id
            $articles_by_provider = [];  // provider_id => [article_id => true]
            $rows = 0;
            $examples = [];

            foreach ($excel_rows as $excel_row) {
                $candidates = isset($matches[$excel_row['field']][$excel_row['value']])
                    ? $matches[$excel_row['field']][$excel_row['value']]
                    : [];

                if (empty($candidates)) {
                    continue;
                }

                $is_selected_provider = false;
                $other_candidates = [];

                foreach ($candidates as $candidate) {
                    if ((int) $candidate['provider_id'] === (int) $provider_id) {
                        $is_selected_provider = true;
                    } elseif (!empty($candidate['provider_id'])) {
                        $other_candidates[] = $candidate;
                    }
                }

                // Si alguno ya es del proveedor elegido, la fila se resuelve ahí: no hay nada que preguntar.
                if ($is_selected_provider || empty($other_candidates)) {
                    continue;
                }

                $rows++;

                foreach ($other_candidates as $candidate) {
                    $detected_articles[$candidate['id']] = (int) $candidate['provider_id'];
                    $articles_by_provider[(int) $candidate['provider_id']][$candidate['id']] = true;
                }

                if (count($examples) < self::MAXIMO_DE_EJEMPLOS) {
                    $examples[] = [
                        'row'              => $excel_row['row'],
                        'name'             => (string) $other_candidates[0]['name'],
                        'current_provider' => (int) $other_candidates[0]['provider_id'],
                    ];
                }
            }

            if (empty($detected_articles)) {
                return $result;
            }

            // Una consulta para los nombres de los proveedores involucrados.
            $provider_names = Provider::whereIn('id', array_keys($articles_by_provider))
                ->pluck('name', 'id')
                ->all();

            $by_provider = [];
            foreach ($articles_by_provider as $other_provider_id => $article_ids) {
                $by_provider[] = [
                    'provider_id' => (int) $other_provider_id,
                    'name'        => isset($provider_names[$other_provider_id]) ? (string) $provider_names[$other_provider_id] : '',
                    'articles'    => count($article_ids),
                ];
            }

            foreach ($examples as $index => $example) {
                $examples[$index]['current_provider'] = isset($provider_names[$example['current_provider']])
                    ? (string) $provider_names[$example['current_provider']]
                    : '';
            }

            return [
                'articles'    => count($detected_articles),
                'rows'        => $rows,
                'by_provider' => $by_provider,
                'examples'    => $examples,
            ];
        } catch (\Throwable $e) {
            // La detección es una ayuda para el paso 3: si falla, el paso 3 se dibuja sin las preguntas.
            Log::warning('OtherProviderArticlesInExcel: no se pudo detectar artículos de otros proveedores', [
                'message' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * Índice 0-based de la columna mapeada a una propiedad del sistema.
     *
     * @param array $column_mapping Mapeo confirmado
     * @param string $system_property 'codigo_de_barras' | 'sku' | 'nombre'
     * @return int|null
     */
    protected static function column_index(array $column_mapping, string $system_property)
    {
        foreach ($column_mapping as $column) {
            if (($column['system_property'] ?? null) === $system_property && isset($column['excel_column_index'])) {
                return (int) $column['excel_column_index'];
            }
        }

        return null;
    }

    /**
     * Lee el Excel y devuelve, por fila de datos, su primer identificador no vacío (barras o SKU).
     * Usa el mismo lector, la misma regla de encabezados repetidos y el mismo normalizador que
     * AiExcelAnalyzer::analyze_identification_chain(), para que los números de fila y los
     * placeholders coincidan con lo que ve el usuario y con lo que hace la importación.
     *
     * @param string $excel_path Ruta absoluta
     * @param int|null $bar_code_index Columna de barras
     * @param int|null $sku_index Columna de SKU
     * @param int|null $name_index Columna de nombre (sólo para ejemplos)
     * @param int $sheet_index Hoja 0-based
     * @param int|null $header_row Fila 1-based del encabezado
     * @return array
     */
    protected static function read_identifiers($excel_path, $bar_code_index, $sku_index, $name_index, $sheet_index, $header_row): array
    {
        $rows = [];

        // Misma bifurcación que analyze_identification_chain(): con encabezado > 1 los números son físicos.
        $use_physical_numbering = (!is_null($header_row) && (int) $header_row > 1);
        $header_row = is_null($header_row) ? 1 : (int) $header_row;

        $repeated_header_rule = EncabezadosRepetidos::leer($excel_path, (int) $sheet_index);

        $reader = ExcelWorkbookReader::abrir($excel_path, $sheet_index, $use_physical_numbering);

        $header_skipped = false;
        $physical_number = 0;
        $data_row_index = 0;

        foreach ($reader->filas() as $row) {
            $physical_number++;

            if ($use_physical_numbering) {
                if ($physical_number <= $header_row) {
                    continue;
                }
            } elseif (!$header_skipped) {
                $header_skipped = true;
                continue;
            }

            $cells = [];
            $is_empty = true;
            foreach ($row->getCells() as $cell) {
                $value = $cell->getValue();

                if ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d');
                }

                $text = trim((string) ($value ?? ''));
                if ($text !== '') {
                    $is_empty = false;
                }
                $cells[] = $text;
            }

            if ($use_physical_numbering && $is_empty) {
                continue;
            }

            if (EncabezadosRepetidos::es_encabezado($cells, $use_physical_numbering ? $physical_number : null, $repeated_header_rule)) {
                continue;
            }

            $data_row_index++;
            $excel_row_number = $use_physical_numbering ? $physical_number : ($data_row_index + 1);

            $bar_code = !is_null($bar_code_index) ? IdentifierNormalizer::normalize($cells[$bar_code_index] ?? null) : null;
            $sku      = !is_null($sku_index) ? IdentifierNormalizer::normalize($cells[$sku_index] ?? null) : null;

            // Primer identificador no vacío: barras; si no hay, SKU.
            if (!is_null($bar_code)) {
                $field = 'bar_code';
                $value = strval($bar_code);
            } elseif (!is_null($sku)) {
                $field = 'sku';
                $value = strval($sku);
            } else {
                continue;
            }

            $rows[] = [
                'row'   => $excel_row_number,
                'field' => $field,
                'value' => $value,
                'name'  => !is_null($name_index) ? (string) ($cells[$name_index] ?? '') : '',
            ];
        }

        return $rows;
    }

    /**
     * Busca los artículos del dueño cuyos barras/SKU coinciden con los del Excel, por tandas de
     * 1000 y con los valores como string.
     *
     * @param array $excel_rows Salida de read_identifiers()
     * @param int $user_id Dueño
     * @return array ['bar_code'|'sku' => [valor => [['id' => int, 'name' => string, 'provider_id' => int|null], ...]]]
     */
    protected static function find_articles(array $excel_rows, int $user_id): array
    {
        $values_by_field = ['bar_code' => [], 'sku' => []];

        foreach ($excel_rows as $excel_row) {
            $values_by_field[$excel_row['field']][$excel_row['value']] = $excel_row['value'];
        }

        $matches = ['bar_code' => [], 'sku' => []];

        foreach ($values_by_field as $field => $values) {
            if (empty($values)) {
                continue;
            }

            foreach (array_chunk(array_values($values), self::TANDA_DE_CONSULTA) as $chunk) {
                $articles = Article::where('user_id', $user_id)
                    ->whereIn($field, $chunk)
                    ->get(['id', 'name', 'bar_code', 'sku', 'provider_id']);

                foreach ($articles as $article) {
                    $matches[$field][strval($article->{$field})][] = [
                        'id'          => (int) $article->id,
                        'name'        => $article->name,
                        'provider_id' => $article->provider_id,
                    ];
                }
            }
        }

        return $matches;
    }
}
