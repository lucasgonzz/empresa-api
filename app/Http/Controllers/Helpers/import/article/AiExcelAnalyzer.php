<?php

namespace App\Http\Controllers\Helpers\import\article;

use App\Models\Address;
use App\Models\Provider;
use App\Models\User;
use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;

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

    /*
     * ---------------------------------------------------------------------------------
     * Mensajes que ve el usuario cuando la IA falla.
     *
     * 🔴 Los tres analizadores (AiExcelAnalyzer, AiClientAnalyzer, AiProviderAnalyzer) son
     * copias casi idénticas. Estos cinco textos están repetidos a propósito en los tres y
     * TIENEN QUE SER IDÉNTICOS: tests/Import/MensajesDeErrorTest.php lo verifica clase por
     * clase. Ya pasó en este módulo que un arreglo se hizo en uno solo y se perdió en los
     * otros dos sin que nadie se enterara. Si tocás uno, tocá los tres.
     *
     * Ninguno lleva detalle técnico. El status HTTP y el body de Anthropic siguen yendo
     * completos al Log::error de call_claude(), que es donde sirven. Hasta esta misión el
     * body crudo de la API —y hasta el nombre de la variable de entorno que faltaba— se le
     * mostraban al usuario tal cual: eso le cuenta a un tercero cómo está configurado el
     * servidor.
     * ---------------------------------------------------------------------------------
     */

    /** La API contestó un error que no es transitorio (auth, rate limit, request inválido). */
    const MENSAJE_IA_RECHAZO = 'El servicio de IA rechazó el pedido. Volvé a intentar en unos minutos; si sigue pasando, avisanos.';

    /** Error transitorio de Anthropic (overloaded_error, api_error, HTTP 529). */
    const MENSAJE_IA_NO_DISPONIBLE = 'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.';

    /** La conexión con Anthropic se cortó o venció el timeout de 60 segundos. */
    const MENSAJE_IA_SIN_RESPUESTA = 'El servicio de IA no respondió a tiempo. Probá de nuevo en un minuto: los archivos grandes a veces necesitan un segundo intento.';

    /** Contestó, pero lo que devolvió no se puede usar: sin texto, sin JSON válido o sin column_mapping. */
    const MENSAJE_IA_RESPUESTA_ILEGIBLE = 'La IA no pudo interpretar esta planilla. Probá de nuevo; si sigue pasando, revisá que el encabezado esté en la fila correcta o avisanos.';

    /** Falta la clave de API en la configuración del servidor. */
    const MENSAJE_IA_SIN_CONFIGURAR = 'La importación con IA no está configurada en este sistema. Avisanos para que la activemos.';

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
     * @param  array  $opciones             ['hoja' => int|null, 'hoja_nombre' => string|null, 'fila_encabezado' => int|null]
     * @return array                        Array con claves: column_mapping, provider_id, provider_confidence
     *
     * @throws \RuntimeException  Si el archivo no puede leerse o Claude no devuelve JSON válido
     */
    public function analyze(string $excel_path, string $original_filename = '', array $opciones = []): array
    {
        /*
         * Paso 0: hoja elegida y fila de encabezado.
         *
         * 🔴 $opciones ENTERO ES OPCIONAL, Y NO ES UN GUSTO DE ESTILO.
         * AdminSync\AiExcelImportController llama analyze($excel_path, $original_filename)
         * con DOS argumentos y está fuera del alcance de esta misión, así que no se toca.
         * Un tercer parámetro obligatorio acá es un ArgumentCountError en el endpoint que
         * usa admin-api contra clientes reales. Lo mismo vale para cada método de más abajo
         * que suma 'hoja'/'fila_encabezado': todos con default, siempre.
         */
        $hoja_pedida     = isset($opciones['hoja']) ? $opciones['hoja'] : null;
        $hoja_nombre     = isset($opciones['hoja_nombre']) ? $opciones['hoja_nombre'] : null;
        $fila_pedida     = isset($opciones['fila_encabezado']) ? $opciones['fila_encabezado'] : null;

        /*
         * 🔴 EL LIBRO SE LISTA UNA SOLA VEZ, Y POR ESO EL ÍNDICE SE RESUELVE SOBRE EL ARRAY.
         *
         * ExcelWorkbookReader::resolver_indice() lista el libro por adentro. Llamarlo y
         * después pedir listar_hojas() son DOS Reader::open() completos de OpenSpout, y cada
         * uno parsea sharedStrings.xml entero: medido sobre un xlsx de 20.000 filas,
         * resolver_indice 195ms + listar_hojas 220ms, tirados en TODO archivo, incluso en los
         * de una sola hoja. Es la misma presión de memoria y de parseo que el plan usó para
         * justificar todo el camino ZIP+XMLReader del inspector; pagarla dos veces acá sería
         * contradecir el propio argumento.
         *
         * El libro entero se ofrece siempre, aunque tenga una sola hoja: la SPA arma el
         * selector del paso 1 con esto.
         */
        $hojas = ExcelWorkbookReader::listar_hojas($excel_path);

        /* Nombre antes que índice: el índice puede venir de SheetJS y no coincidir (T11 del plan). */
        $indice_hoja = $this->resolver_indice_sobre($hojas, $hoja_pedida, $hoja_nombre);

        $encabezado = $this->resolver_encabezado($excel_path, $indice_hoja, $fila_pedida);

        /*
         * Paso 1: Leer headers y filas de muestra del Excel.
         * Limitamos la lectura para no cargar archivos grandes en memoria.
         */
        $sample_data = $this->read_sample_rows($excel_path, $indice_hoja, $encabezado['fila']);

        /*
         * Los encabezados que alimentan el prompt y el mapeo salen del detector, NO del
         * read_sample_rows crudo. Son los de la misma fila, pero con las fusiones ya
         * propagadas: Excel no escribe la celda cubierta por una fusión, así que "PRECIOS"
         * sobre E1:F1 deja la columna F sin ningún nombre en el XML y el mapeo pierde una
         * columna entera. read_sample_rows() se deja leyendo crudo a propósito (es lo que
         * caracterizan los tests de no regresión de la unidad 1).
         */
        if (!empty($encabezado['columnas'])) {
            $sample_data['headers'] = $encabezado['columnas'];
        }

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
        $parsed['row_count'] = $this->count_data_rows($excel_path, $indice_hoja, $encabezado['fila']);

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
        $duplicate_stats = ExcelDuplicateStats::analyze(
            $excel_path,
            $bar_code_idx,
            $provider_code_idx,
            $parsed['provider_id'] ?? null,
            $this->user_id,
            ['hoja' => $indice_hoja, 'fila_encabezado' => $encabezado['fila']]
        );

        /*
         * (grupo 291, prompt 03) ExcelDuplicateStats::analyze() ahora también devuelve
         * 'provider_codes_distintos': la lista completa de provider_codes distintos del
         * archivo. RunExcelAnalysisJob la necesita para persistirla en
         * excel_analysis_runs.codigos_proveedor (así refreshProviderStats() no tiene que
         * releer el archivo), pero puede tener decenas de miles de strings y NO debe viajar
         * en $parsed['duplicate_stats']: ese array se copia tal cual dentro de
         * RunExcelAnalysisJob::handle() a "resultado", que a su vez se devuelve tal cual por
         * GET /ai-excel-import/analysis/{uuid}. Por eso se saca acá y se guarda aparte, en una
         * clave de nivel superior que el job sí lee pero que el resultado no reexpone.
         */
        $parsed['provider_codes_distintos'] = $duplicate_stats['provider_codes_distintos'] ?? [];
        unset($duplicate_stats['provider_codes_distintos']);
        $parsed['duplicate_stats'] = $duplicate_stats;

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
            $parsed['column_mapping'],
            $indice_hoja,
            $encabezado['fila']
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
            $numeric_columns_map['nombres'],
            6,
            ['hoja' => $indice_hoja, 'fila_encabezado' => $encabezado['fila']]
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

        /*
         * 🔴 LOS NOMBRES DE ESTAS CINCO CLAVES SON CONTRATO CON LA SPA (§1.4 del plan), que
         * ya está codeada contra ellos. Si se le cambia el nombre a una, el modal deja de
         * mostrar el aviso correspondiente y falla EN SILENCIO — no tira ningún error, sólo
         * no avisa. Se agregan; ninguna clave existente cambia de nombre, tipo ni sentido.
         */
        $parsed['hojas'] = $hojas;

        $parsed['hoja_elegida'] = [
            'indice' => $indice_hoja,
            'nombre' => $this->nombre_de_la_hoja($hojas, $indice_hoja),
        ];

        $parsed['encabezado_detectado'] = [
            'fila'      => $encabezado['fila'],
            'origen'    => $encabezado['origen'],
            'motivo'    => $encabezado['motivo'],
            'confianza' => $encabezado['confianza'],
            'columnas'  => $encabezado['columnas'],
        ];

        /* Índices 0-based; [] en el caso normal. */
        $parsed['columnas_sin_nombre'] = $encabezado['columnas_sin_nombre'];

        /* Entero; 0 en el caso normal. */
        $parsed['fusiones_aplicadas'] = (int) $encabezado['fusiones_aplicadas'];

        /*
         * 🔴 CLAVE NUEVA, Y ES LA QUE EVITA EL ERROR MÁS CARO DE TODA LA IMPORTACIÓN.
         * Un nombre de encabezado que cubre dos o más columnas (lo que deja cualquier
         * cabecera fusionada) NO se puede desambiguar desde acá: ver el comentario largo de
         * detectar_columnas_ambiguas(). [] en el caso normal.
         */
        $parsed['columnas_ambiguas'] = $this->detectar_columnas_ambiguas(
            $parsed['column_mapping'],
            $sample_data['headers']
        );

        return $parsed;
    }

    /**
     * Índice 0-based de la hoja a leer, resuelto sobre un listado que YA se leyó.
     *
     * 🔴 ES UN ESPEJO DE ExcelWorkbookReader::resolver_indice(), A PROPÓSITO Y CON COSTO.
     * La regla (nombre exacto -> índice en rango -> 0) vive allá y allá manda; acá se repite
     * porque la versión de allá vuelve a listar el libro por adentro y este análisis ya lo
     * listó (B9: 195ms + 220ms de OpenSpout en todo archivo). Mientras las dos existan hay
     * un invariante decidido en dos lugares, que es la clase de error que ya nos mordió tres
     * veces en esta misión; por eso AnalyzerHojaYEncabezadoTest compara las dos respuestas
     * caso por caso y se pone en rojo si se separan. El arreglo de fondo es una sobrecarga
     * de ExcelWorkbookReader que acepte el listado ya leído, y no entra en esta tanda.
     *
     * @param  array       $hojas        Lo que devolvió ExcelWorkbookReader::listar_hojas()
     * @param  int|null    $hoja         Índice pedido por el usuario (puede venir de SheetJS)
     * @param  string|null $hoja_nombre  Nombre pedido por el usuario
     * @return int
     */
    protected function resolver_indice_sobre(array $hojas, $hoja, $hoja_nombre)
    {
        if (count($hojas) === 0) {
            return 0;
        }

        if ($hoja_nombre !== null && trim((string) $hoja_nombre) !== '') {
            $buscado = trim((string) $hoja_nombre);

            foreach ($hojas as $candidata) {
                if ($candidata['nombre'] === $buscado) {
                    return $candidata['indice'];
                }
            }
        }

        if ($hoja !== null && is_numeric($hoja)) {
            $indice = (int) $hoja;

            foreach ($hojas as $candidata) {
                if ($candidata['indice'] === $indice) {
                    return $indice;
                }
            }
        }

        return 0;
    }

    /**
     * Nombres de encabezado que cubren DOS O MÁS columnas y que el mapeo se repartió a
     * ciegas. Cada entrada es un aviso listo para el paso 2 del modal.
     *
     * 🔴 POR QUÉ ESTO AVISA EN VEZ DE ELEGIR, Y POR QUÉ NO SE "SIMPLIFICA" A ELEGIR MEJOR.
     *
     * Con una cabecera "PRECIOS" fusionada sobre E1:F1, la propagación de la fusión deja el
     * MISMO nombre en las columnas E y F. Claude devuelve dos ítems con
     * excel_column: "PRECIOS" —uno costo, otro precio— y enrich_column_mapping() les reparte
     * los índices EN EL ORDEN EN QUE VINIERON: el primero se lleva E, el segundo F. Nada
     * garantiza ese orden, y no hay dato en el archivo que lo decida:
     *
     *     Claude devuelve costo y después precio  ->  costo=E  precio=F   correcto
     *     Claude devuelve precio y después costo  ->  precio=E costo=F    INVERTIDOS
     *
     * El segundo caso importa los costos como precios y los precios como costos en TODO el
     * catálogo de un cliente, sin un solo error en pantalla. Es peor que el bug que la lista
     * de índices vino a matar (los dos ítems leyendo E), porque aquel al menos dejaba
     * precio == costo, que se ve de una. Y con tres ítems para dos columnas, dos propiedades
     * terminan leyendo la misma celda igual que antes.
     *
     * Adivinar mejor no es una opción: la información no está en el archivo. El usuario SÍ la
     * tiene —abre el Excel y ve cuál columna es cuál—, así que el aviso viaja al paso 2 y él
     * decide antes de que se toque la base. Es el principio que ordena toda la misión: la
     * importación no degrada en silencio; si algo no se pudo interpretar, se dice ANTES.
     *
     * Sólo se avisa cuando el nombre repetido llegó al mapeo. Un nombre repetido en columnas
     * que nadie mapeó no le cambia nada al usuario y sería una alerta amarilla más para
     * ignorar (ver B5: a la tercera alerta sin motivo, dejan de leerse todas).
     *
     * @param  array $column_mapping  Mapeo YA enriquecido (con excel_column_index)
     * @param  array $headers         Encabezados de la fila de encabezado, fusiones propagadas
     * @return array  [['nombre','columnas','letras','asignaciones','mensaje'], ...]
     */
    protected function detectar_columnas_ambiguas(array $column_mapping, array $headers)
    {
        $por_nombre = [];

        foreach ($headers as $header_index => $header_text) {
            $clave = $this->normalize_header_key($header_text);

            if ($clave === '') {
                continue;
            }

            if (!isset($por_nombre[$clave])) {
                $por_nombre[$clave] = ['nombre' => trim((string) $header_text), 'columnas' => []];
            }

            $por_nombre[$clave]['columnas'][] = (int) $header_index;
        }

        $avisos = [];

        foreach ($por_nombre as $clave => $datos) {
            if (count($datos['columnas']) < 2) {
                continue;
            }

            $asignaciones = [];

            foreach ($column_mapping as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ($this->normalize_header_key(isset($item['excel_column']) ? $item['excel_column'] : '') !== $clave) {
                    continue;
                }

                $indice = isset($item['excel_column_index']) ? (int) $item['excel_column_index'] : null;

                $asignaciones[] = [
                    'system_property'     => isset($item['system_property']) ? $item['system_property'] : null,
                    'excel_column_index'  => $indice,
                    'excel_column_letter' => is_null($indice) ? '' : $this->number_to_excel_column($indice + 1),
                ];
            }

            if (count($asignaciones) === 0) {
                continue;
            }

            $letras = [];

            foreach ($datos['columnas'] as $indice_columna) {
                $letras[] = $this->number_to_excel_column($indice_columna + 1);
            }

            $avisos[] = [
                'nombre'       => $datos['nombre'],
                'columnas'     => $datos['columnas'],
                'letras'       => $letras,
                'asignaciones' => $asignaciones,
                'mensaje'      => $this->mensaje_de_columna_ambigua($datos['nombre'], $letras, $asignaciones),
            ];
        }

        return $avisos;
    }

    /**
     * Texto del aviso, armado acá y no en la SPA: el backend es el único que sabe a qué
     * columna fue a parar cada propiedad, y el modal de importación es compartido por
     * artículos, clientes y proveedores.
     *
     * @param  string $nombre        Nombre del encabezado tal cual está en el Excel
     * @param  array  $letras        Letras de las columnas que ese nombre cubre
     * @param  array  $asignaciones  Qué propiedad se llevó cada columna, en el orden repartido
     * @return string
     */
    protected function mensaje_de_columna_ambigua($nombre, array $letras, array $asignaciones)
    {
        $repartidas = [];
        $usadas     = [];
        $repetidas  = [];

        foreach ($asignaciones as $asignacion) {
            $propiedad = $asignacion['system_property'];

            if (is_null($propiedad) || trim((string) $propiedad) === '') {
                $propiedad = 'sin asignar';
            }

            $repartidas[] = (string) $propiedad . ' → ' . $asignacion['excel_column_letter'];

            $letra = $asignacion['excel_column_letter'];

            if (isset($usadas[$letra])) {
                $repetidas[$letra] = true;
            }

            $usadas[$letra] = true;
        }

        $mensaje = '«' . $nombre . '» cubre las columnas ' . $this->enumerar($letras)
            . ', y el sistema no tiene forma de saber cuál es cuál.'
            . ' Quedó ' . $this->enumerar($repartidas) . '.';

        $sin_usar = [];

        foreach ($letras as $letra) {
            if (!isset($usadas[$letra])) {
                $sin_usar[] = $letra;
            }
        }

        if (count($sin_usar) === 1) {
            $mensaje .= ' La columna ' . $sin_usar[0] . ' no quedó mapeada a ninguna propiedad.';
        } elseif (count($sin_usar) > 1) {
            $mensaje .= ' Las columnas ' . $this->enumerar($sin_usar) . ' no quedaron mapeadas a ninguna propiedad.';
        }

        if (count($repetidas) > 0) {
            $mensaje .= ' Y hay más de una propiedad leyendo la columna '
                . $this->enumerar(array_keys($repetidas)) . '.';
        }

        return $mensaje . ' Revisalo en el Excel antes de importar.';
    }

    /**
     * "E y F", "E, F y G", "E".
     *
     * @param  array $partes
     * @return string
     */
    protected function enumerar(array $partes)
    {
        $partes = array_values($partes);

        if (count($partes) === 0) {
            return '';
        }

        if (count($partes) === 1) {
            return (string) $partes[0];
        }

        $ultima = array_pop($partes);

        return implode(', ', $partes) . ' y ' . $ultima;
    }

    /**
     * Nombre de la hoja $indice dentro del listado que devolvió listar_hojas().
     *
     * @param  array $hojas
     * @param  int   $indice
     * @return string
     */
    protected function nombre_de_la_hoja(array $hojas, $indice)
    {
        foreach ($hojas as $hoja) {
            if ((int) $hoja['indice'] === (int) $indice) {
                return (string) $hoja['nombre'];
            }
        }

        return '';
    }

    /**
     * Decide con qué fila de encabezado se va a trabajar y devuelve todo lo que hace falta
     * saber sobre ella.
     *
     * @param  string   $excel_path
     * @param  int      $indice_hoja
     * @param  int|null $fila_pedida  Fila 1-based corregida a mano por el usuario, o null
     * @return array    ['fila','origen','motivo','confianza','columnas','columnas_sin_nombre','fusiones_aplicadas']
     */
    protected function resolver_encabezado($excel_path, $indice_hoja, $fila_pedida)
    {
        $deteccion = ExcelHeaderDetector::detectar_en($excel_path, $indice_hoja);

        $deteccion['origen'] = 'automatico';

        $fila_pedida = (is_null($fila_pedida) || !is_numeric($fila_pedida)) ? null : (int) $fila_pedida;

        if (is_null($fila_pedida) || $fila_pedida < 1 || $fila_pedida === $deteccion['fila']) {
            return $deteccion;
        }

        /*
         * El usuario corrigió la fila a mano en el paso 1 del modal: manda él, sin discutir.
         * La detección automática igual se corrió, porque de ahí sale 'fusiones_aplicadas'
         * (cuántas celdas del encabezado se llenaron propagando una fusión), que es una
         * propiedad del archivo y no de la fila elegida.
         */
        $elegido = $this->encabezado_en_la_fila($excel_path, $indice_hoja, $fila_pedida);

        return [
            'fila'                => $fila_pedida,
            'origen'              => 'usuario',
            'motivo'              => 'elegido_por_el_usuario',
            'confianza'           => 'alta',
            'columnas'            => $elegido['columnas'],
            'columnas_sin_nombre' => $elegido['columnas_sin_nombre'],
            'fusiones_aplicadas'  => $deteccion['fusiones_aplicadas'],
        ];
    }

    /**
     * Columnas de la fila que el usuario eligió a mano en el paso 1, y cuáles quedaron sin
     * nombre, CON EL MISMO CRITERIO que ExcelHeaderDetector aplica sobre la fila que elige él.
     *
     * 🔴 POR QUÉ SE RECORTA LA VENTANA EN VEZ DE CALCULAR EL ANCHO ACÁ.
     * Este método tenía su propia copia del cálculo —"la última columna con contenido en
     * cualquier fila de la ventana"— y esa copia se quedó vieja en cuanto el detector
     * recalibró el suyo: una nota suelta a la derecha ("Promo hasta fin de mes" en J3) volvía
     * a inflar columnas_sin_nombre y disparaba la alerta amarilla sobre un archivo perfecto,
     * pero SÓLO por este camino, el de la fila corregida a mano. El mismo invariante decidido
     * en dos lugares con dos criterios: la clase de error de APRENDER_NO_PARCHEAR.md que ya
     * mordió tres veces en esta misión.
     *
     * Se le pasa al detector la ventana recortada desde la fila pedida hacia abajo, así la
     * fila elegida queda arriba de todo y él la toma: si califica como encabezado gana por
     * ser la única candidata antes del corte, y si no califica cae igual en ella porque es la
     * primera fila con contenido. Las de abajo quedan como filas de datos, que es lo que
     * necesita su criterio de "columna que los datos usan de verdad". Si por lo que sea
     * devolviera otra fila, no se inventa nada: se degrada a la fila cruda.
     *
     * @param  string $excel_path
     * @param  int    $indice_hoja
     * @param  int    $fila         1-based
     * @return array  ['columnas' => [string...], 'columnas_sin_nombre' => [int...]]
     */
    protected function encabezado_en_la_fila($excel_path, $indice_hoja, $fila)
    {
        $ventana = ExcelHeaderDetector::leer_ventana($excel_path, $indice_hoja);

        $fila = (int) $fila;

        /* Fuera de la ventana de 20 filas: analyze() se queda con los headers crudos. */
        if (!isset($ventana[$fila])) {
            return ['columnas' => [], 'columnas_sin_nombre' => []];
        }

        $recortada = [];

        foreach ($ventana as $numero_fila => $valores) {
            if ((int) $numero_fila >= $fila) {
                $recortada[(int) $numero_fila] = $valores;
            }
        }

        $resultado = ExcelHeaderDetector::detectar($recortada);

        if ((int) $resultado['fila'] !== $fila) {
            return ['columnas' => [], 'columnas_sin_nombre' => []];
        }

        return [
            'columnas'            => $resultado['columnas'],
            'columnas_sin_nombre' => $resultado['columnas_sin_nombre'],
        ];
    }

    /**
     * Una fila es "vacía" cuando ninguna de sus celdas tiene contenido.
     *
     * Hace falta porque los lectores nuevos leen con preservar_filas_vacias = true (para que
     * el número de fila sea el físico del Excel, el mismo que ve el usuario) y entonces las
     * filas vacías del medio y del final llegan igual: si no se descartan acá, se cuentan
     * como filas de datos y el row_count de la pantalla infla.
     *
     * @param  array $celdas
     * @return bool
     */
    protected function fila_esta_vacia(array $celdas)
    {
        foreach ($celdas as $celda) {
            if (trim((string) $celda) !== '') {
                return false;
            }
        }

        return true;
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
     * @param  string   $excel_path       Ruta absoluta al Excel ya guardado en storage
     * @param  array    $column_mapping   Mapeo de columnas enriquecido (con excel_column_index)
     * @param  int      $indice_hoja      Hoja 0-based elegida por el usuario
     * @param  int|null $fila_encabezado  Fila 1-based del encabezado; null o 1 = rama de siempre
     * @return array  ['placeholders' => [...], 'cadena_identificacion' => [...], 'nombres_duplicados' => [...]]
     */
    protected function analyze_identification_chain(string $excel_path, array $column_mapping, $indice_hoja = 0, $fila_encabezado = null): array
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

        /*
         * 🔴 INTERRUPTOR DE SEGURIDAD (§1.3 del plan). Con $fila_encabezado en null o en 1
         * se toma la rama de HOY, byte por byte: preservar_filas_vacias = false, saltear la
         * primera fila del iterador y numerar $data_row_index + 1. La rama nueva —números de
         * fila FÍSICOS del Excel— sólo se enciende con $fila_encabezado > 1, o sea sólo en
         * los archivos que hoy ya se leen mal (título y razón social contados como datos).
         *
         * NO "simplifiques" esto dejando una sola rama. La rama vieja no está de adorno: es
         * lo que acota TODO el riesgo de esta misión a los archivos rotos y lo que hace que
         * los tests de no regresión pasen por construcción. Los dos criterios de
         * preservar_filas_vacias dan números distintos en cuanto hay una fila vacía en el
         * medio, y esos números son los que se le muestran al usuario al lado de cada
         * duplicado.
         */
        $usar_numeracion_fisica = (!is_null($fila_encabezado) && (int) $fila_encabezado > 1);
        $fila_encabezado        = is_null($fila_encabezado) ? 1 : (int) $fila_encabezado;

        try {
            /* Mismo lector XLSX de OpenSpout que el resto del análisis, leyendo el archivo completo. */
            $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, $usar_numeracion_fisica);

            $header_skipped = false;
            /* Número de fila física dentro de la hoja (sólo tiene sentido en la rama nueva). */
            $numero_fila = 0;

            foreach ($lectura->filas() as $row) {
                $numero_fila++;

                if ($usar_numeracion_fisica) {
                    /* Todo lo que está arriba del encabezado (título, razón social, vacías) no es dato. */
                    if ($numero_fila <= $fila_encabezado) {
                        continue;
                    }
                } else {
                    if (!$header_skipped) {
                        $header_skipped = true;
                        continue;
                    }
                }

                /* Extraemos los valores de las celdas como strings simples. */
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = trim((string) ($value ?? ''));
                }

                if ($usar_numeracion_fisica && $this->fila_esta_vacia($cells)) {
                    continue;
                }

                $data_row_index++;
                /* Número de fila real en el Excel (1-based, incluye cabecera). */
                $excel_row_number = $usar_numeracion_fisica ? $numero_fila : ($data_row_index + 1);

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

            $lectura->cerrar();

        } catch (\Throwable $e) {
            /*
             * T9: este catch devuelve TODO en cero y la pantalla termina diciendo
             * "0 duplicados" en vez de "no se pudo leer". Ese comportamiento no se cambia
             * acá (está fuera del alcance), pero la hoja y la fila de encabezado SÍ van al
             * contexto del log: sin esos dos datos, el próximo que caiga en este error no
             * tiene con qué reproducirlo — el mismo archivo se lee bien o mal según qué
             * hoja y qué fila se le hayan pedido.
             */
            Log::error('AiExcelAnalyzer: error al analizar la cadena de identificación', [
                'message'         => $e->getMessage(),
                'file'            => $excel_path,
                'hoja'            => $indice_hoja,
                'fila_encabezado' => $fila_encabezado,
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
     * Fila 1-based que se toma como encabezado de la hoja.
     *
     * El nombre quedo historico: hoy NO devuelve "la primera fila no vacia" a secas, sino
     * lo que decide ExcelHeaderDetector con la regla de §1.3 del plan (la primera fila no
     * vacia sigue siendo la respuesta en el caso normal, y por eso los once fixtures viejos
     * siguen dando 1). Se conserva el nombre porque es el punto de entrada que ya usaban
     * read_sample_rows() y count_data_rows(), y renombrarlo no arregla nada.
     *
     * 🔴 La regla vive en ExcelHeaderDetector y esta espejada en JS en
     * `empresa-spa/src/components/listado/modals/ai-excel-import/Index.vue`
     * (`detect_header_row()`). Si cambia una, cambia la otra: el navegador calcula start_row
     * con su copia y el backend arma el mapeo con esta.
     *
     * @param  string $excel_path   Ruta al archivo Excel
     * @param  int    $indice_hoja  Hoja 0-based elegida por el usuario
     * @return int                  Numero de fila (1-based) del encabezado
     */
    protected function find_first_non_empty_row(string $excel_path, $indice_hoja = 0): int
    {
        $deteccion = ExcelHeaderDetector::detectar_en($excel_path, $indice_hoja);

        return (int) $deteccion['fila'];
    }

    /**
     * Lee las primeras N filas del Excel y retorna un array con headers y muestra.
     *
     * Detecta la primera fila no vacía del archivo (soporta filas vacías al inicio)
     * y la trata como cabecera; las siguientes filas son datos de muestra.
     *
     * @param  string   $excel_path       Ruta al archivo Excel
     * @param  int      $indice_hoja      Hoja 0-based elegida por el usuario
     * @param  int|null $fila_encabezado  Fila 1-based del encabezado; null = se detecta sola
     * @return array               ['headers' => [...], 'rows' => [[...], ...]]
     *
     * @throws \RuntimeException  Si el archivo no puede abrirse con OpenSpout
     */
    protected function read_sample_rows(string $excel_path, $indice_hoja = 0, $fila_encabezado = null): array
    {
        $headers = [];
        $rows = [];

        /*
         * Detectar en qué fila empieza el contenido real del Excel
         * (puede haber filas vacías, un título y una razón social al inicio del archivo).
         */
        $header_row_number = (is_null($fila_encabezado) || (int) $fila_encabezado < 1)
            ? $this->find_first_non_empty_row($excel_path, $indice_hoja)
            : (int) $fila_encabezado;

        /*
         * Se lee con preservar_filas_vacias = true a propósito: así el contador de filas es
         * el número FÍSICO del Excel y coincide con el que devolvió el detector de
         * encabezado. Si acá se saltearan las vacías, la fila 4 del Excel sería la 3 del
         * contador y el encabezado se buscaría en la fila equivocada.
         */
        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);

        $row_number = 0;
        $header_found = false;

        foreach ($lectura->filas() as $row) {
            $row_number++;

            /* Saltear todo lo que esté arriba de la cabecera detectada. */
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
                /* Fila de encabezado. */
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

        $lectura->cerrar();

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
         *
         * La instrucción 1bis ("una entrada por columna, en orden de columna") existe para
         * que el reparto de índices de enrich_column_mapping() tenga la mayor chance posible
         * de acertar cuando dos columnas comparten encabezado. 🔴 NO ES UNA GARANTÍA Y NO SE
         * DEPENDE DE ELLA: Claude puede no obedecer, y si no obedece el error es costo y
         * precio invertidos en todo el catálogo, sin ruido. La red que sí atrapa ese caso es
         * el aviso de detectar_columnas_ambiguas(), que existe aunque esta instrucción esté.
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
1bis. Devolvé UNA entrada por columna del Excel, en el MISMO orden en que aparecen las columnas, de izquierda a derecha, incluidas las que no correspondan a ninguna propiedad. Si dos columnas comparten el mismo encabezado (pasa cuando la cabecera está fusionada sobre varias columnas), devolvé una entrada por cada una, igual en orden de izquierda a derecha: la primera entrada con ese nombre se asigna a la columna de más a la izquierda.
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
            /*
             * El nombre de la variable de entorno NO va al mensaje del usuario: contarle a un
             * tercero cómo está configurado el servidor es información nuestra, no suya. Va al log.
             */
            Log::error('AiExcelAnalyzer: falta la clave de API de Anthropic en la configuración del servidor');

            throw new \RuntimeException(self::MENSAJE_IA_SIN_CONFIGURAR);
        }

        Log::info('AiExcelAnalyzer: llamando a Claude API', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        /*
         * Cliente HTTP con la misma configuración TLS que admin-api (ANTHROPIC_CAINFO / ANTHROPIC_VERIFY_SSL).
         */
        $http = $this->build_anthropic_http_client($api_key);

        /*
         * El timeout de 60 segundos y el corte de conexión llegan como ConnectionException, que
         * extiende \Exception y NO \RuntimeException (verificado). Sin este catch caían en el
         * \Throwable genérico del job y el usuario leía "ocurrió un error inesperado" para algo
         * que casi siempre se arregla reintentando: un archivo grande a veces necesita un
         * segundo intento.
         */
        try {
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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('AiExcelAnalyzer: no hubo respuesta de Claude API', [
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_SIN_RESPUESTA);
        }

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
                throw new \RuntimeException(self::MENSAJE_IA_NO_DISPONIBLE);
            }

            /*
             * Otros errores (auth, rate limit, request inválido). Hasta esta misión acá se
             * concatenaba $response->body(): el JSON de error crudo de Anthropic terminaba en
             * la pantalla del comerciante. El status y el body están completos en el
             * Log::error de arriba.
             */
            throw new \RuntimeException(self::MENSAJE_IA_RECHAZO);
        }

        $response_data = $response->json();

        /*
         * La respuesta de la API de Anthropic tiene el contenido en:
         * response.content[0].text
         */
        $text = $response_data['content'][0]['text'] ?? null;

        if (is_null($text)) {
            Log::error('AiExcelAnalyzer: Claude devolvió una respuesta sin contenido de texto', [
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
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

            /* El JSON crudo y el error de parseo ya quedaron en el Log::error de arriba. */
            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
        }

        /* Validamos que tenga la estructura esperada con column_mapping. */
        if (!isset($parsed['column_mapping']) || !is_array($parsed['column_mapping'])) {
            Log::error('AiExcelAnalyzer: la respuesta de Claude no trae column_mapping', [
                'raw_response' => $claude_text,
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
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
        /*
         * 🔴 T3 — LISTA DE ÍNDICES POR NOMBRE, NO EL PRIMERO. ES EL RIESGO MÁS CARO DE ESTA
         * MISIÓN Y ACÁ ES DONDE SE EVITA.
         *
         * Hasta acá esto era `if (!isset($header_index_by_name[$key])) { ...= $header_index; }`:
         * se quedaba con el PRIMER índice de cada nombre y descartaba los demás. Con una
         * cabecera "PRECIOS" fusionada sobre E1:F1, la propagación de la fusión deja el mismo
         * nombre en las columnas 4 y 5; si Claude devuelve dos ítems con
         * excel_column: "PRECIOS" (uno para costo y otro para precio), los DOS se llevaban el
         * índice 4. Resultado: costo y precio del catálogo de un cliente importados desde la
         * MISMA celda del Excel, sin un solo error en pantalla que lo denuncie.
         *
         * Por eso el mapa es una lista y se consume en orden: el primer ítem que reclama
         * "PRECIOS" se lleva el 4, el segundo el 5. De paso arregla un bug latente que ya
         * existía sin fusiones de por medio, en cualquier planilla que repita un nombre de
         * columna.
         *
         * Si alguna vez te tienta "simplificar" esto de vuelta a un índice por nombre:
         * el caso que se rompe es costo y precio leyendo la misma columna, y no hace ruido.
         *
         * 🔴 Y REPARTIR EN ORDEN NO ALCANZA, POR ESO ADEMÁS SE AVISA.
         * El orden que se reparte acá es el orden en que Claude devolvió los ítems, y nada lo
         * garantiza: si devuelve precio primero, precio se lleva E y costo F, o sea el
         * catálogo entero del cliente con costo y precio invertidos y ni un error en
         * pantalla. La información para desambiguar no está en el archivo. Por eso
         * detectar_columnas_ambiguas() —abajo, con el detalle completo— manda el caso al paso
         * 2 como aviso para que lo mire el usuario, que sí puede saberlo. Esta lista de
         * índices es la mitad mecánica; el aviso es la que hace que no se degrade en silencio.
         */
        $header_indexes_by_name = [];
        foreach ($headers as $header_index => $header_text) {
            $normalized_key = $this->normalize_header_key($header_text);
            if ($normalized_key === '') {
                continue;
            }
            if (!isset($header_indexes_by_name[$normalized_key])) {
                $header_indexes_by_name[$normalized_key] = [];
            }
            $header_indexes_by_name[$normalized_key][] = $header_index;
        }

        /* Cuántos índices de cada nombre ya se repartieron: nombre normalizado => cantidad. */
        $header_indexes_consumed = [];

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
            if ($normalized_excel_name !== '' && isset($header_indexes_by_name[$normalized_excel_name])) {
                $indices_del_nombre = $header_indexes_by_name[$normalized_excel_name];

                $ya_repartidos = isset($header_indexes_consumed[$normalized_excel_name])
                    ? $header_indexes_consumed[$normalized_excel_name]
                    : 0;

                if ($ya_repartidos < count($indices_del_nombre)) {
                    $excel_column_index = $indices_del_nombre[$ya_repartidos];
                    $header_indexes_consumed[$normalized_excel_name] = $ya_repartidos + 1;
                } else {
                    /*
                     * Claude devolvió más ítems con ese nombre que columnas con ese nombre
                     * hay en el archivo (se lo inventó). No hay índice libre que darle: se
                     * repite el último, que es el comportamiento de siempre para un ítem de
                     * más. Los primeros N, que son los que corresponden a columnas reales,
                     * ya se fueron con índices distintos.
                     */
                    $excel_column_index = $indices_del_nombre[count($indices_del_nombre) - 1];
                }
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
     * Detecta la fila de cabecera y cuenta solo las filas posteriores que tengan algo.
     * Realiza una pasada completa sobre la hoja elegida para obtener el conteo real.
     *
     * 🔴 T1 — ACÁ HABÍA UN BUG QUE YA EXISTÍA ANTES DE ESTA MISIÓN, Y ASÍ SE ARREGLÓ.
     * find_first_non_empty_row() lee con preservar_filas_vacias = TRUE y devuelve un número
     * de fila FÍSICO; este conteo leía con preservar_filas_vacias = FALSE y comparaba ese
     * número físico contra un contador de filas NO VACÍAS. Con dos filas vacías arriba del
     * encabezado ya se perdían dos filas de datos del total que se le muestra al usuario, y
     * con el encabezado en la fila 4 se perderían tres. Los dos criterios están unificados
     * en el físico: se lee con preservar_filas_vacias = true (mismo número de fila que el
     * detector) y las filas vacías se descartan una por una acá adentro, que es lo que hacía
     * antes el flag. NO vuelvas a poner false: el número vuelve a corresponder a otra cosa.
     *
     * @param  string   $excel_path       Ruta absoluta al archivo Excel
     * @param  int      $indice_hoja      Hoja 0-based elegida por el usuario
     * @param  int|null $fila_encabezado  Fila 1-based del encabezado; null = se detecta sola
     * @return int                 Cantidad de filas de datos (0 si el archivo está vacío o solo tiene cabecera)
     */
    protected function count_data_rows(string $excel_path, $indice_hoja = 0, $fila_encabezado = null): int
    {
        /*
         * Detectar dónde empieza el contenido real (filas vacías, título y razón social
         * arriba de la tabla).
         */
        $header_row_number = (is_null($fila_encabezado) || (int) $fila_encabezado < 1)
            ? $this->find_first_non_empty_row($excel_path, $indice_hoja)
            : (int) $fila_encabezado;

        /* Contador de filas de datos (sin contar la fila de cabecera). */
        $data_row_count = 0;

        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);

        $row_number = 0;

        foreach ($lectura->filas() as $row) {
            $row_number++;

            /* Saltear todo lo que esté arriba del encabezado, y el encabezado mismo. */
            if ($row_number <= $header_row_number) {
                continue;
            }

            $cells = [];
            foreach ($row->getCells() as $cell) {
                $value = $cell->getValue();

                if ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d');
                }

                $cells[] = (string) ($value ?? '');
            }

            /* Las filas completamente vacías que algunos Excel dejan al final no son datos. */
            if ($this->fila_esta_vacia($cells)) {
                continue;
            }

            $data_row_count++;
        }

        $lectura->cerrar();

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
     * Arma, para hasta 3 códigos de proveedor repetidos dentro del archivo, los nombres (hasta 4
     * por código) que trae el Excel en esas filas. Es la evidencia que le permite a Claude
     * distinguir si los repetidos son el mismo producto cargado más de una vez (nombres parecidos)
     * o productos distintos que comparten código de proveedor (nombres claramente distintos) —
     * grupo 284, prompt 03.
     *
     * Público porque lo arma el controller antes de llamar a ask_claude_for_recomendation(): en ese
     * punto tiene el $excel_path y el $column_mapping confirmado que el analyzer no conserva.
     *
     * @param  string $excel_path                        Ruta absoluta al Excel ya guardado en storage
     * @param  array  $detalle_provider_codes_duplicados  ExcelDuplicateStats::analyze()['detalle_provider_codes_duplicados']
     * @param  array  $column_mapping                     Mapeo enriquecido de columnas (para ubicar la columna nombre)
     * @param  int    $indice_hoja                        Hoja 0-based elegida por el usuario
     * @param  int|null $fila_encabezado                  Fila 1-based del encabezado; null o 1 = numeración de siempre
     * @return array  [['codigo' => string, 'nombres' => string[]], ...] — vacío si no hay columna
     *                 nombre mapeada, no hay duplicados, o falla la lectura del archivo
     */
    public function build_duplicados_con_nombres(string $excel_path, array $detalle_provider_codes_duplicados, array $column_mapping, $indice_hoja = 0, $fila_encabezado = null): array
    {
        if (empty($detalle_provider_codes_duplicados)) {
            return [];
        }

        $nombre_column_index = null;
        foreach ($column_mapping as $col) {
            if (($col['system_property'] ?? null) === 'nombre') {
                $nombre_column_index = $col['excel_column_index'] ?? null;
                break;
            }
        }

        if (is_null($nombre_column_index)) {
            return [];
        }

        /* Hasta 3 códigos, y para cada uno hasta 4 de las filas que ya trae el detalle. */
        $codigos_a_buscar = array_slice($detalle_provider_codes_duplicados, 0, 3);

        /* Mapa fila (1-based, incluye cabecera) -> código: resuelve todos los nombres en una sola
         * pasada del archivo en vez de reabrirlo por cada código. */
        $fila_a_codigo     = [];
        $nombres_por_codigo = [];
        foreach ($codigos_a_buscar as $entry) {
            $codigo = (string) ($entry['codigo'] ?? '');
            $nombres_por_codigo[$codigo] = [];
            foreach (array_slice($entry['filas'] ?? [], 0, 4) as $fila) {
                $fila_a_codigo[$fila] = $codigo;
            }
        }

        if (empty($fila_a_codigo)) {
            return [];
        }

        /*
         * Mismo interruptor de seguridad que el resto: los números de fila del mapa de arriba
         * los produjo ExcelDuplicateStats, así que acá hay que contar con EL MISMO criterio o
         * los nombres salen de las filas equivocadas. Con fila_encabezado en null o 1 se
         * cuenta como siempre (sin filas vacías); con el encabezado corrido, filas físicas.
         */
        $usar_numeracion_fisica = (!is_null($fila_encabezado) && (int) $fila_encabezado > 1);

        try {
            $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, $usar_numeracion_fisica);

            $row_number = 0;

            foreach ($lectura->filas() as $row) {
                $row_number++;

                if (!isset($fila_a_codigo[$row_number])) {
                    continue;
                }

                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();

                    if ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d');
                    }

                    $cells[] = trim((string) ($value ?? ''));
                }

                $nombre = $cells[$nombre_column_index] ?? '';
                if ($nombre !== '') {
                    $nombres_por_codigo[$fila_a_codigo[$row_number]][] = $nombre;
                }
            }

            $lectura->cerrar();
        } catch (\Throwable $e) {
            Log::warning('AiExcelAnalyzer: error al armar nombres de duplicados de provider_code', [
                'message'         => $e->getMessage(),
                'hoja'            => $indice_hoja,
                'fila_encabezado' => $fila_encabezado,
            ]);
            return [];
        }

        $resultado = [];
        foreach ($codigos_a_buscar as $entry) {
            $codigo = (string) ($entry['codigo'] ?? '');
            if (!empty($nombres_por_codigo[$codigo])) {
                $resultado[] = [
                    'codigo'  => $codigo,
                    'nombres' => $nombres_por_codigo[$codigo],
                ];
            }
        }

        return $resultado;
    }

    /**
     * Pide a Claude una recomendación de configuración basada en las estadísticas de duplicados.
     *
     * Arma un prompt con los conteos del array $stats y las columnas disponibles del Excel,
     * llama a Claude y parsea el JSON devuelto.
     * Si la llamada falla, el parseo lanza error o el JSON no es válido, aplica un fallback
     * heurístico respetando las columnas disponibles.
     *
     * La identificación de artículos usa una jerarquía FIJA (numero -> bar_code -> sku ->
     * provider_code -> name, ver ArticleIndexCache::find_with_index()): no hay ninguna "clave de
     * identidad" que recomendar, así que esta función ya no la devuelve (grupo 284, prompt 03).
     *
     * @param  array $stats                    Resultado de ExcelDuplicateStats::analyze()
     * @param  array $column_mapping           Mapeo enriquecido de columnas (para derivar columnas disponibles)
     * @param  array $formatos_numericos       Resultado de ExcelNumericFormatStats::analyze() (grupo 239, prompt 02);
     *                                         default [] para no romper llamadores existentes que no lo pasen
     * @param  array $duplicados_con_nombres   Resultado de build_duplicados_con_nombres() (grupo 284, prompt 03);
     *                                         default [] para no romper llamadores existentes que no lo pasen
     * @return array                 ['politica_colision' => string, 'politica_intra_archivo' => string, 'explicacion' => string]
     */
    public function ask_claude_for_recomendation(array $stats, array $column_mapping = [], array $formatos_numericos = [], array $duplicados_con_nombres = []): array
    {
        /*
         * Valores aceptados para cada campo de la recomendación.
         * Se usan para validar la respuesta de Claude antes de retornarla.
         */
        $valid_politicas        = ['actualizar_todos', 'saltear_y_reportar', 'crear_nuevo'];
        $valid_politicas_intra  = ['ultima_gana', 'productos_distintos'];

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
         * Sección opcional de nombres de filas con código de proveedor repetido (grupo 284,
         * prompt 03): solo se agrega si build_duplicados_con_nombres() encontró algo. Es la
         * evidencia que le permite a Claude distinguir "mismo producto cargado dos veces" de
         * "productos distintos que comparten código" para politica_intra_archivo.
         */
        $nombres_repetidos_section = '';
        if (!empty($duplicados_con_nombres)) {
            $nombres_repetidos_lines = '';
            foreach ($duplicados_con_nombres as $entry) {
                $nombres_line = implode(' | ', $entry['nombres']);
                $nombres_repetidos_lines .= "- Código \"{$entry['codigo']}\": {$nombres_line}\n";
            }

            $nombres_repetidos_section = <<<NOMBRES

Nombres de las filas con código de proveedor repetido (para decidir politica_intra_archivo):
{$nombres_repetidos_lines}
NOMBRES;
        }

        /*
         * Arma el prompt con los conteos del preanálisis e informa a Claude
         * qué columnas existen realmente en este Excel, para que la explicación final sea
         * coherente con los datos reales del archivo.
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

Cómo identifica el sistema un artículo como "el mismo" (esto NO es una decisión tuya, es fijo): el
sistema usa siempre esta jerarquía, en este orden: número interno (ID) -> código de barras -> SKU ->
código de proveedor -> nombre. Para cada fila del Excel se usa el primer campo de esa lista que la
fila tenga con valor; si ese campo no encuentra ningún artículo existente, se sigue bajando al
siguiente. Usá esto solo como contexto para que tu explicación sea coherente con lo que el sistema
realmente hace — no hay ninguna clave que recomendar.

Decisión 1 - politica_colision: qué hacer cuando un código de proveedor coincide con más de un
artículo ya existente en el sistema. Esto solo puede pasar en el último escalón de la jerarquía
(código de proveedor): número, código de barras, SKU y nombre son siempre únicos en el sistema, así
que nunca tienen este problema.
- "actualizar_todos": cada fila del Excel actualiza TODOS los artículos que tengan ese código de proveedor. Es lo que quiere una distribuidora que usa el mismo código en varios artículos físicos y actualiza el costo de todos con una fila. También es la opción correcta cuando todavía no hay nada existente contra qué coincidir.
- "saltear_y_reportar": si un código coincide con más de un artículo, esa fila NO se crea ni se actualiza y queda reportada como problema para resolver a mano. Es la opción conservadora: no toca nada de lo que no está seguro.
- "crear_nuevo": no se identifica por código de proveedor. Las filas que solo tienen ese código crean artículos nuevos aunque el código ya exista. Sirve cuando el código de proveedor del catálogo no es confiable. NUNCA recomiendes esta opción vos: está reservada para casos manuales.

REGLAS CRÍTICAS para politica_colision (aplicar en orden):
1. Si provider_codes_existentes_mismo_proveedor = 0 (primera importación contra ese proveedor): recomendá "actualizar_todos". No hay nada existente contra qué coincidir, así que da igual cuál elijas, pero esta deja el sistema listo para que la PRÓXIMA importación actualice en vez de duplicar. Decilo en la explicación.
2. Si provider_codes_existentes_mismo_proveedor > 0 y provider_codes_duplicados_intra_archivo > 0: recomendá "actualizar_todos".
3. Si provider_codes_existentes_mismo_proveedor > 0 y provider_codes_duplicados_intra_archivo = 0: recomendá "actualizar_todos".

Decisión 2 - politica_intra_archivo: qué hacer cuando el MISMO código de proveedor aparece más de
una vez DENTRO del propio Excel (no contra la base: repetido en el propio archivo).
- "ultima_gana": las filas repetidas son el mismo producto cargado más de una vez; el sistema se queda con los datos de la última fila del Excel que traiga ese código.
- "productos_distintos": las filas repetidas son productos distintos que comparten el mismo código de proveedor (típico de ferretería: un código para varias medidas o variantes); el sistema no las mezcla, las trata como artículos separados.

Criterio para politica_intra_archivo (aplicar en orden):
1. Si provider_codes_duplicados_intra_archivo = 0: recomendá "ultima_gana". No hay filas repetidas sobre las que aplicar, es inocuo.
2. Si hay repetidos y más abajo tenés la sección "Nombres de las filas con código de proveedor repetido": comparé los nombres de cada código. Nombres parecidos entre sí (mismo producto escrito de forma similar) -> "ultima_gana". Nombres claramente distintos entre sí -> "productos_distintos".
3. Si hay repetidos pero no tenés los nombres para comparar: recomendá "ultima_gana" (comportamiento histórico; es el único que no puede crear duplicados por su cuenta).
{$nombres_repetidos_section}
Para el campo "explicacion":
- Describí qué va a pasar en términos concretos y simples.
- Si provider_codes_existentes_mismo_proveedor = 0 explicá que se van a crear los artículos (primera importación).
- Si provider_codes_existentes_mismo_proveedor > 0 explicá que se van a actualizar artículos existentes.
- Si provider_codes_existentes_otros_proveedores > 0, agregá una advertencia breve de que hay códigos que también existen en artículos de otros proveedores, y que el sistema NO los va a tocar a menos que el usuario lo habilite manualmente.
- Si bar_codes_duplicados_intra_archivo > 0, mencioná explícitamente que se detectaron códigos de barras repetidos en el archivo y que el sistema los procesará correctamente: importará un único artículo por código, quedando con la información de la última fila del Excel que lo contenga.
- Si recomendaste "productos_distintos" para politica_intra_archivo, mencionalo brevemente: hay códigos de proveedor repetidos que parecen productos distintos y el sistema los va a mantener como artículos separados.
- NUNCA uses términos técnicos internos: nada de "provider_code", "bar_code", "actualizar_todos", "saltear_y_reportar", "crear_nuevo", "politica_colision", "politica_intra_archivo", "ultima_gana", "productos_distintos", "intra_archivo", ni ninguna clave del sistema.
- Hablá como si le explicaras a un comerciante qué va a pasar con sus artículos.
- Máximo 4 oraciones claras y directas.
{$numeric_format_section}
Respondé SOLO con un JSON válido, sin markdown ni texto adicional:
{
  "politica_colision": "actualizar_todos" | "saltear_y_reportar" | "crear_nuevo",
  "politica_intra_archivo": "ultima_gana" | "productos_distintos",
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
            $politica_colision      = $decoded['politica_colision']      ?? null;
            $politica_intra_archivo = $decoded['politica_intra_archivo'] ?? null;
            $explicacion            = $decoded['explicacion']            ?? null;

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

            if (!in_array($politica_colision, $valid_politicas, true)) {
                throw new \RuntimeException(
                    "Valor fuera de rango: politica_colision={$politica_colision}"
                );
            }

            /*
             * politica_intra_archivo (grupo 284, prompt 03) es más tolerante que politica_colision
             * a propósito: una respuesta ausente o inválida no amerita tirar toda la recomendación
             * al fallback, simplemente cae al default histórico 'ultima_gana'.
             */
            if (!in_array($politica_intra_archivo, $valid_politicas_intra, true)) {
                $politica_intra_archivo = 'ultima_gana';
            }

            /*
             * Override determinístico de politica_colision.
             * Claude puede malinterpretar el valor numérico de provider_codes_duplicados_intra_archivo.
             * La regla es simple y no requiere juicio subjetivo: si hay códigos de proveedor
             * repetidos en el Excel, la política debe ser actualizar_todos sin excepción. La
             * jerarquía de identificación es fija (grupo 284, prompt 03), así que este override ya
             * no depende de ninguna "clave" elegida.
             */
            if ($stats['provider_codes_duplicados_intra_archivo'] > 0) {
                $politica_colision = 'actualizar_todos';
            }

            Log::info('AiExcelAnalyzer: recomendación de configuración recibida', [
                'politica_colision'      => $politica_colision,
                'politica_intra_archivo' => $politica_intra_archivo,
            ]);

            return [
                'politica_colision'      => $politica_colision,
                'politica_intra_archivo' => $politica_intra_archivo,
                'explicacion'            => is_string($explicacion) ? trim($explicacion) : '',
            ];

        } catch (\Throwable $e) {
            Log::warning('AiExcelAnalyzer: fallo en recomendación de Claude, aplicando fallback', [
                'error' => $e->getMessage(),
            ]);

            /*
             * Fallback heurístico para politica_colision (grupo 284, prompts 02 y 03):
             * - sin nada existente en la base todavía: actualizar_todos (no hay contra qué
             *   coincidir, y deja el sistema listo para que la próxima importación actualice en
             *   vez de duplicar).
             * - con existentes en la base: actualizar_todos también, SALVO que el archivo tenga
             *   muchos códigos de proveedor repetidos respecto del total de filas (por encima de
             *   UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK), en cuyo caso es más seguro no
             *   arriesgarse y recomendar saltear_y_reportar.
             * Nunca se recomienda crear_nuevo en el fallback: es la única opción que puede
             * duplicar el catálogo.
             */
            if (
                $stats['provider_codes_existentes_mismo_proveedor'] > 0
                && $stats['total_filas_datos'] > 0
                && ($stats['provider_codes_duplicados_intra_archivo'] / $stats['total_filas_datos']) >= self::UMBRAL_PROPORCION_PROVIDER_CODES_REPETIDOS_FALLBACK
            ) {
                $politica_fallback = 'saltear_y_reportar';
            } else {
                $politica_fallback = 'actualizar_todos';
            }

            /*
             * politica_intra_archivo en el fallback (grupo 284, prompt 03): siempre 'ultima_gana'.
             * Es el comportamiento histórico y el único que no puede crear duplicados por su
             * cuenta — sin Claude no hay forma de comparar nombres para distinguir "mismo
             * producto" de "productos distintos que comparten código".
             */
            return [
                'politica_colision'      => $politica_fallback,
                'politica_intra_archivo' => 'ultima_gana',
                'explicacion'            => 'Recomendación generada automáticamente porque la IA no devolvió una respuesta válida.',
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
