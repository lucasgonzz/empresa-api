<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Provider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

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
        'codigo_barras',
        'sku',
        'codigo_proveedor',
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
     * @param  string $excel_path  Ruta absoluta al archivo Excel ya guardado en storage
     * @return array               Array con claves: column_mapping, provider_id, provider_confidence
     *
     * @throws \RuntimeException  Si el archivo no puede leerse o Claude no devuelve JSON válido
     */
    public function analyze(string $excel_path): array
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
        $prompt = $this->build_prompt($sample_data, $providers);

        $claude_response = $this->call_claude($prompt);

        /*
         * Paso 4: Parsear y validar el JSON devuelto por Claude.
         */
        return $this->parse_claude_response($claude_response);
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
        $rows = [];

        /*
         * Usamos el lector XLSX de OpenSpout, el mismo que InitExcelImport,
         * para garantizar compatibilidad con los formatos ya aceptados.
         */
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows(true);
        $reader->open($excel_path);

        /* Contador de fila leída en la hoja; la fila 1 es la cabecera. */
        $row_number = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $row_number++;

                /* Extraemos los valores celdas como strings simples. */
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = (string)($value ?? '');
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
     * @param  array $sample_data  Resultado de read_sample_rows()
     * @param  array $providers    Lista de proveedores disponibles
     * @return string              Prompt completo listo para enviar a la API
     */
    protected function build_prompt(array $sample_data, array $providers): string
    {
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

## Encabezados del Excel
{$headers_line}

## Primeras filas de datos (muestra)
{$rows_lines}

## Proveedores disponibles en el sistema
{$providers_text}

## Propiedades del sistema disponibles
{$system_properties_list}

## Instrucciones
1. Analizá cada columna del Excel y mapeala a la propiedad del sistema más apropiada.
2. Si una columna no corresponde a ninguna propiedad del sistema, usá null en system_property.
3. Determiná a qué proveedor pertenece este listado de precios (si podés inferirlo).
4. Devolvé EXCLUSIVAMENTE el siguiente JSON sin texto adicional:

{
  "column_mapping": [
    {
      "excel_column": "nombre exacto del encabezado en el Excel",
      "system_property": "propiedad del sistema o null",
      "confidence": 0.95
    }
  ],
  "provider_id": null,
  "provider_confidence": "alto"
}

Notas:
- confidence es un número entre 0 y 1 indicando seguridad del mapeo
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
        /* Clave de API de Anthropic configurada en .env como ANTHROPIC_API_KEY. */
        $api_key = config('app.ANTHROPIC_API_KEY');

        if (empty($api_key)) {
            throw new \RuntimeException('La clave ANTHROPIC_API_KEY no está configurada en el entorno.');
        }

        Log::info('AiExcelAnalyzer: llamando a Claude API', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        /*
         * Usamos el cliente HTTP nativo de Laravel para la llamada a Anthropic.
         * El timeout es generoso porque Claude puede tardar varios segundos.
         */
        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
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
     * @return array                Array con claves: column_mapping, provider_id, provider_confidence
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
         * Normalizamos los valores opcionales para garantizar estructura consistente
         * aunque Claude omita alguna clave.
         */
        return [
            'column_mapping'      => $parsed['column_mapping'],
            'provider_id'         => $parsed['provider_id'] ?? null,
            'provider_confidence' => $parsed['provider_confidence'] ?? 'bajo',
        ];
    }
}
