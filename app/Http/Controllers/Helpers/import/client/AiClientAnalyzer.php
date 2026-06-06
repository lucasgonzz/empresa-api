<?php

namespace App\Http\Controllers\Helpers\import\client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Helper que analiza un archivo Excel de importación de clientes utilizando la API de Claude (Anthropic).
 *
 * Responsabilidades:
 * 1. Leer las primeras filas del Excel usando OpenSpout.
 * 2. Armar un payload con headers + muestra de datos.
 * 3. Llamar a la API de Claude y devolver el JSON de mapeo de columnas parseado.
 *
 * No gestiona proveedores ni lógica de artículos.
 * Este helper NO guarda nada en base de datos; solo analiza y retorna sugerencias.
 */
class AiClientAnalyzer
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
     * Lista de propiedades del sistema importables para clientes que Claude puede identificar.
     * Deben coincidir exactamente con los valores que el frontend puede manejar.
     *
     * @var array
     */
    protected const SYSTEM_PROPERTIES = [
        'nombre',
        'telefono',
        'email',
        'direccion',
        'localidad',
        'provincia',
        'cuit',
        'cuil',
        'dni',
        'razon_social',
        'numero',
        'vendedor',
        'condicion_frente_al_iva',
        'tipo_de_precio',
        'saldo_actual',
        'descripcion',
    ];

    /**
     * ID del usuario propietario, reservado para extensiones futuras.
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
     * @param  string $original_filename    Nombre del archivo tal como lo subió el usuario
     * @return array                        Array con claves: column_mapping, provider_id (null), provider_confidence, row_count
     *
     * @throws \RuntimeException  Si el archivo no puede leerse o Claude no devuelve JSON válido
     */
    public function analyze(string $excel_path, string $original_filename = ''): array
    {
        /*
         * Paso 1: Leer headers y filas de muestra del Excel.
         */
        $sample_data = $this->read_sample_rows($excel_path);

        /*
         * Paso 2: Construir el prompt y llamar a Claude.
         */
        $prompt = $this->build_prompt($sample_data, $original_filename);

        $claude_response = $this->call_claude($prompt);

        /*
         * Paso 3: Parsear y validar el JSON devuelto por Claude.
         */
        $parsed = $this->parse_claude_response($claude_response);

        /*
         * Paso 4: Enriquecer cada columna con letra Excel, índice 0-based y confianza normalizada.
         */
        $parsed['column_mapping'] = $this->enrich_column_mapping(
            $parsed['column_mapping'],
            $sample_data['headers']
        );

        /*
         * Paso 5: Contar el total real de filas de datos del Excel (excluye cabecera).
         */
        $parsed['row_count'] = $this->count_data_rows($excel_path);

        return $parsed;
    }

    /**
     * Lee las primeras N filas del Excel y retorna un array con headers y muestra.
     *
     * La primera fila se trata siempre como cabecera.
     * Las siguientes filas son datos de muestra.
     *
     * @param  string $excel_path  Ruta al archivo Excel
     * @return array               ['headers' => [...], 'rows' => [[...], ...]]
     *
     * @throws \RuntimeException  Si el archivo no puede abrirse con OpenSpout
     */
    protected function read_sample_rows(string $excel_path): array
    {
        $headers = [];
        $rows    = [];

        /* Lector XLSX de OpenSpout para compatibilidad con los formatos ya aceptados. */
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($excel_path);

        /* Contador de fila leída en la hoja; la fila 1 es la cabecera. */
        $row_number = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $row_number++;

                /* Extraemos los valores de las celdas como strings simples. */
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = (string) ($value ?? '');
                }

                if ($row_number === 1) {
                    /* La primera fila contiene los encabezados de columna. */
                    $headers = $cells;
                } else {
                    $rows[] = $cells;
                }

                /* Dejamos de leer una vez que tenemos suficientes filas de muestra. */
                if ($row_number > self::SAMPLE_ROWS) {
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
     * Construye el prompt que se envía a Claude con los datos del Excel.
     *
     * No incluye lógica de proveedores; se centra en identificar propiedades de clientes.
     *
     * @param  array  $sample_data        Resultado de read_sample_rows()
     * @param  string $original_filename  Nombre original del archivo subido por el usuario
     * @return string                     Prompt completo listo para enviar a la API
     */
    protected function build_prompt(array $sample_data, string $original_filename = ''): string
    {
        /* Nombre del archivo para el prompt (sin ruta, solo nombre + extensión). */
        $filename_for_prompt = trim(basename($original_filename));
        if ($filename_for_prompt === '') {
            $filename_for_prompt = '(no disponible)';
        }

        /* Cabecera del Excel como texto separado por pipes para Claude. */
        $headers_line = implode(' | ', $sample_data['headers']);

        /* Filas de muestra, una por línea, con las celdas separadas por pipe. */
        $rows_lines = '';
        foreach ($sample_data['rows'] as $row) {
            $rows_lines .= implode(' | ', $row) . "\n";
        }

        /* Lista de propiedades del sistema que Claude puede asignar. */
        $system_properties_list = implode(', ', self::SYSTEM_PROPERTIES);

        /*
         * El prompt le explica a Claude exactamente qué debe devolver y en qué formato.
         * Se pide explícitamente JSON puro sin markdown para facilitar el parseo.
         */
        $prompt = <<<PROMPT
Analizá el siguiente archivo Excel de importación de clientes y devolvé SOLO un JSON válido (sin markdown, sin explicaciones extra).

## Nombre del archivo subido
{$filename_for_prompt}

## Encabezados del Excel
{$headers_line}

## Primeras filas de datos (muestra)
{$rows_lines}

## Propiedades del sistema disponibles
{$system_properties_list}

## Instrucciones generales
1. Analizá cada columna del Excel y mapeala a la propiedad del sistema más apropiada.
2. Si una columna no corresponde a ninguna propiedad del sistema, usá null en system_property.
3. La propiedad más importante es "nombre" — es el nombre o razón social del cliente.
4. La propiedad "numero" corresponde al número o código de cliente (identificador interno).
5. Para "condicion_frente_al_iva": mapeá columnas como "Condición IVA", "IVA", "Tipo IVA" a esta propiedad.
6. Para "tipo_de_precio": mapeá columnas como "Lista de precios", "Tipo precio" a esta propiedad.
7. Para "saldo_actual": mapeá columnas de saldo, deuda o cuenta corriente a esta propiedad.

8. Devolvé EXCLUSIVAMENTE el siguiente JSON sin texto adicional:

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
  "provider_confidence": "bajo"
}

Notas:
- confidence es un número entre 0 y 1 indicando seguridad del mapeo
- interpretation_note: completar en español solo cuando la asignación necesita explicación; null en el resto de los casos
- provider_id debe ser siempre null (no aplica para importación de clientes)
- provider_confidence debe ser siempre "bajo" (no aplica para importación de clientes)
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

        Log::info('AiClientAnalyzer: llamando a Claude API', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        /* Cliente HTTP con la misma configuración TLS que admin-api. */
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
            Log::error('AiClientAnalyzer: error en respuesta de Claude', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

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

        Log::info('AiClientAnalyzer: respuesta de Claude recibida', [
            'response_preview' => substr($text, 0, 300),
        ]);

        return $text;
    }

    /**
     * Parsea el texto de respuesta de Claude y extrae el JSON con el mapeo.
     *
     * Para clientes, provider_id es siempre null y provider_confidence siempre 'bajo'.
     *
     * @param  string $claude_text  Texto crudo devuelto por Claude
     * @return array                Array con claves: column_mapping, provider_id (null), provider_confidence ('bajo')
     *
     * @throws \RuntimeException  Si el JSON no puede parsearse o tiene estructura inválida
     */
    protected function parse_claude_response(string $claude_text): array
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
            Log::error('AiClientAnalyzer: JSON inválido en respuesta de Claude', [
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
         * Para importación de clientes no hay proveedor inferido.
         * Forzamos provider_id = null y provider_confidence = 'bajo' siempre.
         */
        return [
            'column_mapping'      => $parsed['column_mapping'],
            'provider_id'         => null,
            'provider_confidence' => 'bajo',
        ];
    }

    /**
     * Completa cada ítem del mapeo con letra de columna Excel, índice y confianza numérica.
     *
     * No aplica normalización de alias (como ArticleImportColumnsNormalizer),
     * ya que las propiedades de clientes no requieren alias especiales.
     *
     * @param  array $column_mapping  Mapeo devuelto por Claude
     * @param  array $headers         Encabezados de la primera fila del Excel (orden real)
     * @return array                  Mismo mapeo enriquecido para la API
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
            $confidence     = max(0, min(1, (float) $raw_confidence));

            $excel_column_name      = (string) ($mapping_item['excel_column'] ?? '');
            $normalized_excel_name  = $this->normalize_header_key($excel_column_name);

            /*
             * Preferimos el índice que coincide con el encabezado leído del archivo;
             * si no hay match, usamos la posición en el array (orden de Claude).
             */
            $excel_column_index = $array_position;
            if ($normalized_excel_name !== '' && isset($header_index_by_name[$normalized_excel_name])) {
                $excel_column_index = $header_index_by_name[$normalized_excel_name];
            }

            /* Usamos la system_property tal cual devuelve Claude (sin alias). */
            $system_property = $mapping_item['system_property'] ?? null;
            if (!is_null($system_property)) {
                $system_property = (string) $system_property;
                /* Descartamos propiedades que no están en el contrato del importador de clientes. */
                if (!in_array($system_property, self::SYSTEM_PROPERTIES, true)) {
                    $system_property = null;
                }
            }

            /* Nota opcional para el usuario cuando la IA necesita explicar el mapeo. */
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
                'system_property'     => $system_property,
                'confidence'          => $confidence,
                'interpretation_note' => $interpretation_note,
                'excel_column_index'  => $excel_column_index,
                'excel_column_letter' => $this->number_to_excel_column($excel_column_index + 1),
            ]);
        }

        return $enriched_mapping;
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
     * Cuenta el total de filas de datos del Excel (excluye la primera fila de cabecera).
     *
     * @param  string $excel_path  Ruta absoluta al archivo Excel
     * @return int                 Cantidad de filas de datos
     */
    protected function count_data_rows(string $excel_path): int
    {
        /* Contador de filas de datos (sin contar la primera fila de cabecera). */
        $data_row_count = 0;

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(false);
        $reader->open($excel_path);

        foreach ($reader->getSheetIterator() as $sheet) {
            /* Bandera para saltar la primera fila (cabecera). */
            $first_row_skipped = false;

            foreach ($sheet->getRowIterator() as $row) {
                if (!$first_row_skipped) {
                    $first_row_skipped = true;
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
     * @return string              Letra o letras de columna (p. ej. "AA")
     */
    protected function number_to_excel_column(int $column_number): string
    {
        $column_letter = '';

        while ($column_number > 0) {
            $remainder     = ($column_number - 1) % 26;
            $column_letter = chr(65 + $remainder) . $column_letter;
            $column_number = (int) floor(($column_number - 1) / 26);
        }

        return $column_letter;
    }

    /**
     * Arma el cliente HTTP hacia Anthropic con headers y TLS (ca_bundle / verify_ssl).
     *
     * Mismo criterio que AiExcelAnalyzer::build_anthropic_http_client().
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
