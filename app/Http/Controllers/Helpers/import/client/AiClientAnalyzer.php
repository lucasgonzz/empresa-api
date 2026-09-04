<?php

namespace App\Http\Controllers\Helpers\import\client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;

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
        'sucursal',
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
     * @param  array  $opciones             ['hoja' => int|null, 'hoja_nombre' => string|null, 'fila_encabezado' => int|null]
     * @return array                        Array con claves: column_mapping, provider_id (null), provider_confidence, row_count
     *
     * @throws \RuntimeException  Si el archivo no puede leerse o Claude no devuelve JSON válido
     */
    public function analyze(string $excel_path, string $original_filename = '', array $opciones = []): array
    {
        /*
         * Paso 0: hoja elegida y fila de encabezado.
         *
         * 🔴 $opciones ENTERO ES OPCIONAL: AdminSync\AiExcelImportController llama a los
         * analyzers con dos argumentos y está fuera del alcance de esta misión. Un parámetro
         * obligatorio acá es un ArgumentCountError en el endpoint que usa admin-api contra
         * clientes reales.
         */
        $hoja_pedida = isset($opciones['hoja']) ? $opciones['hoja'] : null;
        $hoja_nombre = isset($opciones['hoja_nombre']) ? $opciones['hoja_nombre'] : null;
        $fila_pedida = isset($opciones['fila_encabezado']) ? $opciones['fila_encabezado'] : null;

        /*
         * 🔴 EL LIBRO SE LISTA UNA SOLA VEZ (B9). ExcelWorkbookReader::resolver_indice() lista
         * por adentro: llamarlo y además pedir listar_hojas() son dos Reader::open() completos
         * de OpenSpout, cada uno parseando sharedStrings.xml entero (195ms + 220ms medidos
         * sobre un xlsx de 20.000 filas, en TODO archivo, aunque tenga una sola hoja).
         *
         * El libro entero se ofrece siempre: la SPA arma el selector del paso 1 con esto, y el
         * modal de importación es el MISMO para artículos, clientes y proveedores.
         */
        $hojas = ExcelWorkbookReader::listar_hojas($excel_path);

        /* Nombre antes que índice: el índice puede venir de SheetJS y no coincidir (T11 del plan). */
        $indice_hoja = $this->resolver_indice_sobre($hojas, $hoja_pedida, $hoja_nombre);

        /*
         * Paso 0bis: fila de encabezado, con las fusiones ya propagadas.
         *
         * 🔴 ESTO NO ES DE ARTÍCULOS SOLAMENTE. La propagación de fusiones se había entregado
         * sólo en AiExcelAnalyzer, y este analizador le mandaba a Claude el nombre vacío de la
         * columna cubierta por la fusión ([..., "PRECIOS", ""]) y no devolvía ni un aviso: al
         * usuario de clientes se le mostraba la misma pantalla que al de artículos,
         * prometiéndole lo mismo y sin cumplirlo. Lo que vale para artículos vale para los
         * tres modelos.
         */
        $encabezado = $this->resolver_encabezado($excel_path, $indice_hoja, $fila_pedida);

        /*
         * Paso 1: Leer headers y filas de muestra del Excel.
         */
        $sample_data = $this->read_sample_rows($excel_path, $indice_hoja, $encabezado['fila']);

        /*
         * Los encabezados que alimentan el prompt y el mapeo salen del detector, NO del
         * read_sample_rows crudo: son los de la misma fila pero con las fusiones propagadas.
         * Excel no escribe la celda cubierta por una fusión, así que "PRECIOS" sobre E1:F1
         * deja la columna F sin ningún nombre en el XML y el mapeo pierde una columna entera.
         */
        if (!empty($encabezado['columnas'])) {
            $sample_data['headers'] = $encabezado['columnas'];
        }

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
        $parsed['row_count'] = $this->count_data_rows($excel_path, $indice_hoja, $encabezado['fila']);

        /*
         * 🔴 LOS NOMBRES DE ESTAS SEIS CLAVES SON CONTRATO CON LA SPA (§1.4 del plan) Y SON
         * LAS MISMAS QUE DEVUELVE EL ANALIZADOR DE ARTÍCULOS. El modal de importación es
         * compartido: si acá faltan, el usuario de clientes ve el selector de hoja y la alerta
         * amarilla vacíos sobre un archivo que sí tiene el problema, y falla EN SILENCIO — no
         * tira ningún error, sólo no avisa. Se agregan; ninguna clave existente cambia.
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

        /* Un mismo nombre de encabezado cubriendo dos columnas: ver detectar_columnas_ambiguas(). */
        $parsed['columnas_ambiguas'] = $this->detectar_columnas_ambiguas(
            $parsed['column_mapping'],
            $sample_data['headers']
        );

        return $parsed;
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
     * Índice 0-based de la hoja a leer, resuelto sobre un listado que YA se leyó.
     *
     * 🔴 Espejo de ExcelWorkbookReader::resolver_indice(), a propósito: la regla (nombre
     * exacto -> índice en rango -> 0) vive allá y allá manda, pero la versión de allá vuelve a
     * listar el libro y este análisis ya lo listó. AnalyzerHojaYEncabezadoTest compara las dos
     * respuestas caso por caso para que no se separen.
     *
     * @param  array       $hojas
     * @param  int|null    $hoja
     * @param  string|null $hoja_nombre
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
     * Decide con qué fila de encabezado se trabaja y devuelve todo lo que hace falta saber
     * sobre ella. Mismo comportamiento que AiExcelAnalyzer::resolver_encabezado().
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
         * La detección automática igual se corrió, porque de ahí sale 'fusiones_aplicadas',
         * que es una propiedad del archivo y no de la fila elegida.
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
     * Columnas de la fila que el usuario eligió a mano, con EL MISMO criterio de ancho y de
     * "columna sin nombre" que aplica ExcelHeaderDetector sobre la fila que elige él.
     *
     * Se le pasa al detector la ventana recortada desde la fila pedida hacia abajo en vez de
     * recalcular el ancho acá: el criterio del detector distingue "columna que los datos usan"
     * de "celda suelta perdida a la derecha", y una copia local se despega de él en cuanto se
     * recalibra uno de los dos. Si el detector devolviera otra fila, no se inventa nada: se
     * degrada a los encabezados crudos.
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
     * Nombres de encabezado que cubren DOS O MÁS columnas y que el mapeo se repartió a
     * ciegas. Cada entrada es un aviso listo para el paso 2 del modal.
     *
     * 🔴 POR QUÉ ESTO AVISA EN VEZ DE ELEGIR, Y POR QUÉ NO SE "SIMPLIFICA" A ELEGIR MEJOR.
     *
     * Con una cabecera fusionada sobre dos columnas, la propagación deja el MISMO nombre en
     * las dos. Si Claude devuelve dos ítems con ese nombre, enrich_column_mapping() les
     * reparte los índices EN EL ORDEN EN QUE VINIERON, y nada garantiza ese orden ni hay dato
     * en el archivo que lo decida. En artículos eso es costo y precio invertidos en todo el
     * catálogo sin un error en pantalla; acá es dos propiedades del cliente cruzadas, que se
     * ve todavía menos. La información para desambiguar no está en el archivo: el usuario sí
     * la tiene, así que el caso viaja al paso 2 como aviso y decide él antes de que se toque
     * la base. La importación no degrada en silencio.
     *
     * Sólo se avisa cuando el nombre repetido llegó al mapeo: una alerta amarilla sin motivo
     * es lo que hace que se dejen de leer todas las alertas amarillas.
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
     * columna fue a parar cada propiedad, y el modal es compartido por los tres modelos.
     *
     * @param  string $nombre
     * @param  array  $letras
     * @param  array  $asignaciones
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
     * Lee las primeras N filas del Excel y retorna un array con headers y muestra.
     *
     * La fila de cabecera ya NO es siempre la 1: la decide ExcelHeaderDetector con la regla
     * de §1.3 del plan, que en un archivo normal sigue dando 1 y en uno con título y razón
     * social arriba de la tabla da la fila del encabezado de verdad.
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
        $rows    = [];

        $fila_encabezado = $this->resolver_fila_de_encabezado($excel_path, $indice_hoja, $fila_encabezado);

        /*
         * Se lee con preservar_filas_vacias = true a propósito: así el contador de filas es
         * el número FÍSICO del Excel y coincide con el que devolvió el detector de encabezado.
         */
        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);

        /* Contador de fila física leída en la hoja. */
        $row_number = 0;

        foreach ($lectura->filas() as $row) {
            $row_number++;

            /* Saltear todo lo que esté arriba de la cabecera detectada. */
            if ($row_number < $fila_encabezado) {
                continue;
            }

            /* Extraemos los valores de las celdas como strings simples. */
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $value = $cell->getValue();

                if ($value instanceof \DateTime) {
                    $value = $value->format('Y-m-d');
                }

                $cells[] = (string) ($value ?? '');
            }

            if ($row_number === $fila_encabezado) {
                /* Fila de encabezado. */
                $headers = $cells;
            } else {
                $rows[] = $cells;
            }

            /* Dejamos de leer una vez que tenemos suficientes filas de muestra. */
            if ($row_number >= $fila_encabezado + self::SAMPLE_ROWS) {
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
1bis. Devolvé UNA entrada por columna del Excel, en el MISMO orden en que aparecen las columnas, de izquierda a derecha, incluidas las que no correspondan a ninguna propiedad. Si dos columnas comparten el mismo encabezado (pasa cuando la cabecera está fusionada sobre varias columnas), devolvé una entrada por cada una, igual en orden de izquierda a derecha: la primera entrada con ese nombre se asigna a la columna de más a la izquierda.
2. Si una columna no corresponde a ninguna propiedad del sistema, usá null en system_property.
3. La propiedad más importante es "nombre" — es el nombre o razón social del cliente.
4. La propiedad "numero" corresponde al número o código de cliente (identificador interno).
5. Para "condicion_frente_al_iva": mapeá columnas como "Condición IVA", "IVA", "Tipo IVA" a esta propiedad.
6. Para "tipo_de_precio": mapeá columnas como "Lista de precios", "Tipo precio" a esta propiedad.
7. Para "saldo_actual": mapeá columnas de saldo, deuda o cuenta corriente a esta propiedad.
8. Para "sucursal": mapeá columnas como "Sucursal", "Local", "Punto de venta", "Filial" o "Depósito" a esta propiedad. NO la confundas con "direccion", que es el domicilio del cliente (calle y número): "sucursal" es la sucursal del negocio a la que pertenece el cliente. Si una misma planilla tiene las dos columnas, cada una va a su propia propiedad.

9. Devolvé EXCLUSIVAMENTE el siguiente JSON sin texto adicional:

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
            /*
             * El nombre de la variable de entorno NO va al mensaje del usuario: contarle a un
             * tercero cómo está configurado el servidor es información nuestra, no suya. Va al log.
             */
            Log::error('AiClientAnalyzer: falta la clave de API de Anthropic en la configuración del servidor');

            throw new \RuntimeException(self::MENSAJE_IA_SIN_CONFIGURAR);
        }

        Log::info('AiClientAnalyzer: llamando a Claude API', [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => self::MAX_TOKENS,
        ]);

        /* Cliente HTTP con la misma configuración TLS que admin-api. */
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
            Log::error('AiClientAnalyzer: no hubo respuesta de Claude API', [
                'message' => $e->getMessage(),
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_SIN_RESPUESTA);
        }

        if (!$response->successful()) {
            Log::error('AiClientAnalyzer: error en respuesta de Claude', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            /*
             * Detectar el tipo de error desde el JSON de respuesta de Anthropic.
             * Los errores transitorios tienen type: overloaded_error, api_error, etc.
             * Este bloque existía sólo en AiExcelAnalyzer: clientes y proveedores veían el error
             * crudo. Es la misma copia, sin cambios.
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
            Log::error('AiClientAnalyzer: Claude devolvió una respuesta sin contenido de texto', [
                'body' => $response->body(),
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
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

            /* El JSON crudo y el error de parseo ya quedaron en el Log::error de arriba. */
            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
        }

        /* Validamos que tenga la estructura esperada con column_mapping. */
        if (!isset($parsed['column_mapping']) || !is_array($parsed['column_mapping'])) {
            Log::error('AiClientAnalyzer: la respuesta de Claude no trae column_mapping', [
                'raw_response' => $claude_text,
            ]);

            throw new \RuntimeException(self::MENSAJE_IA_RESPUESTA_ILEGIBLE);
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
        /*
         * 🔴 LISTA DE ÍNDICES POR NOMBRE, NO EL PRIMERO — LO MISMO QUE EN ARTÍCULOS (T3).
         *
         * Esto era `if (!isset(...)) { ... = $header_index; }`: se quedaba con el PRIMER
         * índice de cada nombre y descartaba los demás. Con una cabecera fusionada sobre dos
         * columnas, la propagación deja el mismo nombre en las dos, y los dos ítems que Claude
         * devuelva con ese nombre se llevaban el mismo índice: dos propiedades del sistema
         * leyendo la MISMA celda del Excel, sin un error en pantalla. Por eso el mapa es una
         * lista y se consume en orden. De paso arregla el bug latente de cualquier planilla
         * que repita un nombre de columna, sin fusiones de por medio.
         *
         * Repartir en orden NO alcanza: el orden es el que devolvió Claude y nada lo
         * garantiza. La red que atrapa eso es detectar_columnas_ambiguas(), que avisa al paso
         * 2 en vez de elegir en silencio.
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
            $confidence     = max(0, min(1, (float) $raw_confidence));

            $excel_column_name      = (string) ($mapping_item['excel_column'] ?? '');
            $normalized_excel_name  = $this->normalize_header_key($excel_column_name);

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
                     * Claude devolvió más ítems con ese nombre que columnas con ese nombre hay
                     * en el archivo (se lo inventó). No hay índice libre que darle: se repite
                     * el último, que es el comportamiento de siempre para un ítem de más, y
                     * detectar_columnas_ambiguas() lo denuncia en el aviso del paso 2.
                     */
                    $excel_column_index = $indices_del_nombre[count($indices_del_nombre) - 1];
                }
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
     * Cuenta el total de filas de datos del Excel (excluye la fila de cabecera).
     *
     * 🔴 T1 — criterio de preservar_filas_vacias unificado con el del detector de encabezado.
     * El detector devuelve un número de fila FÍSICO; si acá se leyera con
     * preservar_filas_vacias = false, ese número se compararía contra un contador de filas NO
     * VACÍAS y cada fila vacía de arriba del encabezado se comería una fila de datos del total
     * que se le muestra al usuario. Se lee físico y las vacías se descartan una por una, que
     * es lo que hacía antes el flag.
     *
     * @param  string   $excel_path       Ruta absoluta al archivo Excel
     * @param  int      $indice_hoja      Hoja 0-based elegida por el usuario
     * @param  int|null $fila_encabezado  Fila 1-based del encabezado; null = se detecta sola
     * @return int                 Cantidad de filas de datos
     */
    protected function count_data_rows(string $excel_path, $indice_hoja = 0, $fila_encabezado = null): int
    {
        /* Contador de filas de datos (sin contar la fila de cabecera). */
        $data_row_count = 0;

        $fila_encabezado = $this->resolver_fila_de_encabezado($excel_path, $indice_hoja, $fila_encabezado);

        $lectura = ExcelWorkbookReader::abrir($excel_path, $indice_hoja, true);

        $row_number = 0;

        foreach ($lectura->filas() as $row) {
            $row_number++;

            /* Saltear todo lo que esté arriba del encabezado, y el encabezado mismo. */
            if ($row_number <= $fila_encabezado) {
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
     * Fila 1-based que se toma como encabezado: la que pidió el usuario, o la que decide
     * ExcelHeaderDetector.
     *
     * 🔴 La regla vive en ExcelHeaderDetector y está espejada en JS en la SPA. Si cambia una,
     * cambia la otra: el navegador calcula start_row con su copia y el backend arma el mapeo
     * con ésta.
     *
     * @param  string   $excel_path
     * @param  int      $indice_hoja
     * @param  int|null $fila_encabezado
     * @return int
     */
    protected function resolver_fila_de_encabezado($excel_path, $indice_hoja, $fila_encabezado)
    {
        if (!is_null($fila_encabezado) && is_numeric($fila_encabezado) && (int) $fila_encabezado >= 1) {
            return (int) $fila_encabezado;
        }

        $deteccion = ExcelHeaderDetector::detectar_en($excel_path, $indice_hoja);

        return (int) $deteccion['fila'];
    }

    /**
     * Una fila es "vacía" cuando ninguna de sus celdas tiene contenido.
     *
     * Hace falta porque los lectores leen con preservar_filas_vacias = true (para que el
     * número de fila sea el físico del Excel): si no se descartan acá, las filas vacías se
     * cuentan como datos e inflan el total que ve el usuario.
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
