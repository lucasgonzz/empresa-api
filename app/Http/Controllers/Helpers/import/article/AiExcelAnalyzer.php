<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;

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
     * @var int
     */
    protected const MAX_TOKENS = 2000;

    /**
     * Lista de propiedades del sistema importables que Claude puede identificar.
     * Deben coincidir exactamente con los valores que el frontend puede manejar.
     *
     * @var array
     */
    protected const SYSTEM_PROPERTIES = [
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
        'recargos',
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
         * Paso 3: Construir el prompt y llamar a Claude.
         */
        $prompt = $this->build_prompt($sample_data, $providers, $original_filename);

        $claude_response = $this->call_claude($prompt);

        /*
         * Paso 4: Parsear y validar el JSON devuelto por Claude.
         */
        $parsed = $this->parse_claude_response($claude_response, $providers);

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
         * Paso 9: Pedir recomendación de configuración a Claude basada en los conteos.
         * Pasamos también el mapeo de columnas para que Claude sepa qué columnas existen
         * y no recomiende claves de identidad que no están mapeadas en el Excel.
         * Si la llamada falla, se aplica un fallback heurístico y la respuesta sigue siendo HTTP 200.
         */
        $parsed['recomendacion_configuracion'] = $this->ask_claude_for_recomendation(
            $parsed['duplicate_stats'],
            $parsed['column_mapping']
        );

        return $parsed;
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
     * Construye el prompt que se envía a Claude con los datos del Excel y los proveedores.
     *
     * @param  array  $sample_data        Resultado de read_sample_rows()
     * @param  array  $providers          Lista de proveedores disponibles
     * @param  string $original_filename  Nombre original del archivo subido por el usuario
     * @return string                     Prompt completo listo para enviar a la API
     */
    protected function build_prompt(array $sample_data, array $providers, string $original_filename = ''): string
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
  "provider_confidence": "alto"
}

Notas:
- confidence es un número entre 0 y 1 indicando seguridad del mapeo
- interpretation_note: obligatorio en español cuando interpretás "Descripción" (u homólogo) como nombre; null en el resto de los casos
- provider_confidence debe ser "alto", "medio" o "bajo"
- provider_id debe ser el ID numérico del proveedor o null si no se puede inferir
- Devolvé SOLO el JSON, sin markdown ni texto adicional
PROMPT;

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

            /* Error de sobrecarga (HTTP 529): mensaje amigable para el usuario. */
            if ($response->status() === 529) {
                throw new \RuntimeException(
                    'El servicio de IA está temporalmente sobrecargado. Esperá unos segundos y volvé a intentarlo.'
                );
            }

            /* Otros errores: mensaje técnico para debugging (no llega al usuario final en producción). */
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
     * @return array                Array con claves: column_mapping, provider_id, provider_confidence
     *
     * @throws \RuntimeException  Si el JSON no puede parsearse o tiene estructura inválida
     */
    protected function parse_claude_response(string $claude_text, array $providers = []): array
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

        return [
            'column_mapping'      => $parsed['column_mapping'],
            'provider_id'         => $provider_id,
            'provider_confidence' => $provider_confidence,
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
            if (!is_null($system_property)) {
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
     * @param  array $stats          Resultado de ExcelDuplicateStats::analyze()
     * @param  array $column_mapping Mapeo enriquecido de columnas (para derivar columnas disponibles)
     * @return array                 ['clave_identidad' => string, 'politica_colision' => string, 'explicacion' => string]
     */
    protected function ask_claude_for_recomendation(array $stats, array $column_mapping = []): array
    {
        /*
         * Valores aceptados para cada campo de la recomendación.
         * Se usan para validar la respuesta de Claude antes de retornarla.
         */
        $valid_claves    = ['bar_code', 'provider_code', 'name'];
        $valid_politicas = ['actualizar_todos', 'actualizar_uno', 'crear_nuevo'];

        /*
         * Derivamos qué columnas clave están disponibles en este Excel.
         * Se hace antes del try/catch para que el fallback también pueda usarlas.
         * Solo se marca true si la columna está efectivamente mapeada en el Excel.
         */
        $tiene_bar_code      = false;
        $tiene_provider_code = false;
        $tiene_nombre        = false;

        foreach ($column_mapping as $col) {
            $prop = $col['system_property'] ?? null;
            if ($prop === 'codigo_de_barras')    $tiene_bar_code      = true;
            if ($prop === 'codigo_de_proveedor') $tiene_provider_code = true;
            if ($prop === 'nombre')              $tiene_nombre        = true;
        }

        /* Textos "Sí" / "No" para el prompt. */
        $bar_code_disponible      = $tiene_bar_code      ? 'Sí' : 'No';
        $provider_code_disponible = $tiene_provider_code ? 'Sí' : 'No';
        $nombre_disponible        = $tiene_nombre        ? 'Sí' : 'No';

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
- Código de barras (bar_code): {$bar_code_disponible}
- Código de proveedor (provider_code): {$provider_code_disponible}
- Nombre del artículo: {$nombre_disponible}

IMPORTANTE: solo podés recomendar usar una clave de identidad si esa columna existe en el Excel.
Si bar_code NO existe, no recomiendes bar_code como clave_identidad.
Si provider_code NO existe, no recomiendes provider_code como clave_identidad.
Si ninguno de los dos existe, recomendá name.

Decisión 1 - clave_identidad: qué campo usar para identificar un artículo como "el mismo".
- "bar_code": usar solo si la columna bar_code está disponible Y no tiene duplicados dentro del Excel (bar_codes_duplicados_intra_archivo = 0). Es la opción más confiable cuando aplica.
- "provider_code": usar cuando bar_code no está disponible o tiene duplicados. Es la opción más común en listas de proveedor.
- "name": último recurso, solo si ni bar_code ni provider_code están disponibles.

IMPORTANTE: solo podés recomendar una clave si esa columna existe en el Excel (ver "Columnas disponibles" arriba).

Decisión 2 - politica_colision: qué hacer cuando una fila del Excel coincide con artículos ya existentes en el sistema.
- "actualizar_todos": el sistema encuentra TODOS los artículos con ese código y los actualiza o crea. SOLO válido cuando clave_identidad = "provider_code" y hay provider_codes repetidos en el Excel.
- "actualizar_uno": actualiza o crea un único artículo por fila. Es la opción correcta para bar_code y name (que deben ser únicos), y también para provider_code cuando no hay repetidos.
- "crear_nuevo": NUNCA recomiendes esta opción. Está reservada para casos manuales.

REGLAS CRÍTICAS para politica_colision (aplicar en orden):
1. Si clave_identidad es "bar_code" o "name": recomendá SIEMPRE "actualizar_uno". No puede haber dos artículos con el mismo código de barras ni con el mismo nombre. Ignorar los conteos de repetidos.
2. Si clave_identidad es "provider_code" y provider_codes_duplicados_intra_archivo > 0: recomendá "actualizar_todos". El sistema creará un artículo por cada fila en primera importación, y actualizará todos los coincidentes en reimportaciones.
3. Si clave_identidad es "provider_code" y provider_codes_duplicados_intra_archivo = 0: recomendá "actualizar_uno".

Para el campo "explicacion":
- Describí qué va a pasar en términos concretos y simples.
- Si provider_codes_existentes_mismo_proveedor = 0 explicá que se van a crear los artículos (primera importación).
- Si provider_codes_existentes_mismo_proveedor > 0 explicá que se van a actualizar artículos existentes.
- Si provider_codes_existentes_otros_proveedores > 0, agregá una advertencia breve de que hay códigos que también existen en artículos de otros proveedores, y que el sistema NO los va a tocar a menos que el usuario lo habilite manualmente.
- NUNCA uses términos técnicos internos: nada de "provider_code", "bar_code", "actualizar_todos", "actualizar_uno", "crear_nuevo", "clave_identidad", "politica_colision", "intra_archivo", ni ninguna clave del sistema.
- Hablá como si le explicaras a un comerciante qué va a pasar con sus artículos.
- Máximo 3 oraciones claras y directas.

Respondé SOLO con un JSON válido, sin markdown ni texto adicional:
{
  "clave_identidad": "bar_code" | "provider_code" | "name",
  "politica_colision": "actualizar_todos" | "actualizar_uno" | "crear_nuevo",
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
             * Para bar_code y name nunca puede haber repetidos, así que siempre actualizar_uno.
             */
            if ($clave_identidad === 'provider_code' && $stats['provider_codes_duplicados_intra_archivo'] > 0) {
                $politica_colision = 'actualizar_todos';
            } elseif ($clave_identidad === 'bar_code' || $clave_identidad === 'name') {
                $politica_colision = 'actualizar_uno';
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
             * Fallback heurístico: prioridad bar_code → provider_code → name.
             * Si la columna no existe en el Excel, se descarta aunque sea la preferida.
             * Esto evita el caso donde Claude alucina bar_code cuando no hay columna mapeada.
             */
            if ($tiene_bar_code && $stats['bar_codes_duplicados_intra_archivo'] === 0) {
                $clave_fallback = 'bar_code';
            } elseif ($tiene_provider_code) {
                $clave_fallback = 'provider_code';
            } elseif ($tiene_bar_code) {
                $clave_fallback = 'bar_code';
            } else {
                $clave_fallback = 'name';
            }

            /*
             * Fallback heurístico para politica_colision:
             * - bar_code y name son siempre únicos: actualizar_uno.
             * - provider_code con repetidos en el Excel: actualizar_todos.
             * - provider_code sin repetidos: actualizar_uno.
             * Nunca se recomienda crear_nuevo en el fallback.
             */
            if ($clave_fallback === 'bar_code' || $clave_fallback === 'name') {
                $politica_fallback = 'actualizar_uno';
            } elseif (
                $clave_fallback === 'provider_code'
                && $stats['provider_codes_duplicados_intra_archivo'] > 0
            ) {
                $politica_fallback = 'actualizar_todos';
            } else {
                $politica_fallback = 'actualizar_uno';
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
