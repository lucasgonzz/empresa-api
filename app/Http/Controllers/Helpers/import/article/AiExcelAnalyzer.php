<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Address;
use App\Models\Provider;
use App\Models\User;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;

/**
 * Helper que analiza un archivo Excel utilizando la API de Claude (Anthropic).
 *
 * Responsabilidades:
 * 1. Leer las primeras filas del Excel usando OpenSpout.
 * 2. Armar un payload con headers + muestra de datos + proveedores disponibles.
 * 3. Llamar a la API de Claude y devolver el JSON de mapeo de columnas parseado.
 *
 * Este helper NO guarda nada en base de datos; solo analiza y retorna sugerencias.
 */
class AiExcelAnalyzer
{
    /**
     * Cantidad de filas de muestra que se envían a Claude (excluye la cabecera).
     *
     * @var int
     */
    protected const SAMPLE_ROWS = 10;

    /**
     * Modelo de Claude a utilizar para el análisis.
     *
     * @var string
     */
    protected const CLAUDE_MODEL = 'claude-sonnet-4-5';

    /**
     * Tokens máximos para la respuesta de Claude.
     *
     * Se eleva a 4000 porque al incluir depósitos y listas de precio el prompt
     * es más largo y la respuesta (column_mapping con propiedades codificadas) puede ser más extensa.
     *
     * @var int
     */
    protected const MAX_TOKENS = 4000;

    /**
     * Umbral del fallback heurístico de politica_colision (grupo 284, prompt 02): proporción
     * mínima de provider_codes duplicados dentro del archivo, respecto del total de filas de
     * datos, a partir de la cual el fallback prefiere no arriesgarse y recomienda
     * "saltear_y_reportar" en vez de "actualizar_todos". Ver ask_claude_for_recomendation().
     *
     * @var float
     */
    protected const UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK = 0.3;

    /**
     * Columnas numéricas que se analizan para la alerta de números con punto
     * ambiguos (grupo 239, prompt 02): las mismas que ProcessRow marca con
     * is_number en $props_to_add, más las de stock. Los valores son los
     * system_property tal como viajan en column_mapping.
     *
     * @var array
     */
    protected const NUMERIC_COLUMNS_FOR_FORMAT_STATS = [
        'costo',
        'precio',
        'margen_de_ganancia',
        'stock_actual',
        'stock_minimo',
        'medida',
        'u_individuales',
    ];

    /**
     * Lista de propiedades del sistema importables que Claude puede identificar.
     * Deben coincidir exactamente con los valores que el frontend puede manejar.
     *
     * @var array
     */
    protected const SYSTEM_PROPERTIES = [
        'numero',
        'nombre',
        'codigo_de_barras',
        'sku',
        'codigo_de_proveedor',
        'costo',
        'precio',
        'iva',
        'margen_de_ganancia',
        'categoria',
        'sub_categoria',
        'marca',
        'descripcion',
        'stock_actual',
        'descuentos',
        'descuentos_montos',
        'recargos',
        'recargos_montos',
        'proveedor',
        // Propiedades planas adicionales importables (ya existen en BD y en ProcessRow).
        'costo_en_dolares',
        'aplicar_iva',
        'unidad_medida',
        'u_individuales',
        'medida',
        'contenido',
        'in_offer',
        'online',
        'precio_pausado',
        'disponible_tienda_nube',
    ];

    /**
     * ID del usuario propietario, para cargar sus proveedores disponibles.
     *
     * @var int
     */
    protected $user_id;

    /**
     * Crea una instancia del analizador para el usuario indicado.
     *
     * @param int $user_id  ID del usuario dueño (owner) de la importación
     */
    public function __construct(int $user_id)
    {
        $this->user_id = $user_id;
    }

    /**
     * Analiza el Excel recibido y devuelve el mapeo de columnas sugerido por Claude.
     *
     * @param  string $excel_path           Ruta absoluta al archivo Excel ya guardado en storage
     * @param  string $original_filename    Nombre del archivo tal como lo subió el usuario (pista para proveedor)
     * @return array                        Array con claves: column_mapping, provider_id, provider_confidence
     *
     * @throws \RuntimeException  Si el archivo no puede leerse o Claude no devuelve JSON válido
     */
    public function analyze(string $excel_path, string $original_filename = ''): array
    {
        /*
         * Paso 1: Leer headers y filas de muestra del Excel.
         * Limitamos la lectura para no cargar archivos grandes en memoria.
         */
        $sample_data = $this->read_sample_rows($excel_path);

        /*
         * Paso 2: Cargar proveedores disponibles del usuario para que Claude
         * pueda inferir a qué proveedor corresponde el listado.
         */
        $providers = $this->get_available_providers();

        /*
         * Cargamos las sucursales (addresses) del usuario para que Claude aplique
         * la regla de stock por sucursal. Solo necesitamos id y street para el prompt.
         */
        $addresses = $this->get_available_addresses();

        /*
         * Cargamos las listas de precio (price types) del usuario para que Claude
         * pueda mapear columnas de precio/margen por lista. Solo id y name para el prompt.
         */
        $price_types = $this->get_available_price_types();

        /*
         * Paso 3: Construir el prompt y llamar a Claude.
         */
        $prompt = $this->build_prompt($sample_data, $providers, $original_filename, $addresses, $price_types);

        $claude_response = $this->call_claude($prompt);

        /*
         * Paso 4: Parsear y validar el JSON devuelto por Claude.
         * Pasamos addresses y price_types para validar los IDs codificados que devuelva Claude.
         */
        $parsed = $this->parse_claude_response($claude_response, $providers, $addresses, $price_types);

        /*
         * Paso 5: Enriquecer cada columna con letra Excel, índice 0-based y confianza normalizada
         * para que el frontend muestre A, B, C… y use la posición real al importar.
         */
        $parsed['column_mapping'] = $this->enrich_column_mapping(
            $parsed['column_mapping'],
            $sample_data['headers']
        );

        $parsed['column_mapping'] = $this->apply_nombre_descripcion_interpretation_rules(
            $parsed['column_mapping']
        );

        /*
         * Paso 6: Contar el total real de filas de datos del Excel (excluye cabecera)
         * para que el caller pueda informarlo al cliente sin estimaciones heurísticas.
         */
        $parsed['row_count'] = $this->count_data_rows($excel_path);

        /*
         * Paso 7: Extraer los índices 0-based de bar_code y provider_code del mapeo enriquecido
         * para pasarlos al analizador de duplicados.
         */
        $bar_code_idx      = null;
        $provider_code_idx = null;
        foreach ($parsed['column_mapping'] as $col) {
            if (($col['system_property'] ?? null) === 'codigo_de_barras') {
                $bar_code_idx = $col['excel_column_index'] ?? null;
            }
            if (($col['system_property'] ?? null) === 'codigo_de_proveedor') {
                $provider_code_idx = $col['excel_column_index'] ?? null;
            }
        }

        Log::info('AiExcelAnalyzer: índices de columnas detectados para preanálisis', [
            'bar_code_idx'      => $bar_code_idx,
            'provider_code_idx' => $provider_code_idx,
        ]);

        /*
         * Paso 8: Preanálisis de duplicados.
         * Calcula conteos intra-archivo y cruza contra BD para detectar colisiones.
         * Nunca lanza excepción hacia el caller; en caso de error retorna conteos en 0.
         */
        $parsed['duplicate_stats'] = ExcelDuplicateStats::analyze(
            $excel_path,
            $bar_code_idx,
            $provider_code_idx,
            $parsed['provider_id'] ?? null,
            $this->user_id
        );

        /*
         * Paso 8.1: Análisis de la cadena de identificación efectiva (prompt 06, grupo 229).
         * Detecta valores placeholder ("-", "S/N", etc.) en las columnas identificadoras y
         * calcula, con el MISMO normalizador y el MISMO orden de prioridad que usa el
         * matching real (ArticleIndexCache::find_with_index), cuántas filas van a caer en
         * cada escalón (id -> bar_code -> sku -> provider_code -> name -> sin_identificador).
         * Esto es lo que permite mostrarle al usuario, antes de importar, con qué columna se
         * va a identificar cada fila (el problema real de Servian del 24/07 fue no saberlo).
         */
        $identification_chain_analysis = $this->analyze_identification_chain(
            $excel_path,
            $parsed['column_mapping']
        );
        $parsed['placeholders']          = $identification_chain_analysis['placeholders'];
        $parsed['cadena_identificacion'] = $identification_chain_analysis['cadena_identificacion'];
        $parsed['nombres_duplicados']    = $identification_chain_analysis['nombres_duplicados'];

        /*
         * Paso 8.2 (grupo 239, prompt 02): detección de números con punto ambiguos en las
         * columnas numéricas del mapeo (costo, precio, margen de ganancia, stock, medida y
         * unidades individuales). Esto es lo que alimenta la alerta de "números con punto"
         * del paso 3 del modal, al lado de duplicate_stats y placeholders. Usa el mapeo
         * enriquecido con el proveedor todavía inferido por Claude; se recalcula más tarde
         * en /get-recomendacion con el column_mapping confirmado por el usuario.
         */
        $numeric_columns_map = $this->build_numeric_columns_map($parsed['column_mapping']);
        $parsed['formatos_numericos'] = ExcelNumericFormatStats::analyze(
            $excel_path,
            $numeric_columns_map['indices'],
            $numeric_columns_map['nombres']
        );

        /*
         * Nota: la recomendación de configuración (ask_claude_for_recomendation) ya no se genera
         * aquí porque en este punto el proveedor puede ser solo inferido por Claude y no el
         * confirmado por el usuario. La recomendación se genera en el endpoint
         * POST /ai-excel-import/get-recomendacion, una vez que el usuario confirma el proveedor
         * en el paso 2 del modal.
         */

        /*
         * Primeras 5 filas de datos del Excel para la preview reactiva del paso 2 en el frontend.
         */
        $parsed['preview_rows'] = array_slice($sample_data['rows'], 0, 5);

        /*
         * Advertencias de alto nivel generadas por Claude para mostrar al usuario
         * antes de la tabla de mapeo. Si no vino el campo, retornamos array vacío.
         */
        $parsed['assistant_notes'] = $parsed['assistant_notes'] ?? [];

        return $parsed;
    }

    /**
     * Recorre el column_mapping enriquecido y arma los dos arrays que necesita
     * ExcelNumericFormatStats::analyze(): índices 0-based por columna numérica y
     * nombres visibles del Excel por columna, filtrando solo las siete columnas
     * numéricas de NUMERIC_COLUMNS_FOR_FORMAT_STATS y salteando las que no tengan
     * excel_column_index.
     *
     * Público porque lo reusa AiExcelImportController::getRecomendacion() para
     * recalcular con el column_mapping confirmado por el usuario, en vez de
     * duplicar la lógica de derivar índices en el controller (grupo 239, prompt 02).
     *
     * @param  array $column_mapping  Mapeo de columnas enriquecido (con excel_column_index)
     * @return array  ['indices' => [system_property => excel_column_index], 'nombres' => [system_property => nombre visible]]
     */
    public function build_numeric_columns_map(array $column_mapping): array
    {
        /* Índices 0-based de columna en el Excel, por system_property numérico. */
        $indices = [];
        /* Nombre visible de la columna en el Excel (excel_column), por system_property numérico. */
        $nombres = [];

        foreach ($column_mapping as $col) {
            $prop = $col['system_property'] ?? null;

            /* Solo nos interesan las siete columnas numéricas relevantes para esta alerta. */
            if (is_null($prop) || !in_array($prop, self::NUMERIC_COLUMNS_FOR_FORMAT_STATS, true)) {
                continue;
            }

            /* Sin índice de columna en el Excel no hay nada que leer para esta propiedad. */
            if (!isset($col['excel_column_index'])) {
                continue;
            }

            $indices[$prop] = (int) $col['excel_column_index'];
            /* Nombre visible de la columna; si no vino, ExcelNumericFormatStats cae al campo. */
            $nombres[$prop] = isset($col['excel_column']) ? (string) $col['excel_column'] : $prop;
        }

        return [
            'indices' => $indices,
            'nombres' => $nombres,
        ];
    }

    /**
     * Analiza los identificadores del Excel para el prompt 06 (grupo 229 —
     * matching-importacion-excel): detecta valores "placeholder" (códigos que no son
     * reales, ej. "-", "S/N") en las columnas identificadoras mapeadas, y calcula
     * cuántas filas van a caer en cada escalón de la cadena de identificación real
     * que usa el importador (ArticleIndexCache::find_with_index):
     * id -> bar_code -> sku -> provider_code -> name -> sin_identificador.
     *
     * Usa IdentifierNormalizer::normalize()/is_placeholder() — el mismo normalizador
     * que ProcessRow usa al importar — para que estos conteos coincidan exactamente
     * con lo que va a pasar en la importación real. Si divergieran, la pantalla le
     * mentiría al usuario sobre lo que va a pasar con su archivo.
     *
     * @param  string $excel_path      Ruta absoluta al Excel ya guardado en storage
     * @param  array  $column_mapping  Mapeo de columnas enriquecido (con excel_column_index)
     * @return array  ['placeholders' => [...], 'cadena_identificacion' => [...], 'nombres_duplicados' => [...]]
     */
    protected function analyze_identification_chain(string $excel_path, array $column_mapping): array
    {
        /* Resultado vacío por defecto: se retorna si no hay ninguna columna identificadora mapeada, o si falla la lectura. */
        $empty_result = [
            'placeholders' => [],
            'cadena_identificacion' => [
                'columnas_mapeadas' => [],
                'disponible' => false,
                'motivo' => null,
                'total_filas' => 0,
                'escalones' => [
                    ['campo' => 'id',                'filas' => 0],
                    ['campo' => 'bar_code',           'filas' => 0],
                    ['campo' => 'sku',                'filas' => 0],
                    ['campo' => 'provider_code',      'filas' => 0],
                    ['campo' => 'name',               'filas' => 0],
                    ['campo' => 'sin_identificador',  'filas' => 0],
                ],
            ],
            'nombres_duplicados' => [
                'cantidad_distintos' => 0,
                'filas_afectadas'    => 0,
            ],
        ];

        /*
         * Mapa campo interno -> system_property del mapeo, en el mismo orden de
         * prioridad que usa el matching real (ArticleIndexCache::find_with_index).
         */
        $campo_a_property = [
            'id'            => 'numero',
            'bar_code'      => 'codigo_de_barras',
            'sku'           => 'sku',
            'provider_code' => 'codigo_de_proveedor',
            'name'          => 'nombre',
        ];

        /* Índice 0-based de cada columna identificadora en el Excel, o null si no está mapeada. */
        $indices = [];
        foreach ($campo_a_property as $campo => $property) {
            $indices[$campo] = null;
            foreach ($column_mapping as $col) {
                if (($col['system_property'] ?? null) === $property) {
                    $indices[$campo] = $col['excel_column_index'] ?? null;
                    break;
                }
            }
        }

        /* Lista de campos que efectivamente tienen columna mapeada en este Excel. */
        $columnas_mapeadas = [];
        foreach ($indices as $campo => $idx) {
            if (!is_null($idx)) {
                $columnas_mapeadas[] = $campo;
            }
        }

        /* Sin ninguna columna identificadora mapeada, no tiene sentido leer el archivo completo. */
        if (empty($columnas_mapeadas)) {
            $empty_result['cadena_identificacion']['motivo'] = 'sin_columnas_identificadoras';

            /* Propiedades del sistema que sí llegaron en el column_mapping: permite ver de un
             * vistazo si el mapeo vino vacío, si vino con otros nombres de propiedad, o si el
             * problema es otro. */
            $system_properties_presentes = [];
            foreach ($column_mapping as $col) {
                $prop = $col['system_property'] ?? null;
                if (!is_null($prop)) {
                    $system_properties_presentes[] = $prop;
                }
            }

            Log::warning('AiExcelAnalyzer: cadena de identificación no disponible, sin columnas identificadoras mapeadas', [
                'excel_path'        => $excel_path,
                'system_properties' => $system_properties_presentes,
            ]);

            return $empty_result;
        }

        /* Acumulador de placeholders: campo -> valor original de la celda -> ['count' => N, 'filas' => [...]]. */
        $placeholders_data = [];

        /* Conteo de filas por escalón de la cadena de identificación. */
        $escalon_counts = [
            'id' => 0, 'bar_code' => 0, 'sku' => 0,
            'provider_code' => 0, 'name' => 0, 'sin_identificador' => 0,
        ];

        /* Acumulador de nombres normalizados -> cantidad de filas, para detectar nombres repetidos. */
        $nombres_data = [];

        /* Cantidad de filas de datos efectivamente recorridas (excluye cabecera). Se hoistea acá
         * porque el try/catch de abajo la necesita en el return final, fuera del scope del foreach. */
        $data_row_index = 0;

        try {
            /* Mismo lector XLSX de OpenSpout que el resto del análisis, leyendo el archivo completo. */
            $reader = ReaderEntityFactory::createXLSXReader();
            $reader->setShouldPreserveEmptyRows(false);
            $reader->open($excel_path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $header_skipped = false;
                /* Contador de filas de datos (sin contar la cabecera). */
                $data_row_index = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue;
                    }

                    $data_row_index++;
                    /* Número de fila real en el Excel (1-based, incluye cabecera): la fila 1 es cabecera. */
                    $excel_row_number = $data_row_index + 1;

                    /* Extraemos los valores de las celdas como strings simples. */
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $value = $cell->getValue();

                        if ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d');
                        }

                        $cells[] = trim((string) ($value ?? ''));
                    }

                    /* Detectar placeholders en cada columna identificadora mapeada. */
                    foreach ($indices as $campo => $idx) {
                        if (is_null($idx) || !isset($cells[$idx])) {
                            continue;
                        }

                        $raw_value = $cells[$idx];
                        if ($raw_value === '') {
                            continue;
                        }

                        if (IdentifierNormalizer::is_placeholder($raw_value)) {
                            if (!isset($placeholders_data[$campo][$raw_value])) {
                                $placeholders_data[$campo][$raw_value] = ['count' => 0, 'filas' => []];
                            }
                            $placeholders_data[$campo][$raw_value]['count']++;
                            /* Limitamos a las primeras 10 filas por valor, igual que ExcelDuplicateStats. */
                            if (count($placeholders_data[$campo][$raw_value]['filas']) < 10) {
                                $placeholders_data[$campo][$raw_value]['filas'][] = $excel_row_number;
                            }
                        }
                    }

                    /*
                     * Cadena de identificación efectiva: misma prioridad y mismo normalizador
                     * que ArticleIndexCache::find_with_index (id -> bar_code -> sku -> provider_code -> name).
                     */
                    $id_val            = !is_null($indices['id'])            ? IdentifierNormalizer::normalize($cells[$indices['id']] ?? null)            : null;
                    $bar_code_val      = !is_null($indices['bar_code'])      ? IdentifierNormalizer::normalize($cells[$indices['bar_code']] ?? null)      : null;
                    $sku_val           = !is_null($indices['sku'])           ? IdentifierNormalizer::normalize($cells[$indices['sku']] ?? null)           : null;
                    $provider_code_val = !is_null($indices['provider_code']) ? IdentifierNormalizer::normalize($cells[$indices['provider_code']] ?? null) : null;
                    $name_val          = !is_null($indices['name'])          ? IdentifierNormalizer::normalize($cells[$indices['name']] ?? null)          : null;

                    if (!is_null($id_val)) {
                        $escalon_counts['id']++;
                    } elseif (!is_null($bar_code_val)) {
                        $escalon_counts['bar_code']++;
                    } elseif (!is_null($sku_val)) {
                        $escalon_counts['sku']++;
                    } elseif (!is_null($provider_code_val)) {
                        $escalon_counts['provider_code']++;
                    } elseif (!is_null($name_val)) {
                        $escalon_counts['name']++;
                    } else {
                        $escalon_counts['sin_identificador']++;
                    }

                    /*
                     * Nombres repetidos: se cuenta sobre cualquier fila con nombre normalizado
                     * (no solo las que caen en el escalón "name"), como aviso general del archivo.
                     */
                    if (!is_null($name_val)) {
                        $name_key = mb_strtolower($name_val);
                        if (!isset($nombres_data[$name_key])) {
                            $nombres_data[$name_key] = 0;
                        }
                        $nombres_data[$name_key]++;
                    }
                }

                /* Solo procesamos la primera hoja del libro. */
                break;
            }

            $reader->close();

        } catch (\Throwable $e) {
            Log::error('AiExcelAnalyzer: error al analizar la cadena de identificación', [
                'message' => $e->getMessage(),
                'file'    => $excel_path,
            ]);
            $empty_result['cadena_identificacion']['motivo'] = 'error_de_lectura';
            return $empty_result;
        }

        /* Armamos el array final de placeholders detectados. */
        $placeholders = [];
        foreach ($placeholders_data as $campo => $valores) {
            foreach ($valores as $valor => $data) {
                $placeholders[] = [
                    'campo'        => $campo,
                    'valor'        => $valor,
                    'repeticiones' => $data['count'],
                    'filas'        => $data['filas'],
                ];
            }
        }

        /* Nombres duplicados: cuántos valores distintos se repiten y cuántas filas afecta en total. */
        $nombres_distintos_repetidos = 0;
        $nombres_filas_afectadas     = 0;
        foreach ($nombres_data as $veces) {
            if ($veces > 1) {
                $nombres_distintos_repetidos++;
                $nombres_filas_afectadas += $veces;
            }
        }

        return [
            'placeholders' => $placeholders,
            'cadena_identificacion' => [
                'columnas_mapeadas' => $columnas_mapeadas,
                'disponible' => true,
                'motivo' => null,
                'total_filas' => $data_row_index,
                'escalones' => [
                    ['campo' => 'id',                'filas' => $escalon_counts['id']],
                    ['campo' => 'bar_code',           'filas' => $escalon_counts['bar_code']],
                    ['campo' => 'sku',                'filas' => $escalon_counts['sku']],
                    ['campo' => 'provider_code',      'filas' => $escalon_counts['provider_code']],
                    ['campo' => 'name',               'filas' => $escalon_counts['name']],
                    ['campo' => 'sin_identificador',  'filas' => $escalon_counts['sin_identificador']],
                ],
            ],
            'nombres_duplicados' => [
                'cantidad_distintos' => $nombres_distintos_repetidos,
                'filas_afectadas'    => $nombres_filas_afectadas,
            ],
        ];
    }

    /**
     * Recorre la hoja de un reader ya abierto y retorna el número de fila (1-based)
     * de la primera fila que tenga al menos una celda con contenido no vacío.
     *
     * Retorna 1 si todas las filas están vacías o el archivo no tiene filas.
     *
     * @param  string $excel_path  Ruta al archivo Excel
     * @return int                 Número de fila (1-based) de la primera fila no vacía
     */
    protected function find_first_non_empty_row(string $excel_path): int
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($excel_path);

        /* Número de fila Excel (1-based) donde empieza el contenido real. */
        $first_non_empty_row = 1;
        $row_number = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $row_number++;

                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $str_value = trim((string)($value ?? ''));

                    if ($str_value !== '') {
                        $first_non_empty_row = $row_number;
                        $reader->close();
                        return $first_non_empty_row;
                    }
                }
            }

            /* Solo primera hoja. */
            break;
        }

        $reader->close();

        /* Si todo está vacío, retornar 1 como fallback (mismo comportamiento histórico). */
        return 1;
    }

    /**
     * Lee las primeras N filas del Excel y retorna un array con headers y muestra.
     *
     * Detecta la primera fila no vacía del archivo (soporta filas vacías al inicio)
     * y la trata como cabecera; las siguientes filas son datos de muestra.
     *
     * @param  string $excel_path  Ruta al archivo Excel
     * @return array               ['headers' => [...], 'rows' => [[...], ...]]
     *
     * @throws \RuntimeException  Si el archivo no puede abrirse con OpenSpout
     */
    protected function read_sample_rows(string $excel_path): array
    {
        $headers = [];
        $rows = [];

        /*
         * Detectar en qué fila empieza el contenido real del Excel
         * (puede haber filas vacías al inicio del archivo).
         */
        $header_row_number = $this->find_first_non_empty_row($excel_path);

        /*
         * Usamos el lector XLSX de OpenSpout, el mismo que InitExcelImport,
         * para garantizar compatibilidad con los formatos ya aceptados.
         */
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($excel_path);

        $row_number = 0;
        $header_found = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $row_number++;

                /* Saltear filas vacías anteriores a la cabecera detectada. */
                if ($row_number < $header_row_number) {
                    continue;
                }

                /* Extraemos los valores celdas como strings simples. */
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = (string)($value ?? '');
                }

                if (!$header_found) {
                    /* Primera fila no vacía: encabezados de columna. */
                    $headers = $cells;
                    $header_found = true;
                } else {
                    $rows[] = $cells;
                }

                /* Leer cabecera + SAMPLE_ROWS filas de datos. */
                if ($row_number >= $header_row_number + self::SAMPLE_ROWS) {
                    break;
                }
            }

            /* Solo procesamos la primera hoja del libro. */
            break;
        }

        $reader->close();

        if (empty($headers)) {
            throw new \RuntimeException('El archivo Excel está vacío o no tiene cabecera legible.');
        }

        return [
            'headers' => $headers,
            'rows'    => $rows,
        ];
    }

    /**
     * Retorna la lista de proveedores disponibles del usuario como array simple.
     *
     * @return array  Array de ['id' => int, 'name' => string]
     */
    protected function get_available_providers(): array
    {
        /*
         * Cargamos solo id y name para minimizar el tamaño del prompt enviado a Claude.
         */
        return Provider::where('user_id', $this->user_id)
            ->orderBy('name', 'ASC')
            ->get(['id', 'name'])
            ->map(function ($p) {
                return ['id' => $p->id, 'name' => $p->name];
            })
            ->values()
            ->all();
    }

    /**
     * Retorna las sucursales (addresses) del usuario como array simple.
     *
     * Solo se cargan id y street para minimizar el tamaño del prompt y para que
     * Claude pueda aplicar la regla de stock por sucursal.
     *
     * @return array  Array de ['id' => int, 'street' => string]
     */
    protected function get_available_addresses(): array
    {
        return Address::where('user_id', $this->user_id)
            ->orderBy('id', 'ASC')
            ->get(['id', 'street'])
            ->map(function ($a) {
                return ['id' => $a->id, 'street' => $a->street];
            })
            ->values()
            ->all();
    }

    /**
     * Retorna las listas de precio (price types) del usuario como array simple.
     *
     * Solo se cargan id y name para minimizar el tamaño del prompt y para que
     * Claude pueda mapear columnas de precio/margen por lista de precio.
     *
     * @return array  Array de ['id' => int, 'name' => string]
     */
    protected function get_available_price_types(): array
    {
        /*
         * FIX (bug real, 2/7/2026): antes de este fix acá no se chequeaba si el dueño
         * tiene activo el modo "listas de precio" (users.listas_de_precio). El frontend
         * (ai-excel-import/Index.vue, system_property_options) y ProcessRow::obtener_price_types()
         * SÍ lo chequean — así que un usuario con PriceType cargadas pero listas_de_precio
         * desactivado (ej: quedaron de antes de migrar la extensión vieja al flag nuevo, y
         * nunca se corrió check_extencion_listas_de_precios sobre la base de ese cliente)
         * recibía de Claude una sugerencia de mapeo y una vista previa con datos que el
         * select no podía mostrar (no matcheaba ninguna opción) y que ProcessRow iba a
         * ignorar igual al importar de verdad. Chequear acá también evita que Claude vea
         * y sugiera listas de precio que el usuario no puede usar.
         */
        $owner = User::find($this->user_id);
        if (!UserHelper::uses_listas_de_precio($owner)) {
            return [];
        }

        return \App\Models\PriceType::where('user_id', $this->user_id)
            ->orderBy('position', 'ASC')
            ->get(['id', 'name'])
            ->map(function ($pt) {
                return ['id' => $pt->id, 'name' => $pt->name];
            })
            ->values()
            ->all();
    }

    /**
     * Construye el prompt que se envía a Claude con los datos del Excel y los proveedores.
     *
     * @param  array  $sample_data        Resultado de read_sample_rows()
     * @param  array  $providers          Lista de proveedores disponibles
     * @param  string $original_filename  Nombre original del archivo subido por el usuario
     * @param  array  $addresses          Sucursales del usuario (['id' => int, 'street' => string]); vacío si no tiene
     * @param  array  $price_types        Listas de precio del usuario (['id' => int, 'name' => string]); vacío si no tiene
     * @return string                     Prompt completo listo para enviar a la API
     */
    protected function build_prompt(array $sample_data, array $providers, string $original_filename = '', array $addresses = [], array $price_types = []): string
    {
        /* Texto del nombre de archivo para el prompt (sin ruta, solo nombre + extensión). */
        $filename_for_prompt = trim(basename($original_filename));
        if ($filename_for_prompt === '') {
            $filename_for_prompt = '(no disponible)';
        }

        /* Armamos la cabecera del Excel como texto separado por pipes para Claude. */
        $headers_line = implode(' | ', $sample_data['headers']);

        /* Armamos las filas de muestra, una por línea, con las celdas separadas por pipe. */
        $rows_lines = '';
        foreach ($sample_data['rows'] as $row) {
            $rows_lines .= implode(' | ', $row) . "\n";
        }

        /* Listado de proveedores en formato legible para Claude. */
        $providers_text = '';
        if (!empty($providers)) {
            foreach ($providers as $provider) {
                $providers_text .= "- ID {$provider['id']}: {$provider['name']}\n";
            }
        } else {
            $providers_text = '(No hay proveedores registrados)';
        }

        /* Lista de propiedades del sistema que Claude puede asignar. */
        $system_properties_list = implode(', ', self::SYSTEM_PROPERTIES);

        /*
         * Indica a Claude si el usuario tiene sucursales (addresses) configuradas.
         * Se usa para reemplazar el marcador {HAS_ADDRESSES} y aplicar la regla de stock por sucursal.
         */
        $has_addresses_text = !empty($addresses) ? 'Sí' : 'No';

        /*
         * Sección de depósitos: solo se incluye si el usuario tiene sucursales configuradas.
         * Lista cada depósito con su ID y explica el sistema de system_property codificado
         * (address_{id}_amount / _min / _max) que el frontend sabe expandir.
         */
        $addresses_section = '';
        if (!empty($addresses)) {
            /* Listado de depósitos en formato "- ID {id}: {street}". */
            $addresses_lines = '';
            foreach ($addresses as $address) {
                $addresses_lines .= "- ID {$address['id']}: {$address['street']}\n";
            }

            $addresses_section = <<<ADDR
## Depósitos (sucursales) disponibles
{$addresses_lines}
## Instrucciones para columnas de stock por depósito

El usuario tiene sucursales/depósitos configurados. El stock SOLO puede modificarse a nivel de cada depósito — NO existe una columna de stock global cuando hay depósitos.

Para cada columna del Excel que parezca contener stock de un depósito específico:
- Si la columna contiene el nombre (o parte del nombre) de un depósito → mapeala con:
  system_property: "address_{id}_amount"   (donde {id} es el ID del depósito)
- Si la columna contiene stock mínimo de un depósito → system_property: "address_{id}_min"
- Si la columna contiene stock máximo de un depósito → system_property: "address_{id}_max"

Si encontrás una columna de stock genérico (sin referencia a una sucursal) → system_property: null.
Generá una interpretation_note para esa columna: "Esta columna parece ser stock global, pero el sistema trabaja con stock por sucursal. Seleccioná el depósito correspondiente en el selector."

ADDR;
        }

        /*
         * Sección de listas de precio: solo se incluye si el usuario tiene price_types configurados.
         * Lista cada lista de precio con su ID y explica el sistema de system_property codificado
         * (price_type_{id}_final_price / _percentage / _setear) que el frontend sabe expandir.
         */
        $price_types_section = '';
        if (!empty($price_types)) {
            /* Listado de listas de precio en formato "- ID {id}: {name}". */
            $price_types_lines = '';
            foreach ($price_types as $price_type) {
                $price_types_lines .= "- ID {$price_type['id']}: {$price_type['name']}\n";
            }

            $price_types_section = <<<PT
## Listas de precio disponibles
{$price_types_lines}
## Instrucciones para columnas de listas de precio

Si detectás columnas del Excel relacionadas con listas de precio, mapeá cada una así:
- Columna de precio final para la lista "{name}" → system_property: "price_type_{id}_final_price"
- Columna de porcentaje/margen para la lista "{name}" → system_property: "price_type_{id}_percentage"
- Columna que indica si setear precio manualmente (Si/No) para la lista "{name}" → system_property: "price_type_{id}_setear"

Patrones comunes de nombre de columna en Excel:
- "\$ Final {nombre_lista}", "Precio {nombre_lista}", "PVP {nombre_lista}" → final_price
- "% {nombre_lista}", "Margen {nombre_lista}", "Ganancia {nombre_lista}" → percentage
- "Setear {nombre_lista}", "Manual {nombre_lista}", "Fijar {nombre_lista}" → setear

PT;
        }

        /*
         * El prompt le explica a Claude exactamente qué debe devolver y en qué formato.
         * Se pide explícitamente JSON puro sin markdown para facilitar el parseo.
         */
        $prompt = <<<PROMPT
Analizá el siguiente archivo Excel de importación de artículos y devolvé SOLO un JSON válido (sin markdown, sin explicaciones extra).

## Nombre del archivo subido
{$filename_for_prompt}

(Usá este nombre como pista principal para inferir el proveedor: suele contener el nombre o siglas del distribuidor. Comparalo con la lista de proveedores disponibles.)

## Encabezados del Excel
{$headers_line}

## Primeras filas de datos (muestra)
{$rows_lines}

## Proveedores disponibles en el sistema
{$providers_text}

## Propiedades del sistema disponibles
{$system_properties_list}

## Instrucciones generales
1. Analizá cada columna del Excel y mapeala a la propiedad del sistema más apropiada.
2. Si una columna no corresponde a ninguna propiedad del sistema, usá null en system_property.
3. Determiná a qué proveedor pertenece este listado: priorizá el nombre del archivo; si no alcanza, usá encabezados y datos de muestra. El provider_id debe ser un ID de la lista de proveedores o null.

## Regla crítica: nombre del artículo (propiedad "nombre")
- El dato más importante para importar es el **nombre** del artículo (propiedad del sistema: nombre).
- En listas de proveedor/distribuidor es MUY frecuente que la columna del Excel se llame "DESCRIPCION", "Descripción", "DETALLE" o similar, pero su contenido es en realidad el **nombre del producto** (texto largo identificatorio), NO la descripción complementaria del sistema.
- Si NO existe otra columna claramente dedicada al nombre (encabezados como "Nombre", "Name", "Artículo", "Producto", "Denominación"):
  - Mapeá esa columna "Descripción" (o equivalente) a system_property **nombre**, NO a descripcion.
  - Usá confidence como máximo **0.78** (no estás 100% seguro).
  - Completá **interpretation_note** en español, indicando explícitamente que interpretás esa columna como el nombre del artículo para que el usuario lo valide. Ejemplo: "Interpretamos la columna «DESCRIPCION» como el nombre del artículo; en este tipo de listados suele ser el dato principal del producto."
- Solo mapeá una columna a system_property **descripcion** cuando YA identificaste otra columna distinta mapeada a **nombre** (es decir: hay nombre de producto en una columna y texto complementario en otra).
- Si existen columnas separadas "Nombre" y "Descripción", mapeá "Nombre" → nombre (confidence alta) y "Descripción" → descripcion solo si el contenido parece texto complementario; si la segunda sigue siendo el nombre largo del producto, mapeala a nombre o null, nunca a descripcion.

## Regla especial: columna de proveedor por fila
- Si el Excel tiene una columna cuyos valores son **nombres de proveedores distintos por fila** (por ejemplo, una columna llamada "Proveedor", "Prov.", "Distribuidor" o similar, donde cada fila tiene el nombre de un proveedor diferente), mapeala a system_property **proveedor**.
- Esta regla aplica cuando los valores de la columna varían por fila conteniendo nombres de proveedores. La instrucción 3 (inferir proveedor global) aplica cuando TODO el archivo pertenece a un único proveedor y no hay una columna explícita de proveedor.
- Cuando una columna quedó mapeada a **proveedor**, el campo **provider_id** del JSON raíz debe ser **null** (porque el proveedor se determina fila a fila, no globalmente).
- La `interpretation_note` para esta columna debe ser **null**: no requiere validación del usuario.
- NO generes interpretation_note con mensajes del tipo "el sistema gestiona el proveedor globalmente": ese texto confunde al usuario.

## Nuevas propiedades y reglas de mapeo

Propiedades booleanas (valores aceptados: Si, No, 1, 0):
- in_offer: si el artículo está en oferta (columnas como "Oferta", "En oferta", "Promoción", "Promo").
- online: si el artículo está activo/disponible para la venta (columnas como "Activo", "Disponible", "Visible", "Online").
- precio_pausado: si el precio se muestra como "Consultar" en la tienda en lugar del monto (columnas como "Precio pausado", "Consultar precio", "Precio oculto").
- disponible_tienda_nube: si el artículo está disponible en Tienda Nube (columnas como "Tienda Nube", "TN", "Disponible online").
- numero: número único interno del artículo (ID de la base de datos). SOLO mapear si el encabezado dice claramente "Número", "Num", "N°", "ID", "Cod" o similar y los valores son enteros positivos que parecen IDs internos. NO inferir por los datos: requiere encabezado explícito. Esta propiedad indica que el Excel fue exportado desde el sistema y permite identificar artículos por su ID interno (incluso para actualizar el código de barras).
- aplicar_iva: si el IVA se suma al costo para calcular el precio (default Si).

Propiedades numéricas:
- medida: magnitud del contenido del artículo (ej. 2.5 para "2.5 litros"). Columnas como "Medida", "Contenido", "Volumen", "Peso neto".
- u_individuales: cuántas unidades individuales componen el artículo (columnas como "Unidades individuales", "U. individuales", "U individuales").

Propiedades de texto:
- unidad_medida: unidad de medida del artículo (columnas como "Unidad", "UM", "Unidad medida"). Valores posibles: el nombre tal como figura en el sistema.
- contenido: descripción del contenido o empaque (columnas como "Contenido", "Envase", "Presentación"). Solo mapear si hay UNA columna de contenido y no hay una columna medida con valor numérico.
- `costo_en_dolares` (IMPORTANTE: siempre usar este nombre exacto en el JSON — NUNCA escribas "moneda" como valor de system_property): indica si el costo del artículo está en dólares. Columnas típicas en el Excel: "Moneda", "Divisa", "USD", "Costo en dólares", "Costo USD". Valores posibles en las celdas: "dólar", "dolar", "dolares", "dólares", "USD", "u\$s", "dollar" → la columna corresponde a esta propiedad (el sistema interpretará esos valores como verdadero). "peso", "pesos", "ARS" también confirman que la columna es `costo_en_dolares` (el sistema los tratará como falso). Mapeá la columna aunque los valores sean "peso/dólar" en lugar de "Si/No".

Regla crítica: descuentos vs descuentos_montos / recargos vs recargos_montos:
- descuentos: la columna contiene descuentos expresados como porcentaje (ej: "10_5_3", valores sin símbolo \$). Los múltiples descuentos se concatenan con guion bajo.
- descuentos_montos: la columna contiene descuentos expresados como montos en pesos/dólares (ej: "500_200", valores que son sumas de dinero). Los múltiples montos se concatenan con guion bajo.
- recargos: la columna contiene recargos expresados como porcentaje (ej: "5_10F"). El sufijo F indica que ese recargo se aplica después del precio final.
- recargos_montos: la columna contiene recargos expresados como montos en \$ (ej: "1000_500F").
- Cuando el encabezado no aclara si es monto o porcentaje (ej: columna llamada simplemente "Recargos" o "Descuentos"), generá una interpretation_note explicando la ambigüedad y lo que se asumió.

Regla: stock con sucursales:
El usuario tiene sucursales configuradas: {HAS_ADDRESSES}
- Si tiene sucursales configuradas (valor "Sí"): NO mapees ninguna columna a stock_actual. Si detectás una columna de stock genérico (sin nombre de sucursal específico), dejá system_property: null y generá una interpretation_note indicando: "El sistema trabaja con stock por sucursal. No podemos mapear esta columna automáticamente — seleccioná el depósito correspondiente desde el selector." Si la columna ya tiene el nombre de una sucursal específica, mapeala a stock_actual igualmente (eso lo maneja el paso siguiente).
- Si no tiene sucursales (valor "No"): mapeá normalmente a stock_actual.

{ADDRESSES_SECTION}
{PRICE_TYPES_SECTION}
## Campo assistant_notes
Agregá al JSON de respuesta un array assistant_notes con strings en español (máximo 5 ítems). Cada ítem es una advertencia concisa de alto nivel para el usuario. Generá notas cuando:
- Hay una columna ambigua entre descuentos porcentaje / monto.
- Hay una columna ambigua entre recargos porcentaje / monto.
- Detectás columna de stock con sucursales y no podés mapearla.
- Detectás columna que podría ser nombre o descripcion y lo mapeaste con baja confianza.
- Hay columnas que no pudiste mapear a ninguna propiedad y que parecen importantes (no vacías).
Cada nota debe ser accionable: decirle al usuario qué columna revisar y qué opciones tiene.
Si no hay nada que aclarar, devolvé "assistant_notes": [].
Ejemplo de nota: "La columna «Recargos» no especifica si son porcentajes o montos. La mapeamos como «Recargos (%)» — verificá en la tabla si corresponde cambiarla a «Recargos (montos)»."

4. Devolvé EXCLUSIVAMENTE el siguiente JSON sin texto adicional:

{
  "column_mapping": [
    {
      "excel_column": "nombre exacto del encabezado en el Excel",
      "system_property": "propiedad del sistema o null",
      "confidence": 0.95,
      "interpretation_note": "texto en español para el usuario o null"
    }
  ],
  "provider_id": null,
  "provider_confidence": "alto",
  "assistant_notes": []
}

Notas:
- confidence es un número entre 0 y 1 indicando seguridad del mapeo
- interpretation_note: obligatorio en español cuando interpretás "Descripción" (u homólogo) como nombre; null en el resto de los casos
- provider_confidence debe ser "alto", "medio" o "bajo"
- provider_id debe ser el ID numérico del proveedor o null si no se puede inferir
- Devolvé SOLO el JSON, sin markdown ni texto adicional
- assistant_notes: array de strings en español (máx. 5) con advertencias de alto nivel; vacío si no hay nada que aclarar
PROMPT;

        /*
         * Reemplazamos el marcador dinámico de sucursales: "Sí" si el usuario tiene
         * addresses configuradas, "No" en caso contrario. Esto activa la regla de stock por sucursal.
         */
        $prompt = str_replace('{HAS_ADDRESSES}', $has_addresses_text, $prompt);

        /*
         * Reemplazamos las secciones dinámicas de depósitos y listas de precio.
         * Si el usuario no tiene addresses o price_types, el marcador se reemplaza por vacío
         * y el comportamiento existente (stock_actual / listas) se mantiene sin cambios.
         */
        $prompt = str_replace('{ADDRESSES_SECTION}', $addresses_section, $prompt);
        $prompt = str_replace('{PRICE_TYPES_SECTION}', $price_types_section, $prompt);

        return $prompt;
    }

    /**
     * Realiza la llamada HTTP a la API de Claude (Anthropic) con el prompt indicado.
     *
     * @param  string $prompt  Prompt completo a enviar
     * @return string          Texto de respuesta devuelto por Claude
     *
     * @throws \RuntimeException  Si la llamada falla o la API devuelve error
     */
    protected function call_claude(string $prompt): string
    {
        /* Clave de API de Anthropic (config/services.php → ANTHROPIC_API_KEY). */
        $api_key = (string) config('services.anthropic.api_key');

        if ($api_key === '') {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no está configurada en el entorno.');
        }

        Log::info('AiExcelAnalyzer: llamando a Claude API', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        /*
         * Cliente HTTP con la misma configuración TLS que admin-api (ANTHROPIC_CAINFO / ANTHROPIC_VERIFY_SSL).
         */
        $http = $this->build_anthropic_http_client($api_key);

        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('AiExcelAnalyzer: error en respuesta de Claude', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            /*
             * Detectar el tipo de error desde el JSON de respuesta de Anthropic.
             * Los errores transitorios tienen type: overloaded_error, api_error, etc.
             * Mostramos un mensaje amigable en lugar del JSON crudo.
             */
            $error_body = $response->json();
            $error_type = $error_body['error']['type'] ?? null;

            $transient_error_types = ['overloaded_error', 'api_error'];

            if (in_array($error_type, $transient_error_types) || $response->status() === 529) {
                throw new \RuntimeException(
                    'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.'
                );
            }

            /* Otros errores (auth, rate limit, etc.): mensaje técnico para debugging. */
            throw new \RuntimeException(
                'Error al comunicarse con Claude API (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $response_data = $response->json();

        /*
         * La respuesta de la API de Anthropic tiene el contenido en:
         * response.content[0].text
         */
        $text = $response_data['content'][0]['text'] ?? null;

        if (is_null($text)) {
            throw new \RuntimeException('Claude devolvió una respuesta sin contenido de texto.');
        }

        Log::info('AiExcelAnalyzer: respuesta de Claude recibida', [
            'response_preview' => substr($text, 0, 300),
        ]);

        return $text;
    }

    /**
     * Parsea el texto de respuesta de Claude y extrae el JSON con el mapeo.
     *
     * Claude a veces envuelve el JSON en bloques de código markdown aunque
     * se le pide que no lo haga, así que limpiamos esos artefactos primero.
     *
     * @param  string $claude_text  Texto crudo devuelto por Claude
     * @param  array  $providers    Proveedores del usuario (para validar provider_id)
     * @param  array  $addresses    Sucursales del usuario (para validar IDs en address_{id}_*)
     * @param  array  $price_types  Listas de precio del usuario (para validar IDs en price_type_{id}_*)
     * @return array                Array con claves: column_mapping, provider_id, provider_confidence
     *
     * @throws \RuntimeException  Si el JSON no puede parsearse o tiene estructura inválida
     */
    protected function parse_claude_response(string $claude_text, array $providers = [], array $addresses = [], array $price_types = []): array
    {
        /*
         * Limpiamos posibles bloques de código markdown que Claude pueda incluir
         * a pesar de que el prompt pide JSON puro.
         */
        $clean_text = trim($claude_text);
        $clean_text = preg_replace('/^```(?:json)?\s*/i', '', $clean_text);
        $clean_text = preg_replace('/\s*```$/i', '', $clean_text);
        $clean_text = trim($clean_text);

        $parsed = json_decode($clean_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('AiExcelAnalyzer: JSON inválido en respuesta de Claude', [
                'raw_response' => $claude_text,
                'json_error'   => json_last_error_msg(),
            ]);

            throw new \RuntimeException(
                'Claude no devolvió un JSON válido. Error: ' . json_last_error_msg()
            );
        }

        /* Validamos que tenga la estructura esperada con column_mapping. */
        if (!isset($parsed['column_mapping']) || !is_array($parsed['column_mapping'])) {
            throw new \RuntimeException(
                'La respuesta de Claude no contiene la clave "column_mapping" esperada.'
            );
        }

        /*
         * Normalizamos provider_id: solo IDs que existan en los proveedores del usuario.
         */
        $provider_id = $parsed['provider_id'] ?? null;
        if ($provider_id !== null) {
            $provider_id = (int) $provider_id;
            $valid_provider_ids = [];
            foreach ($providers as $provider) {
                $valid_provider_ids[(int) $provider['id']] = true;
            }
            if (!isset($valid_provider_ids[$provider_id])) {
                Log::warning('AiExcelAnalyzer: provider_id devuelto por Claude no pertenece al usuario', [
                    'provider_id' => $provider_id,
                ]);
                $provider_id = null;
            }
        }

        $provider_confidence = $parsed['provider_confidence'] ?? 'bajo';
        if (!in_array($provider_confidence, ['alto', 'medio', 'bajo'], true)) {
            $provider_confidence = 'bajo';
        }

        if ($provider_id === null) {
            $provider_confidence = 'bajo';
        }

        /*
         * Validar IDs de depósitos y listas de precio en el column_mapping.
         * Claude puede devolver system_property codificado (address_{id}_{sub} / price_type_{id}_{sub}).
         * Si el ID no pertenece al usuario, descartamos el mapeo (system_property = null) para no
         * referenciar un depósito o lista inexistente que ProcessRow no podría resolver.
         */
        $valid_address_ids    = array_column($addresses, 'id');
        $valid_price_type_ids = array_column($price_types, 'id');

        foreach ($parsed['column_mapping'] as &$col) {
            $prop = $col['system_property'] ?? null;
            if (is_null($prop)) continue;

            // Validar address_{id}_{sub_tipo}
            if (preg_match('/^address_(\d+)_(amount|min|max)$/', $prop, $m)) {
                $address_id = (int) $m[1];
                if (!in_array($address_id, $valid_address_ids, true)) {
                    $col['system_property'] = null; // ID inválido → ignorar
                }
                continue;
            }

            // Validar price_type_{id}_{sub_tipo}
            if (preg_match('/^price_type_(\d+)_(final_price|percentage|setear)$/', $prop, $m)) {
                $pt_id = (int) $m[1];
                if (!in_array($pt_id, $valid_price_type_ids, true)) {
                    $col['system_property'] = null; // ID inválido → ignorar
                }
                continue;
            }
        }
        unset($col);

        /*
         * Extraemos assistant_notes: array de strings con advertencias de alto nivel.
         * Filtramos cualquier valor que no sea string y, si el campo no existe, retornamos array vacío.
         */
        $assistant_notes = [];
        if (isset($parsed['assistant_notes']) && is_array($parsed['assistant_notes'])) {
            foreach ($parsed['assistant_notes'] as $note) {
                if (is_string($note) && trim($note) !== '') {
                    $assistant_notes[] = trim($note);
                }
            }
        }

        return [
            'column_mapping'      => $parsed['column_mapping'],
            'provider_id'         => $provider_id,
            'provider_confidence' => $provider_confidence,
            'assistant_notes'     => $assistant_notes,
        ];
    }

    /**
     * Completa cada ítem del mapeo con letra de columna Excel, índice y confianza numérica.
     *
     * @param  array $column_mapping  Mapeo devuelto por Claude
     * @param  array $headers           Encabezados de la primera fila del Excel (orden real)
     * @return array                    Mismo mapeo enriquecido para la API
     */
    protected function enrich_column_mapping(array $column_mapping, array $headers): array
    {
        /* Índice por nombre de encabezado normalizado para ubicar la columna en el Excel. */
        $header_index_by_name = [];
        foreach ($headers as $header_index => $header_text) {
            $normalized_key = $this->normalize_header_key($header_text);
            if ($normalized_key !== '' && !isset($header_index_by_name[$normalized_key])) {
                $header_index_by_name[$normalized_key] = $header_index;
            }
        }

        $enriched_mapping = [];

        foreach ($column_mapping as $array_position => $mapping_item) {
            if (!is_array($mapping_item)) {
                continue;
            }

            /* Confianza entre 0 y 1; si Claude omite el valor, asumimos 0. */
            $raw_confidence = $mapping_item['confidence'] ?? 0;
            $confidence = (float) $raw_confidence;
            $confidence = max(0, min(1, $confidence));

            $excel_column_name = (string) ($mapping_item['excel_column'] ?? '');
            $normalized_excel_name = $this->normalize_header_key($excel_column_name);

            /*
             * Preferimos el índice que coincide con el encabezado leído del archivo;
             * si no hay match, usamos la posición en el array (orden de Claude).
             */
            $excel_column_index = $array_position;
            if ($normalized_excel_name !== '' && isset($header_index_by_name[$normalized_excel_name])) {
                $excel_column_index = $header_index_by_name[$normalized_excel_name];
            }

            /* Alineamos system_property al contrato del importador (codigo_de_proveedor, etc.). */
            $system_property = $mapping_item['system_property'] ?? null;
            /*
             * Solo normalizar propiedades planas — las codificadas con address_/price_type_
             * se pasan tal cual porque el normalizer no las conoce y las dejaría en null.
             */
            if (
                !is_null($system_property)
                && strpos($system_property, 'address_') !== 0
                && strpos($system_property, 'price_type_') !== 0
            ) {
                $system_property = ArticleImportColumnsNormalizer::normalize_property_key($system_property);
            }

            /* Nota opcional para el usuario cuando la IA reinterpreta un encabezado (p. ej. Descripción → nombre). */
            $interpretation_note = $mapping_item['interpretation_note'] ?? null;
            if (is_string($interpretation_note)) {
                $interpretation_note = trim($interpretation_note);
                if ($interpretation_note === '') {
                    $interpretation_note = null;
                }
            } else {
                $interpretation_note = null;
            }

            /*
             * Si la columna fue mapeada a 'proveedor', no mostrar nota: es un mapeo directo sin ambigüedad.
             */
            if ($system_property === 'proveedor' && !is_null($interpretation_note)) {
                $interpretation_note = null;
            }

            $enriched_mapping[] = array_merge($mapping_item, [
                'system_property'      => $system_property,
                'confidence'           => $confidence,
                'interpretation_note'  => $interpretation_note,
                'excel_column_index'   => $excel_column_index,
                'excel_column_letter'  => $this->number_to_excel_column($excel_column_index + 1),
            ]);
        }

        return $enriched_mapping;
    }

    /**
     * Ajusta mapeos Descripción/nombre según reglas de negocio si Claude no las aplicó del todo.
     *
     * @param  array $column_mapping  Mapeo enriquecido
     * @return array                 Mapeo corregido con interpretation_note cuando corresponda
     */
    protected function apply_nombre_descripcion_interpretation_rules(array $column_mapping): array
    {
        /* ¿Hay alguna columna ya mapeada a nombre desde un encabezado explícito de nombre? */
        $has_nombre_from_clear_header = false;

        foreach ($column_mapping as $mapping_item) {
            if (!is_array($mapping_item)) {
                continue;
            }

            $header_key = $this->normalize_header_key($mapping_item['excel_column'] ?? '');
            $system_property = $mapping_item['system_property'] ?? null;

            if (
                $system_property === 'nombre'
                && $this->header_indicates_clear_product_name($header_key)
            ) {
                $has_nombre_from_clear_header = true;
                break;
            }
        }

        foreach ($column_mapping as $index => $mapping_item) {
            if (!is_array($mapping_item)) {
                continue;
            }

            $excel_column_label = (string) ($mapping_item['excel_column'] ?? '');
            $header_key = $this->normalize_header_key($excel_column_label);
            $system_property = $mapping_item['system_property'] ?? null;

            if (!$this->header_indicates_descripcion_label($header_key)) {
                continue;
            }

            /*
             * Sin columna "Nombre" clara: la columna Descripción del Excel debe alimentar nombre.
             */
            if (!$has_nombre_from_clear_header) {
                if ($system_property === 'descripcion' || is_null($system_property)) {
                    $column_mapping[$index]['system_property'] = 'nombre';
                    $system_property = 'nombre';
                }

                if ($system_property === 'nombre') {
                    $column_mapping[$index]['confidence'] = min(
                        (float) ($column_mapping[$index]['confidence'] ?? 0.7),
                        0.78
                    );

                    if (empty($column_mapping[$index]['interpretation_note'])) {
                        $column_mapping[$index]['interpretation_note'] =
                            'Interpretamos la columna «' . $excel_column_label . '» como el nombre del artículo; '
                            . 'en listas de proveedor suele llamarse descripción pero identifica el producto. '
                            . 'Revisá el mapeo antes de importar.';
                    }
                }

                continue;
            }

            /*
             * Ya hay nombre en otra columna: descripcion solo si Claude la asignó explícitamente.
             * Si quedó como nombre por error en una segunda columna "Descripción", pasar a descripcion.
             */
            if ($system_property === 'nombre' && $has_nombre_from_clear_header) {
                $column_mapping[$index]['system_property'] = 'descripcion';
                $column_mapping[$index]['interpretation_note'] = null;
            }
        }

        return $column_mapping;
    }

    /**
     * Indica si el encabezado del Excel suele ser el nombre explícito del producto.
     *
     * @param  string $header_key  Encabezado normalizado
     * @return bool
     */
    protected function header_indicates_clear_product_name(string $header_key): bool
    {
        if ($header_key === '') {
            return false;
        }

        $clear_name_tokens = [
            'nombre',
            'name',
            'articulo',
            'artículo',
            'producto',
            'denominacion',
            'denominación',
        ];

        foreach ($clear_name_tokens as $token) {
            if (strpos($header_key, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el encabezado del Excel es el típico "Descripción" de listas de proveedor.
     *
     * @param  string $header_key  Encabezado normalizado
     * @return bool
     */
    protected function header_indicates_descripcion_label(string $header_key): bool
    {
        if ($header_key === '') {
            return false;
        }

        $descripcion_tokens = [
            'descripcion',
            'descripción',
            'desc ',
            'detalle',
            'detalle producto',
        ];

        foreach ($descripcion_tokens as $token) {
            if (strpos($header_key, $token) !== false) {
                return true;
            }
        }

        return $header_key === 'desc';
    }

    /**
     * Normaliza un texto de encabezado para comparación insensible a mayúsculas y espacios.
     *
     * @param  mixed $value  Texto del encabezado
     * @return string        Clave normalizada o cadena vacía
     */
    protected function normalize_header_key($value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Cuenta el total de filas de datos del Excel (excluye la fila de cabecera detectada).
     *
     * Detecta la primera fila no vacía como cabecera y cuenta solo las filas posteriores.
     * Realiza una pasada completa sobre la primera hoja para obtener el conteo real.
     *
     * @param  string $excel_path  Ruta absoluta al archivo Excel
     * @return int                 Cantidad de filas de datos (0 si el archivo está vacío o solo tiene cabecera)
     */
    protected function count_data_rows(string $excel_path): int
    {
        /*
         * Detectar dónde empieza el contenido real (filas vacías al inicio del Excel).
         */
        $header_row_number = $this->find_first_non_empty_row($excel_path);

        /* Contador de filas de datos (sin contar la fila de cabecera). */
        $data_row_count = 0;

        /*
         * Usamos setShouldPreserveEmptyRows(false) para no contar filas completamente vacías
         * que algunos Excel incluyen al final del rango.
         */
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(false);
        $reader->open($excel_path);

        $row_number = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $row_number++;

                /* Saltear filas vacías iniciales y la fila de cabecera. */
                if ($row_number <= $header_row_number) {
                    continue;
                }

                $data_row_count++;
            }

            /* Solo procesamos la primera hoja. */
            break;
        }

        $reader->close();

        return $data_row_count;
    }

    /**
     * Convierte un índice de columna 1-based (1 = A) a letra estilo Excel.
     *
     * @param  int $column_number  Número de columna (1 = A, 2 = B, …)
     * @return string             Letra o letras de columna (p. ej. "AA")
     */
    protected function number_to_excel_column(int $column_number): string
    {
        $column_letter = '';

        while ($column_number > 0) {
            $remainder = ($column_number - 1) % 26;
            $column_letter = chr(65 + $remainder) . $column_letter;
            $column_number = (int) floor(($column_number - 1) / 26);
        }

        return $column_letter;
    }

    /**
     * Pide a Claude una recomendación de configuración basada en las estadísticas de duplicados.
     *
     * Arma un prompt con los conteos del array $stats y las columnas disponibles del Excel,
     * llama a Claude y parsea el JSON devuelto.
     * Si la llamada falla, el parseo lanza error o el JSON no es válido, aplica un fallback
     * heurístico respetando las columnas disponibles.
     *
     * @param  array $stats               Resultado de ExcelDuplicateStats::analyze()
     * @param  array $column_mapping      Mapeo enriquecido de columnas (para derivar columnas disponibles)
     * @param  array $formatos_numericos  Resultado de ExcelNumericFormatStats::analyze() (grupo 239, prompt 02);
     *                                    default [] para no romper llamadores existentes que no lo pasen
     * @return array                 ['clave_identidad' => string, 'politica_colision' => string, 'explicacion' => string]
     */
    public function ask_claude_for_recomendation(array $stats, array $column_mapping = [], array $formatos_numericos = []): array
    {
        /*
         * Valores aceptados para cada campo de la recomendación.
         * Se usan para validar la respuesta de Claude antes de retornarla.
         */
        $valid_claves    = ['numero', 'bar_code', 'sku', 'provider_code', 'name'];
        $valid_politicas = ['actualizar_todos', 'saltear_y_reportar', 'crear_nuevo'];

        /*
         * Derivamos qué columnas clave están disponibles en este Excel.
         * Se hace antes del try/catch para que el fallback también pueda usarlas.
         * Solo se marca true si la columna está efectivamente mapeada en el Excel.
         * Prioridad de identidad del sistema: numero -> bar_code -> sku -> provider_code -> name.
         */
        $tiene_numero        = false;
        $tiene_bar_code      = false;
        $tiene_sku           = false;
        $tiene_provider_code = false;
        $tiene_nombre        = false;

        foreach ($column_mapping as $col) {
            $prop = $col['system_property'] ?? null;
            if ($prop === 'numero')              $tiene_numero        = true;
            if ($prop === 'codigo_de_barras')    $tiene_bar_code      = true;
            if ($prop === 'sku')                 $tiene_sku           = true;
            if ($prop === 'codigo_de_proveedor') $tiene_provider_code = true;
            if ($prop === 'nombre')              $tiene_nombre        = true;
        }

        /* Textos "Sí" / "No" para el prompt. */
        $numero_disponible        = $tiene_numero        ? 'Sí' : 'No';
        $bar_code_disponible      = $tiene_bar_code      ? 'Sí' : 'No';
        $sku_disponible           = $tiene_sku           ? 'Sí' : 'No';
        $provider_code_disponible = $tiene_provider_code ? 'Sí' : 'No';
        $nombre_disponible        = $tiene_nombre        ? 'Sí' : 'No';

        /*
         * Sección opcional de formatos numéricos ambiguos (grupo 239, prompt 02).
         * Solo se agrega al prompt cuando alguna columna numérica tiene
         * nivel_de_riesgo = 'alto' (mezcla de números interpretados como miles y
         * como decimales dentro de la misma columna). Si no hay riesgo alto, no se
         * agrega nada: la recomendación ya es larga y cada bloque extra le compite
         * atención a lo importante (punto (05) del prompt).
         */
        $numeric_format_section = '';
        $hay_columna_riesgo_alto = false;
        foreach (($formatos_numericos['columnas'] ?? []) as $columna_numerica) {
            if (($columna_numerica['nivel_de_riesgo'] ?? null) === 'alto') {
                $hay_columna_riesgo_alto = true;
                break;
            }
        }

        if ($hay_columna_riesgo_alto) {
            $numeric_format_section = <<<NUMFMT

Advertencia adicional - formatos numéricos ambiguos:
Se detectaron columnas numéricas donde algunos valores tienen un punto que se interpretó como
separador de miles (ej. "2.500" -> dos mil quinientos) y otros valores, en la misma columna, tienen
un punto que se interpretó como separador decimal (ej. "3330.95" -> tres mil trescientos treinta con
noventa y cinco). Si mencionás este tema en la explicación, usá la regla concreta que aplica el
sistema: un punto se interpreta como separador de miles SOLO cuando separa grupos de exactamente 3
dígitos (ej. "2.500", "12.750"); en cualquier otro caso (ej. "3330.95", "12.5") se interpreta como
separador decimal. No des una advertencia genérica sobre "formatos de número": explicá esta regla
puntual para que el usuario entienda por qué algunos valores se leyeron distinto que otros.

NUMFMT;
        }

        /*
         * Arma el prompt con los conteos del preanálisis e informa a Claude
         * qué columnas existen realmente en este Excel para evitar que sugiera
         * claves que no están disponibles.
         */
        $prompt = <<<PROMPT
Sos un asistente que ayuda a configurar una importación de artículos desde Excel a un ERP.

Análisis del archivo:
- Total de filas de datos: {$stats['total_filas_datos']}
- Bar_codes que aparecen repetidos dentro del Excel: {$stats['bar_codes_duplicados_intra_archivo']}
- Cantidad de códigos de proveedor distintos que aparecen MÁS DE UNA VEZ dentro del Excel (0 = ninguno repetido, >0 = hay al menos un código que aparece en múltiples filas): {$stats['provider_codes_duplicados_intra_archivo']}
- Provider_codes del Excel que ya existen en BD para el MISMO proveedor: {$stats['provider_codes_existentes_mismo_proveedor']}
- Provider_codes del Excel que ya existen en BD para OTROS proveedores: {$stats['provider_codes_existentes_otros_proveedores']}

Columnas disponibles en este Excel:
- Número interno del artículo (ID del sistema): {$numero_disponible}
- Código de barras (bar_code): {$bar_code_disponible}
- SKU (sku): {$sku_disponible}
- Código de proveedor (provider_code): {$provider_code_disponible}
- Nombre del artículo: {$nombre_disponible}

IMPORTANTE: solo podés recomendar usar una clave de identidad si esa columna existe en el Excel (ver "Columnas disponibles" arriba). Si una columna NO está disponible, no la recomiendes.

Decisión 1 - clave_identidad: qué campo usar para identificar un artículo como "el mismo".
Elegí SIEMPRE la primera opción disponible en este orden de prioridad (de más confiable a menos):
1. "numero": el número interno (ID del sistema). Es la clave más confiable y sólo aparece cuando el Excel fue exportado desde este mismo sistema. Si está disponible, preferila por sobre todas.
2. "bar_code": el código de barras. Clave de identidad natural del artículo. Preferila si no hay número.
3. "sku": el SKU del artículo. Usala si no hay número ni código de barras.
4. "provider_code": el código de proveedor. Típico de listas de proveedor; usala si no hay número, código de barras ni SKU.
5. "name": el nombre del artículo. Último recurso, sólo si ninguna de las anteriores está disponible.

Decisión 2 - politica_colision: qué hacer cuando un código coincide con más de un artículo ya existente en el sistema.
- "actualizar_todos": cada fila del Excel actualiza TODOS los artículos que tengan ese código de proveedor. Es lo que quiere una distribuidora que usa el mismo código en varios artículos físicos y actualiza el costo de todos con una fila. También es la opción correcta cuando clave_identidad es "numero", "bar_code", "sku" o "name" (esas claves nunca se repiten, así que "todos" es a lo sumo uno) o cuando todavía no hay nada existente contra qué coincidir.
- "saltear_y_reportar": si un código coincide con más de un artículo, esa fila NO se crea ni se actualiza y queda reportada como problema para resolver a mano. Es la opción conservadora: no toca nada de lo que no está seguro.
- "crear_nuevo": no se identifica por código de proveedor. Las filas que solo tienen ese código crean artículos nuevos aunque el código ya exista. Sirve cuando el código de proveedor del catálogo no es confiable. NUNCA recomiendes esta opción vos: está reservada para casos manuales.

REGLAS CRÍTICAS para politica_colision (aplicar en orden):
1. Si clave_identidad es "numero", "bar_code", "sku" o "name": recomendá SIEMPRE "actualizar_todos". Ninguna de estas claves puede repetirse en dos artículos del sistema, así que "todos" es a lo sumo uno. Ignorar los conteos de repetidos.
2. Si clave_identidad es "provider_code" y provider_codes_existentes_mismo_proveedor = 0 (primera importación contra ese proveedor): recomendá "actualizar_todos". No hay nada existente contra qué coincidir, así que las tres opciones dan el mismo resultado, pero esta deja el sistema listo para que la PRÓXIMA importación actualice en vez de duplicar. Decilo en la explicación.
3. Si clave_identidad es "provider_code", hay provider_codes_existentes_mismo_proveedor > 0 y provider_codes_duplicados_intra_archivo > 0: recomendá "actualizar_todos".
4. Si clave_identidad es "provider_code", hay provider_codes_existentes_mismo_proveedor > 0 y provider_codes_duplicados_intra_archivo = 0: recomendá "actualizar_todos".

Para el campo "explicacion":
- Describí qué va a pasar en términos concretos y simples.
- Si provider_codes_existentes_mismo_proveedor = 0 explicá que se van a crear los artículos (primera importación).
- Si provider_codes_existentes_mismo_proveedor > 0 explicá que se van a actualizar artículos existentes.
- Si provider_codes_existentes_otros_proveedores > 0, agregá una advertencia breve de que hay códigos que también existen en artículos de otros proveedores, y que el sistema NO los va a tocar a menos que el usuario lo habilite manualmente.
- Si bar_codes_duplicados_intra_archivo > 0, mencioná explícitamente que se detectaron códigos de barras repetidos en el archivo y que el sistema los procesará correctamente: importará un único artículo por código, quedando con la información de la última fila del Excel que lo contenga.
- NUNCA uses términos técnicos internos: nada de "provider_code", "bar_code", "actualizar_todos", "saltear_y_reportar", "crear_nuevo", "clave_identidad", "politica_colision", "intra_archivo", ni ninguna clave del sistema.
- Hablá como si le explicaras a un comerciante qué va a pasar con sus artículos.
- Máximo 3 oraciones claras y directas.
{$numeric_format_section}
Respondé SOLO con un JSON válido, sin markdown ni texto adicional:
{
  "clave_identidad": "numero" | "bar_code" | "sku" | "provider_code" | "name",
  "politica_colision": "actualizar_todos" | "saltear_y_reportar" | "crear_nuevo",
  "explicacion": "texto claro y conciso"
}
PROMPT;

        try {
            /* Llamamos a Claude con el mismo método que usa el análisis principal. */
            $claude_response = $this->call_claude($prompt);

            /* Limpiamos posibles bloques markdown igual que en parse_claude_response(). */
            $clean_text = trim($claude_response);
            $clean_text = preg_replace('/^```(?:json)?\s*/i', '', $clean_text);
            $clean_text = preg_replace('/\s*```$/i', '', $clean_text);
            $clean_text = trim($clean_text);

            $decoded = json_decode($clean_text, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON inválido: ' . json_last_error_msg());
            }

            /* Validamos que los valores estén dentro del set permitido. */
            $clave_identidad  = $decoded['clave_identidad']  ?? null;
            $politica_colision = $decoded['politica_colision'] ?? null;
            $explicacion      = $decoded['explicacion']      ?? null;

            /*
             * Compatibilidad con el valor legado 'actualizar_uno' (grupo 284, prompt 02): quedó en
             * el historial de respuestas de Claude y puede salir por inercia aunque el prompt ya no
             * lo mencione. 'actualizar_uno' prometía elegir el artículo más antiguo entre varios
             * coincidentes, una conducta que nunca existió en el backend (el modo real era crear un
             * artículo nuevo, ver el "por qué" del prompt 02). De las tres opciones que sí existen,
             * la más cercana a esa intención es no elegir ninguno y avisar. No se agrega a
             * $valid_politicas: es una traducción de entrada, no un valor soportado.
             */
            if ($politica_colision === 'actualizar_uno') {
                $politica_colision = 'saltear_y_reportar';
            }

            if (
                !in_array($clave_identidad, $valid_claves, true)
                || !in_array($politica_colision, $valid_politicas, true)
            ) {
                throw new \RuntimeException(
                    "Valores fuera de rango: clave_identidad={$clave_identidad}, politica_colision={$politica_colision}"
                );
            }

            /*
             * Override determinístico de politica_colision.
             * Claude puede malinterpretar el valor numérico de provider_codes_duplicados_intra_archivo.
             * La regla es simple y no requiere juicio subjetivo: si hay códigos repetidos en el Excel
             * y la clave es provider_code, la política debe ser actualizar_todos sin excepción.
             * Para numero, bar_code, sku y name nunca puede haber repetidos, así que siempre
             * actualizar_todos (a lo sumo hay uno, así que "todos" y "uno" son la misma operación).
             */
            if ($clave_identidad === 'provider_code' && $stats['provider_codes_duplicados_intra_archivo'] > 0) {
                $politica_colision = 'actualizar_todos';
            } elseif (in_array($clave_identidad, ['numero', 'bar_code', 'sku', 'name'], true)) {
                // Claves únicas del sistema: nunca puede haber dos artículos con el mismo valor.
                $politica_colision = 'actualizar_todos';
            }

            Log::info('AiExcelAnalyzer: recomendación de configuración recibida', [
                'clave_identidad'  => $clave_identidad,
                'politica_colision' => $politica_colision,
            ]);

            return [
                'clave_identidad'  => $clave_identidad,
                'politica_colision' => $politica_colision,
                'explicacion'      => is_string($explicacion) ? trim($explicacion) : '',
            ];

        } catch (\Throwable $e) {
            Log::warning('AiExcelAnalyzer: fallo en recomendación de Claude, aplicando fallback', [
                'error' => $e->getMessage(),
            ]);

            /*
             * Fallback heurístico con la MISMA prioridad que el matching real
             * (ArticleIndexCache::find_with_index): numero -> bar_code -> sku -> provider_code -> name.
             */
            if ($tiene_numero) {
                $clave_fallback = 'numero';
            } elseif ($tiene_bar_code) {
                $clave_fallback = 'bar_code';
            } elseif ($tiene_sku) {
                $clave_fallback = 'sku';
            } elseif ($tiene_provider_code) {
                $clave_fallback = 'provider_code';
            } else {
                $clave_fallback = 'name';
            }

            /*
             * Fallback heurístico para politica_colision (grupo 284, prompt 02):
             * - numero, bar_code, sku y name son siempre únicos: actualizar_todos (a lo sumo hay
             *   uno, así que "todos" y "uno" son la misma operación).
             * - provider_code sin nada existente en la base todavía: actualizar_todos (no hay
             *   contra qué coincidir, y deja el sistema listo para que la próxima importación
             *   actualice en vez de duplicar).
             * - provider_code con existentes en la base: actualizar_todos también, SALVO que el
             *   archivo tenga muchos códigos de proveedor repetidos respecto del total de filas
             *   (por encima de UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK), en cuyo caso
             *   es más seguro no arriesgarse y recomendar saltear_y_reportar.
             * Nunca se recomienda crear_nuevo en el fallback: es la única opción que puede
             * duplicar el catálogo.
             */
            if (in_array($clave_fallback, ['numero', 'bar_code', 'sku', 'name'], true)) {
                $politica_fallback = 'actualizar_todos';
            } elseif (
                $clave_fallback === 'provider_code'
                && $stats['provider_codes_existentes_mismo_proveedor'] > 0
                && $stats['total_filas_datos'] > 0
                && ($stats['provider_codes_duplicados_intra_archivo'] / $stats['total_filas_datos']) >= self::UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK
            ) {
                $politica_fallback = 'saltear_y_reportar';
            } else {
                $politica_fallback = 'actualizar_todos';
            }

            return [
                'clave_identidad'  => $clave_fallback,
                'politica_colision' => $politica_fallback,
                'explicacion'      => 'Recomendación generada automáticamente porque la IA no devolvió una respuesta válida.',
            ];
        }
    }

    /**
     * Arma el cliente HTTP hacia Anthropic con headers y TLS (ca_bundle / verify_ssl).
     *
     * Mismo criterio que admin-api SupportAiSuggestionService::build_http_client().
     *
     * @param  string $api_key  Clave ANTHROPIC_API_KEY
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_anthropic_http_client(string $api_key)
    {
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }
}
