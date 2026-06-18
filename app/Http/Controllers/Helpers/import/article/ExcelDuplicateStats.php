<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Article;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Helper estático para calcular estadísticas de duplicados en un archivo Excel
 * antes de ejecutar la importación de artículos.
 *
 * Responsabilidades:
 * - Leer el Excel completo y acumular recuentos de bar_code y provider_code.
 * - Detectar valores repetidos dentro del mismo archivo (duplicados intra-archivo).
 * - Cruzar los provider_codes del Excel contra la tabla articles en base de datos.
 * - Devolver conteos y ejemplos que el caller puede pasar a Claude para
 *   generar una recomendación de configuración óptima para la importación.
 *
 * Este helper NO escribe nada en base de datos ni lanza eventos.
 * Cualquier error interno retorna el resultado vacío para no interrumpir el flujo.
 */
class ExcelDuplicateStats
{
    /**
     * Tamaño máximo de lote para la consulta whereIn a la base de datos.
     * Evita saturar el stack de MySQL con archivos muy grandes.
     *
     * @var int
     */
    protected const DB_CHUNK_SIZE = 5000;

    /**
     * Cantidad máxima de ejemplos que se incluyen en cada lista de valores duplicados.
     *
     * @var int
     */
    protected const MAX_EXAMPLES = 5;

    /**
     * Analiza el Excel y devuelve estadísticas de duplicados.
     *
     * Lee el archivo completo (solo primera hoja, saltando la cabecera) y calcula:
     * - Cuántos bar_codes y provider_codes distintos aparecen más de una vez en el archivo.
     * - Cuántos provider_codes del Excel ya existen en la BD para el mismo proveedor o para otro.
     *
     * Si ambos índices son null, retorna todos los conteos en 0 sin leer el archivo.
     * Si uno de los índices es null, los conteos de ese campo retornan 0.
     *
     * @param  string   $excel_path                          Ruta absoluta al archivo Excel
     * @param  int|null $bar_code_column_index_0based        Índice 0-based de la columna bar_code (null si no identificada)
     * @param  int|null $provider_code_column_index_0based   Índice 0-based de la columna provider_code (null si no identificada)
     * @param  int|null $provider_id                         ID del proveedor seleccionado (null o 0 si no se pudo inferir)
     * @param  int      $user_id                             ID del usuario propietario para filtrar artículos en BD
     * @return array    Conteos y ejemplos según el contrato:
     *                  [
     *                      'total_filas_datos'                          => int,
     *                      'bar_codes_duplicados_intra_archivo'         => int,
     *                      'provider_codes_duplicados_intra_archivo'    => int,
     *                      'provider_codes_existentes_mismo_proveedor'  => int,
     *                      'provider_codes_existentes_otros_proveedores'=> int,
     *                      'ejemplos_bar_codes_duplicados'              => string[],
     *                      'ejemplos_provider_codes_duplicados'         => string[],
     *                  ]
     */
    public static function analyze(
        string $excel_path,
        ?int $bar_code_column_index_0based,
        ?int $provider_code_column_index_0based,
        ?int $provider_id,
        int $user_id
    ): array {
        /* Resultado vacío por defecto: se retorna cuando no hay columnas definidas o si ocurre un error. */
        $empty_result = [
            'total_filas_datos'                           => 0,
            'bar_codes_duplicados_intra_archivo'          => 0,
            'provider_codes_duplicados_intra_archivo'     => 0,
            'provider_codes_existentes_mismo_proveedor'   => 0,
            'provider_codes_existentes_otros_proveedores' => 0,
            'ejemplos_bar_codes_duplicados'               => [],
            'ejemplos_provider_codes_duplicados'          => [],
        ];

        /* Si no hay ningún índice definido, no tiene sentido leer el archivo. */
        if (is_null($bar_code_column_index_0based) && is_null($provider_code_column_index_0based)) {
            Log::info('ExcelDuplicateStats: sin columnas de código definidas, retornando vacío.');
            return $empty_result;
        }

        /*
         * Acumuladores: clave = valor normalizado de la celda, valor = cantidad de ocurrencias.
         * Se usan para detectar duplicados dentro del propio archivo (intra-archivo).
         */
        $bar_code_counts    = [];
        /* provider_code_vals también sirve para el cruce posterior contra la BD. */
        $provider_code_vals = [];

        /* Contador de filas de datos procesadas (sin contar la cabecera). */
        $total_filas = 0;

        try {
            /*
             * Usamos el mismo lector XLSX de OpenSpout que InitExcelImport
             * para garantizar compatibilidad de formatos.
             */
            $reader = ReaderEntityFactory::createXLSXReader();
            /* No preservamos filas vacías para no inflar el conteo total. */
            $reader->setShouldPreserveEmptyRows(false);
            $reader->open($excel_path);

            foreach ($reader->getSheetIterator() as $sheet) {
                /* Bandera para saltar la primera fila (cabecera). */
                $header_skipped = false;

                foreach ($sheet->getRowIterator() as $row) {
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue;
                    }

                    $total_filas++;

                    /* Extraemos los valores de las celdas como strings simples. */
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();

                        if ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d');
                        }

                        $cells[] = trim((string) ($value ?? ''));
                    }

                    /* Acumular bar_code si la columna está definida y la celda tiene contenido. */
                    if (!is_null($bar_code_column_index_0based) && isset($cells[$bar_code_column_index_0based])) {
                        $bar_code_val = $cells[$bar_code_column_index_0based];
                        if ($bar_code_val !== '') {
                            $bar_code_counts[$bar_code_val] = ($bar_code_counts[$bar_code_val] ?? 0) + 1;
                        }
                    }

                    /* Acumular provider_code si la columna está definida y la celda tiene contenido. */
                    if (!is_null($provider_code_column_index_0based) && isset($cells[$provider_code_column_index_0based])) {
                        $provider_code_val = $cells[$provider_code_column_index_0based];
                        if ($provider_code_val !== '') {
                            $provider_code_vals[$provider_code_val] = ($provider_code_vals[$provider_code_val] ?? 0) + 1;
                        }
                    }
                }

                /* Solo procesamos la primera hoja del libro. */
                break;
            }

            $reader->close();

        } catch (\Throwable $e) {
            Log::error('ExcelDuplicateStats: error al leer el Excel', [
                'message' => $e->getMessage(),
                'file'    => $excel_path,
            ]);
            return $empty_result;
        }

        Log::info('ExcelDuplicateStats: Excel leído', [
            'total_filas'              => $total_filas,
            'bar_codes_distintos'      => count($bar_code_counts),
            'provider_codes_distintos' => count($provider_code_vals),
        ]);

        /*
         * Contamos cuántos valores distintos de bar_code aparecen más de una vez en el archivo.
         * Guardamos hasta MAX_EXAMPLES para debug o presentación en frontend.
         */
        $bar_codes_duplicados = 0;
        $ejemplos_bar_codes   = [];
        foreach ($bar_code_counts as $val => $count) {
            if ($count > 1) {
                $bar_codes_duplicados++;
                if (count($ejemplos_bar_codes) < self::MAX_EXAMPLES) {
                    $ejemplos_bar_codes[] = (string) $val;
                }
            }
        }

        /*
         * Contamos cuántos valores distintos de provider_code aparecen más de una vez en el archivo.
         */
        $provider_codes_duplicados_intra = 0;
        $ejemplos_provider_codes         = [];
        foreach ($provider_code_vals as $val => $count) {
            if ($count > 1) {
                $provider_codes_duplicados_intra++;
                if (count($ejemplos_provider_codes) < self::MAX_EXAMPLES) {
                    $ejemplos_provider_codes[] = (string) $val;
                }
            }
        }

        /*
         * Cruzamos los provider_codes únicos extraídos del Excel contra la tabla articles en BD.
         * Solo si la columna provider_code existe y tiene datos para cruzar.
         * Procesamos en chunks para no saturar la consulta con archivos de miles de filas.
         */
        $provider_codes_mismo_proveedor   = 0;
        $provider_codes_otros_proveedores = 0;

        if (!is_null($provider_code_column_index_0based) && !empty($provider_code_vals)) {
            /* Lista de provider_codes únicos del Excel. */
            $all_provider_codes = array_keys($provider_code_vals);

            /* Partimos en lotes de DB_CHUNK_SIZE para no reventar la consulta. */
            $db_chunks = array_chunk($all_provider_codes, self::DB_CHUNK_SIZE);

            foreach ($db_chunks as $chunk) {
                /*
                 * Buscamos artículos del mismo usuario con cualquiera de esos provider_codes.
                 * Solo traemos las columnas necesarias para el cruce.
                 */
                $matches = Article::where('user_id', $user_id)
                    ->whereIn('provider_code', $chunk)
                    ->get(['provider_code', 'provider_id']);

                foreach ($matches as $article) {
                    /*
                     * Consideramos "mismo proveedor" solo si se proporcionó un provider_id válido
                     * y el artículo en BD pertenece a ese mismo proveedor.
                     */
                    $is_same_provider = (
                        !is_null($provider_id)
                        && (int) $provider_id > 0
                        && (int) $article->provider_id === (int) $provider_id
                    );

                    if ($is_same_provider) {
                        $provider_codes_mismo_proveedor++;
                    } else {
                        $provider_codes_otros_proveedores++;
                    }
                }
            }
        }

        Log::info('ExcelDuplicateStats: análisis completado', [
            'total_filas'                               => $total_filas,
            'bar_codes_duplicados_intra_archivo'        => $bar_codes_duplicados,
            'provider_codes_duplicados_intra_archivo'   => $provider_codes_duplicados_intra,
            'provider_codes_existentes_mismo_proveedor' => $provider_codes_mismo_proveedor,
            'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
        ]);

        return [
            'total_filas_datos'                           => $total_filas,
            'bar_codes_duplicados_intra_archivo'          => $bar_codes_duplicados,
            'provider_codes_duplicados_intra_archivo'     => $provider_codes_duplicados_intra,
            'provider_codes_existentes_mismo_proveedor'   => $provider_codes_mismo_proveedor,
            'provider_codes_existentes_otros_proveedores' => $provider_codes_otros_proveedores,
            'ejemplos_bar_codes_duplicados'               => $ejemplos_bar_codes,
            'ejemplos_provider_codes_duplicados'          => $ejemplos_provider_codes,
        ];
    }
}
