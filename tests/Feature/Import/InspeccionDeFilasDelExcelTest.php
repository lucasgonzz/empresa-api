<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\import\excel\ExcelSheetInspector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Models\ExcelAnalysisRun;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests de aceptacion de `POST /api/ai-excel-import/inspeccionar-filas`
 * (mision excel-ultima-fila-servidor, CA1 a CA5 del PLAN).
 *
 * POR QUE EXISTE EL ENDPOINT
 * --------------------------
 * SheetJS (en el navegador) no puede leer los .xlsx con zip64 forzado en el local header
 * (error "Bad compressed size"), como el Excel de Plazoleta de 55.521 filas. En ese caso el
 * SPA le pide al servidor la ultima fila con contenido de cada hoja, leyendola con
 * ExcelSheetInspector (XMLReader en streaming, que si lee esos zip).
 *
 * ESTE ARCHIVO NO HEREDA ImportTestCase: ese necesita el tenant 900, que la base de testing
 * no trae. Crea su propio usuario, como ImportHistoryChunksPaginationTest.
 *
 * Los .xlsx sinteticos se arman a mano dentro del test (ZipArchive de PHP 7.4 no permite
 * forzar zip64) en un directorio temporal que se borra en tearDown. No se commitean binarios.
 *
 * IMPORTANTE: PHP 7.4 estricto (sin sintaxis de PHP 8 en este archivo).
 */
class InspeccionDeFilasDelExcelTest extends TestCase
{
    use DatabaseTransactions;

    /** Ruta del endpoint bajo prueba. */
    const URL_INSPECCION = '/api/ai-excel-import/inspeccionar-filas';

    /** Mensaje cuando el archivo no es .xlsx (acordado en el contrato del PLAN). */
    const MENSAJE_SOLO_XLSX = 'La detección de filas en el servidor sólo lee archivos .xlsx.';

    /** Mensaje cuando falta la subida (mismo texto que /analyze). */
    const MENSAJE_SIN_ARCHIVO = 'Se requiere un archivo Excel válido en el campo "excel_file".';

    /** @var User usuario dueño de la sesion de cada test */
    protected $comercio;

    /** @var string directorio temporal donde se arman los xlsx sinteticos */
    protected $directorio_temporal;

    /**
     * Crea el usuario, abre sesion y prepara el directorio temporal.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'         => 'Comercio inspeccion filas',
            'company_name' => 'Ferreteria inspeccion filas',
            'email'        => 'inspeccion-filas-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->actingAs($this->comercio, 'web');

        $this->directorio_temporal = sys_get_temp_dir() . '/inspeccion-filas-' . uniqid();
        mkdir($this->directorio_temporal, 0777, true);
    }

    /**
     * Borra los archivos temporales de cada test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->directorio_temporal && is_dir($this->directorio_temporal)) {
            foreach (glob($this->directorio_temporal . '/*') as $archivo) {
                @unlink($archivo);
            }
            @rmdir($this->directorio_temporal);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers de fixture
    // ------------------------------------------------------------------

    /**
     * Escribe un zip a mano. Cada entrada puede llevar zip64 forzado en el local header
     * (tamaños 0xFFFFFFFF + extra 0x0001), igual que el Excel de Plazoleta.
     *
     * @param  string $ruta_destino  archivo a crear
     * @param  array  $entradas      lista de ['nombre' => string, 'contenido' => string, 'zip64' => bool]
     * @return void
     */
    protected function escribir_zip(string $ruta_destino, array $entradas)
    {
        $cuerpo = '';
        $directorio_central = '';

        foreach ($entradas as $entrada) {
            $nombre = $entrada['nombre'];
            $datos = gzdeflate($entrada['contenido']);
            $crc = crc32($entrada['contenido']);
            $tamano_original = strlen($entrada['contenido']);
            $tamano_comprimido = strlen($datos);
            $con_zip64 = !empty($entrada['zip64']);
            $desplazamiento = strlen($cuerpo);

            // Version necesaria: 45 si usa zip64, 20 si no.
            $version = $con_zip64 ? 45 : 20;

            if ($con_zip64) {
                // Extra 0x0001: tamaño sin comprimir y comprimido de 64 bits.
                $extra = pack('vv', 0x0001, 16) . pack('P', $tamano_original) . pack('P', $tamano_comprimido);
                $tamano_local_comprimido = 0xFFFFFFFF;
                $tamano_local_original = 0xFFFFFFFF;
            } else {
                $extra = '';
                $tamano_local_comprimido = $tamano_comprimido;
                $tamano_local_original = $tamano_original;
            }

            // Local file header.
            $cuerpo .= pack('VvvvvvVVVvv', 0x04034b50, $version, 0, 8, 0, 0x21, $crc,
                $tamano_local_comprimido, $tamano_local_original, strlen($nombre), strlen($extra))
                . $nombre . $extra . $datos;

            // Central directory header (con tamaños reales de 32 bits).
            $directorio_central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, $version, 0, 8, 0, 0x21, $crc,
                $tamano_comprimido, $tamano_original, strlen($nombre), 0, 0, 0, 0, 0, $desplazamiento)
                . $nombre;
        }

        $inicio_directorio = strlen($cuerpo);
        $fin = pack('VvvvvVVv', 0x06054b50, 0, 0, count($entradas), count($entradas),
            strlen($directorio_central), $inicio_directorio, 0);

        file_put_contents($ruta_destino, $cuerpo . $directorio_central . $fin);
    }

    /**
     * Arma un .xlsx minimo con las hojas dadas y sus shared strings.
     *
     * @param  string $ruta_destino
     * @param  array  $hojas           lista de ['nombre' => string, 'xml' => string con el <worksheet> completo]
     * @param  array  $shared_strings  textos de sharedStrings.xml (el indice es la posicion)
     * @param  bool   $zip64           si true, la parte de cada hoja lleva zip64 forzado
     * @return void
     */
    protected function armar_xlsx(string $ruta_destino, array $hojas, array $shared_strings, bool $zip64)
    {
        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        $hojas_xml = '';
        $relaciones = '';
        $entradas_hojas = [];

        foreach ($hojas as $posicion => $hoja) {
            $numero = $posicion + 1;
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $numero . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $hojas_xml .= '<sheet name="' . $hoja['nombre'] . '" sheetId="' . $numero . '" r:id="rId' . $numero . '"/>';
            $relaciones .= '<Relationship Id="rId' . $numero . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $numero . '.xml"/>';
            $entradas_hojas[] = ['nombre' => 'xl/worksheets/sheet' . $numero . '.xml', 'contenido' => $hoja['xml'], 'zip64' => $zip64];
        }

        $tipos .= '</Types>';

        $id_shared = 'rId' . (count($hojas) + 1);
        $relaciones .= '<Relationship Id="' . $id_shared . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $textos = '';
        foreach ($shared_strings as $texto) {
            $textos .= '<si><t xml:space="preserve">' . $texto . '</t></si>';
        }

        $entradas = [
            ['nombre' => '[Content_Types].xml', 'contenido' => $tipos, 'zip64' => false],
            ['nombre' => '_rels/.rels', 'contenido' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>', 'zip64' => false],
            ['nombre' => 'xl/workbook.xml', 'contenido' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $hojas_xml . '</sheets></workbook>', 'zip64' => false],
            ['nombre' => 'xl/_rels/workbook.xml.rels', 'contenido' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relaciones . '</Relationships>', 'zip64' => false],
            ['nombre' => 'xl/sharedStrings.xml', 'contenido' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared_strings) . '" uniqueCount="' . count($shared_strings) . '">' . $textos . '</sst>', 'zip64' => false],
        ];

        $this->escribir_zip($ruta_destino, array_merge($entradas, $entradas_hojas));
    }

    /**
     * Envuelve filas <row> en el XML de una hoja (sin <dimension>, como la de Plazoleta).
     *
     * @param  string $filas_xml
     * @return string
     */
    protected function xml_de_hoja(string $filas_xml)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $filas_xml . '</sheetData></worksheet>';
    }

    /**
     * Fila de una sola celda con un shared string.
     *
     * @param  int $fila
     * @param  int $indice_shared
     * @return string
     */
    protected function fila_con_texto(int $fila, int $indice_shared)
    {
        return '<row r="' . $fila . '"><c r="A' . $fila . '" t="s"><v>' . $indice_shared . '</v></c></row>';
    }

    /**
     * Arma el xlsx de CA1: zip64 forzado, sin <dimension>, filas 1-5 con datos, fila 6 con
     * un shared string en blanco, fila 7 con una celda sólo con estilo y fila 8 vacía con formato.
     *
     * @return string ruta del archivo generado
     */
    protected function armar_xlsx_zip64_con_filas_vacias_al_final()
    {
        $filas = '';
        for ($fila = 1; $fila <= 5; $fila++) {
            $filas .= $this->fila_con_texto($fila, $fila - 1);
        }
        // Fila 6: shared string "   " (indice 5), no es contenido.
        $filas .= $this->fila_con_texto(6, 5);
        // Fila 7: celda sólo con estilo.
        $filas .= '<row r="7"><c r="A7" s="1"/></row>';
        // Fila 8: fila vacía con formato.
        $filas .= '<row r="8" s="1" customFormat="1"/>';

        $ruta = $this->directorio_temporal . '/lista.xlsx';
        $this->armar_xlsx(
            $ruta,
            [['nombre' => 'Lista', 'xml' => $this->xml_de_hoja($filas)]],
            ['Codigo', 'Pintura', 'Tornillo', 'Martillo', 'Cinta', '   '],
            true
        );

        return $ruta;
    }

    /**
     * Verifica que el archivo sea zip64 de verdad: el local header de la hoja trae tamaños
     * 0xFFFFFFFF y el extra 0x0001. Evita que el test pruebe un zip común sin darse cuenta.
     *
     * @param  string $ruta
     * @return void
     */
    protected function afirmar_que_es_zip64(string $ruta)
    {
        $contenido = file_get_contents($ruta);
        $posicion_nombre = strpos($contenido, 'xl/worksheets/sheet1.xml');
        $this->assertNotFalse($posicion_nombre, 'No se encontró la hoja en el zip del fixture.');

        // El local header empieza 30 bytes antes del nombre.
        $encabezado = unpack('Vfirma/vversion/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomprimido/Vsin_comprimir/vlargo_nombre/vlargo_extra',
            substr($contenido, $posicion_nombre - 30, 30));

        $this->assertSame(0x04034b50, $encabezado['firma']);
        $this->assertSame(0xFFFFFFFF, $encabezado['comprimido']);
        $this->assertSame(0xFFFFFFFF, $encabezado['sin_comprimir']);
        $this->assertSame(20, $encabezado['largo_extra']);

        $id_extra = unpack('vid', substr($contenido, $posicion_nombre + $encabezado['largo_nombre'], 2));
        $this->assertSame(0x0001, $id_extra['id']);
    }

    /**
     * Sube un archivo al endpoint como multipart en el campo `excel_file`.
     *
     * @param  string $ruta
     * @param  string $nombre_original
     * @return \Illuminate\Testing\TestResponse
     */
    protected function subir_archivo(string $ruta, string $nombre_original)
    {
        $subida = new UploadedFile($ruta, $nombre_original, null, null, true);

        return $this->call('POST', self::URL_INSPECCION, [], [], ['excel_file' => $subida],
            ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * Cuenta los archivos de storage/app/imported_files (donde /analyze guarda lo subido).
     *
     * @return int
     */
    protected function contar_archivos_importados()
    {
        $archivos = glob(storage_path('app/imported_files') . '/*');

        return $archivos === false ? 0 : count($archivos);
    }

    // ------------------------------------------------------------------
    // CA1
    // ------------------------------------------------------------------

    /**
     * CA1: un zip64 forzado con filas vacías con formato al final devuelve la última fila con
     * contenido (5), las filas declaradas (8), y no guarda nada ni crea corridas de análisis.
     *
     * @return void
     */
    public function test_un_xlsx_zip64_devuelve_la_ultima_fila_con_contenido_sin_guardar_nada()
    {
        $ruta = $this->armar_xlsx_zip64_con_filas_vacias_al_final();
        $this->afirmar_que_es_zip64($ruta);

        $archivos_antes = $this->contar_archivos_importados();
        $corridas_antes = ExcelAnalysisRun::count();

        $respuesta = $this->subir_archivo($ruta, 'lista.xlsx');

        $respuesta->assertStatus(200);
        $this->assertSame(
            [['indice' => 0, 'nombre' => 'Lista', 'filas' => 8, 'ultima_fila_con_contenido' => 5]],
            $respuesta->json('hojas')
        );

        $this->assertSame($archivos_antes, $this->contar_archivos_importados(), 'No debe guardar el archivo subido.');
        $this->assertSame($corridas_antes, ExcelAnalysisRun::count(), 'No debe crear ExcelAnalysisRun.');
    }

    // ------------------------------------------------------------------
    // CA2
    // ------------------------------------------------------------------

    /**
     * CA2: con el fixture de tres hojas los índices y nombres coinciden con listar_hojas
     * (OpenSpout) y las últimas filas con contenido son 8 / 3 / 2.
     *
     * @return void
     */
    public function test_un_libro_de_varias_hojas_devuelve_cada_hoja_alineada_con_openspout()
    {
        $ruta = base_path('tests/Import/fixtures/12_tres_hojas.xlsx');

        $respuesta = $this->subir_archivo($ruta, '12_tres_hojas.xlsx');

        $respuesta->assertStatus(200);
        $hojas = $respuesta->json('hojas');

        $esperadas = ExcelWorkbookReader::listar_hojas($ruta);
        $this->assertCount(count($esperadas), $hojas);

        $ultimas_filas = [8, 3, 2];
        $filas_declaradas = [8, 3, 2];

        foreach ($esperadas as $posicion => $esperada) {
            $this->assertSame($esperada['indice'], $hojas[$posicion]['indice']);
            $this->assertSame($esperada['nombre'], $hojas[$posicion]['nombre']);
            $this->assertSame($filas_declaradas[$posicion], $hojas[$posicion]['filas']);
            $this->assertSame($ultimas_filas[$posicion], $hojas[$posicion]['ultima_fila_con_contenido']);
        }
    }

    /**
     * CA2: en un libro de dos hojas donde la segunda no tiene datos, su última fila con
     * contenido es 0.
     *
     * @return void
     */
    public function test_una_hoja_vacia_da_cero()
    {
        $ruta = $this->directorio_temporal . '/dos_hojas.xlsx';
        $this->armar_xlsx(
            $ruta,
            [
                ['nombre' => 'Datos', 'xml' => $this->xml_de_hoja($this->fila_con_texto(1, 0) . $this->fila_con_texto(2, 1))],
                ['nombre' => 'Vacia', 'xml' => $this->xml_de_hoja('')],
            ],
            ['Codigo', 'Pintura'],
            false
        );

        $respuesta = $this->subir_archivo($ruta, 'dos_hojas.xlsx');

        $respuesta->assertStatus(200);
        $hojas = $respuesta->json('hojas');
        $this->assertCount(2, $hojas);
        $this->assertSame(2, $hojas[0]['ultima_fila_con_contenido']);
        $this->assertSame('Vacia', $hojas[1]['nombre']);
        $this->assertSame(0, $hojas[1]['ultima_fila_con_contenido']);
    }

    // ------------------------------------------------------------------
    // CA3
    // ------------------------------------------------------------------

    /**
     * CA3: texto plano subido como .xlsx responde 422 con el mensaje limpio, nunca 500.
     *
     * @return void
     */
    public function test_un_archivo_que_no_es_excel_responde_422_con_mensaje_limpio()
    {
        $ruta = $this->directorio_temporal . '/texto.xlsx';
        file_put_contents($ruta, 'esto no es un excel, es texto plano');

        $respuesta = $this->subir_archivo($ruta, 'lista.xlsx');

        $respuesta->assertStatus(422);
        $this->assertSame(ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE, $respuesta->json('message'));
    }

    /**
     * CA3: un .xls responde 422 con el mensaje de sólo .xlsx.
     *
     * @return void
     */
    public function test_un_xls_responde_422()
    {
        $ruta = base_path('tests/Import/fixtures/16_viejo.xls');

        $respuesta = $this->subir_archivo($ruta, '16_viejo.xls');

        $respuesta->assertStatus(422);
        $this->assertSame(self::MENSAJE_SOLO_XLSX, $respuesta->json('message'));
    }

    /**
     * CA3: sin el campo excel_file responde 422 con el mensaje de /analyze.
     *
     * @return void
     */
    public function test_sin_archivo_responde_422()
    {
        $respuesta = $this->postJson(self::URL_INSPECCION, []);

        $respuesta->assertStatus(422);
        $this->assertSame(self::MENSAJE_SIN_ARCHIVO, $respuesta->json('message'));
    }

    /**
     * CA3: sin sesión responde 401 (lo da el grupo auth:sanctum).
     *
     * @return void
     */
    public function test_sin_sesion_responde_401()
    {
        // Se olvidan los guards resueltos para que la petición llegue sin usuario.
        $this->app['auth']->forgetGuards();

        $respuesta = $this->postJson(self::URL_INSPECCION, []);

        $respuesta->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // CA4
    // ------------------------------------------------------------------

    /**
     * CA4: el inspector nuevo devuelve null (sin lanzar) si el archivo no es un zip.
     *
     * @return void
     */
    public function test_el_inspector_no_lanza_con_un_archivo_que_no_es_zip()
    {
        $ruta = $this->directorio_temporal . '/no_es_zip.xlsx';
        file_put_contents($ruta, 'texto plano');

        $this->assertNull(ExcelSheetInspector::filas_con_contenido_por_hoja($ruta));
    }

    /**
     * CA4: filas_por_hoja no cambia: sobre el fixture de CA1 sigue dando [0 => 8].
     * (Este test ya pasa antes de implementar: es de no regresión.)
     *
     * @return void
     */
    public function test_filas_por_hoja_no_cambia()
    {
        $ruta = $this->armar_xlsx_zip64_con_filas_vacias_al_final();
        $this->afirmar_que_es_zip64($ruta);

        $this->assertSame([0 => 8], ExcelSheetInspector::filas_por_hoja($ruta));
    }

    // ------------------------------------------------------------------
    // CA5
    // ------------------------------------------------------------------

    /**
     * CA5: caso real. Con TEST_EXCEL_PLAZOLETA apuntando a una copia del Excel de Plazoleta,
     * el endpoint responde la hoja "Datos" con 55521 filas y última fila con contenido 55521
     * en menos de 10 segundos. Sin la variable, se saltea. No importa nada.
     *
     * @return void
     */
    public function test_el_excel_real_de_plazoleta_da_55521()
    {
        $ruta = getenv('TEST_EXCEL_PLAZOLETA');

        if (!$ruta || !is_file($ruta)) {
            $this->markTestSkipped('Falta TEST_EXCEL_PLAZOLETA con la ruta de una copia del Excel de Plazoleta.');
        }

        $inicio = microtime(true);
        $respuesta = $this->subir_archivo($ruta, 'plazoleta.xlsx');
        $segundos = microtime(true) - $inicio;

        $respuesta->assertStatus(200);
        $this->assertSame(
            [['indice' => 0, 'nombre' => 'Datos', 'filas' => 55521, 'ultima_fila_con_contenido' => 55521]],
            $respuesta->json('hojas')
        );
        $this->assertLessThan(10, $segundos, 'La detección tardó 10 segundos o más.');
    }
}
