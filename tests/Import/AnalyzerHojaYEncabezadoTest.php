<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Hoja elegida y fila de encabezado dentro de los analizadores y las estadísticas
 * (unidad 2 de la misión de importación de Excel).
 *
 * Lo que cubre, en orden de qué tan caro sale que se rompa:
 *
 *   1. Las estadísticas no cuentan el encabezado como una fila de datos, y los números de
 *      fila que le muestran al usuario son los FÍSICOS del Excel. Con el encabezado en la
 *      fila 4, antes de esta misión el texto "codigo_de_proveedor" entraba como si fuera un
 *      código de proveedor más y todas las filas reportadas estaban corridas en 3.
 *   2. El interruptor de seguridad: sin fila de encabezado, la rama vieja corre igual que
 *      siempre. Es lo que acota el riesgo de la misión a los archivos que ya estaban rotos.
 *   3. La compatibilidad hacia atrás de las firmas (T12 y T13): AdminSync llama a los
 *      analyzers con dos argumentos y dos tests existentes los invocan por reflexión con
 *      firma fija. Un parámetro nuevo sin default rompe un endpoint de producción.
 *   4. Que la hoja elegida atraviese los lectores del analyzer y no se quede en el camino.
 *
 * No extiende ImportTestCase: acá no se siembra ningún tenant ni se escribe nada. Se leen
 * archivos, y ExcelDuplicateStats hace una consulta de sólo lectura contra articles.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class AnalyzerHojaYEncabezadoTest extends TestCase
{
    /**
     * Planilla que escribe archivo_con_nota_suelta(), para borrarla al terminar.
     *
     * @var string|null
     */
    protected $archivo_temporal = null;

    /**
     * Ruta absoluta a un fixture de tests/Import/fixtures/.
     *
     * @param  string $archivo
     * @return string
     */
    protected function fixture($archivo)
    {
        return __DIR__ . '/fixtures/' . $archivo;
    }

    /**
     * Llama a un método protected de AiExcelAnalyzer vía reflexión (son protected a
     * propósito; no cambiar la visibilidad del método de producción para testearlo).
     *
     * @param  string $metodo
     * @param  array  $argumentos
     * @return mixed
     */
    protected function invocar_analyzer($metodo, array $argumentos)
    {
        $analyzer = new AiExcelAnalyzer(1);

        $reflexion = new \ReflectionMethod($analyzer, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($analyzer, $argumentos);
    }

    /* ---------------------------------------------------------------------
     * Test extra obligatorio del plan (§5): las estadísticas y el encabezado.
     * ------------------------------------------------------------------- */

    /**
     * 14_encabezado_corrido.xlsx tiene título en la fila 1, razón social en la 2, la 3
     * vacía, el encabezado real en la 4 y 5 filas de datos (5 a 9).
     *
     * Con fila_encabezado = 4, ExcelDuplicateStats tiene que reportar 5 filas de datos,
     * ningún código de proveedor igual al texto del encabezado, y los números de fila
     * FÍSICOS 5..9 — no 2..6, que es lo que salía contando desde la primera fila del
     * iterador. Esos números son los que la pantalla le muestra al usuario al lado de cada
     * duplicado ("aparece en las filas 5, 6 y 7"); si están corridos, el usuario abre el
     * Excel, va a la fila que le dijimos y ve otra cosa.
     *
     * La columna que se pasa como bar_code es la 7 (iva): las cinco filas de datos tienen
     * "21", así que es el único valor repetido del archivo y es lo que hace que el detalle
     * con las filas exista y se pueda asertar.
     *
     * @return void
     */
    public function test_las_estadisticas_no_cuentan_el_encabezado_como_dato()
    {
        $archivo = $this->fixture('14_encabezado_corrido.xlsx');

        $stats = ExcelDuplicateStats::analyze(
            $archivo,
            7,     /* bar_code   -> columna iva, todas "21" */
            2,     /* provider_code */
            null,
            1,
            ['hoja' => 0, 'fila_encabezado' => 4]
        );

        $this->assertSame(5, $stats['total_filas_datos'], 'El encabezado, el título y la razón social no son filas de datos.');

        $this->assertSame(
            ['PC-C-1', 'PC-C-2', 'PC-C-3', 'PC-C-4', 'PC-C-5'],
            $stats['provider_codes_distintos']
        );

        $this->assertNotContains(
            'codigo_de_proveedor',
            $stats['provider_codes_distintos'],
            'El texto del encabezado se coló como si fuera un código de proveedor.'
        );

        $this->assertSame(
            [
                ['codigo' => '21', 'veces' => 5, 'filas' => [5, 6, 7, 8, 9]],
            ],
            $stats['detalle_bar_codes_duplicados'],
            'Las filas reportadas tienen que ser las físicas del Excel (5 a 9), no 2 a 6.'
        );
    }

    /**
     * La cadena de identificación cuenta lo mismo: 5 filas, y las 5 caen en el escalón
     * provider_code. Si el encabezado se contara como dato serían 6 y una de ellas tendría
     * como "código" el texto "codigo_de_proveedor".
     *
     * @return void
     */
    public function test_la_cadena_de_identificacion_arranca_debajo_del_encabezado()
    {
        $mapeo = [
            ['system_property' => 'codigo_de_proveedor', 'excel_column_index' => 2],
            ['system_property' => 'nombre',              'excel_column_index' => 3],
        ];

        $resultado = $this->invocar_analyzer('analyze_identification_chain', [
            $this->fixture('14_encabezado_corrido.xlsx'),
            $mapeo,
            0,
            4,
        ]);

        $this->assertTrue($resultado['cadena_identificacion']['disponible']);
        $this->assertSame(5, $resultado['cadena_identificacion']['total_filas']);

        $escalones = [];

        foreach ($resultado['cadena_identificacion']['escalones'] as $escalon) {
            $escalones[$escalon['campo']] = $escalon['filas'];
        }

        $this->assertSame(5, $escalones['provider_code']);
        $this->assertSame(0, $escalones['sin_identificador']);
    }

    /* ---------------------------------------------------------------------
     * El interruptor de seguridad de §1.3.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 ESTE TEST ASERTA A PROPÓSITO EL COMPORTAMIENTO VIEJO, CON DEFECTO Y TODO.
     *
     * Sin fila_encabezado, ExcelDuplicateStats corre la rama de siempre: saltea la primera
     * fila del iterador y numera desde ahí. Sobre 14_encabezado_corrido.xlsx eso da 7 filas
     * de datos y mete el texto del encabezado adentro de los códigos de proveedor — que es
     * exactamente el defecto que la misión vino a arreglar, y que se sigue viendo acá porque
     * es lo que pasa cuando NADIE le dice al helper dónde está el encabezado.
     *
     * El valor del test no es celebrar el bug: es fijar que la rama vieja quedó intacta. Es
     * la mitad del diseño que acota todo el riesgo de esta misión a los archivos que ya
     * estaban rotos. Si alguien "limpia" el interruptor y deja una sola rama, este test se
     * pone en rojo y avisa antes de que el cambio llegue a un cliente con archivos que hoy
     * andan bien.
     *
     * @return void
     */
    public function test_sin_fila_de_encabezado_la_rama_vieja_corre_igual_que_siempre()
    {
        $archivo = $this->fixture('14_encabezado_corrido.xlsx');

        $stats = ExcelDuplicateStats::analyze($archivo, 7, 2, null, 1);

        $this->assertSame(7, $stats['total_filas_datos']);

        $this->assertContains(
            'codigo_de_proveedor',
            $stats['provider_codes_distintos'],
            'La rama vieja tiene que seguir haciendo exactamente lo de antes, defecto incluido.'
        );

        /* Y pasarle fila_encabezado = 1 explícitamente es lo mismo que no pasarle nada. */
        $stats_con_uno = ExcelDuplicateStats::analyze($archivo, 7, 2, null, 1, ['fila_encabezado' => 1]);

        $this->assertSame($stats, $stats_con_uno);
    }

    /**
     * Sobre un archivo normal (encabezado en la fila 1) los dos caminos dan lo mismo: pasar
     * fila_encabezado = 1 o no pasar nada tiene que ser indistinguible.
     *
     * @return void
     */
    public function test_en_un_archivo_normal_pedir_la_fila_1_no_cambia_nada()
    {
        $archivo = $this->fixture('01_codigos_de_proveedor.xlsx');

        $sin_opciones = ExcelDuplicateStats::analyze($archivo, 0, 2, null, 1);
        $con_fila_uno = ExcelDuplicateStats::analyze($archivo, 0, 2, null, 1, ['hoja' => 0, 'fila_encabezado' => 1]);

        $this->assertSame(7, $sin_opciones['total_filas_datos']);
        $this->assertSame($sin_opciones, $con_fila_uno);
    }

    /* ---------------------------------------------------------------------
     * T12 y T13 — compatibilidad hacia atrás de las firmas.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 BLOQUEANTE DE PRODUCCIÓN, NO UN DETALLE DE ESTILO.
     *
     * AdminSync\AiExcelImportController llama `$analyzer->analyze($path, $filename)` con dos
     * argumentos y está fuera del alcance de esta misión, así que no se toca. Ese endpoint es
     * el que usa admin-api contra clientes reales: cualquier parámetro nuevo sin default es
     * un ArgumentCountError ahí, y no lo detecta ningún test de importación.
     *
     * Lo mismo con analyze_identification_chain(), que CadenaIdentificacionTest invoca por
     * reflexión con dos argumentos, y con las dos clases de estadísticas.
     *
     * @dataProvider firmas_que_no_pueden_romperse
     *
     * @param  string $clase
     * @param  string $metodo
     * @param  int    $argumentos_obligatorios  Cuántos argumentos manda el llamador viejo
     * @return void
     */
    public function test_ningun_parametro_nuevo_es_obligatorio($clase, $metodo, $argumentos_obligatorios)
    {
        $reflexion = new \ReflectionMethod($clase, $metodo);

        $this->assertSame(
            $argumentos_obligatorios,
            $reflexion->getNumberOfRequiredParameters(),
            $clase . '::' . $metodo . '() sumó un parámetro obligatorio y rompe a sus llamadores viejos.'
        );

        $this->assertGreaterThan(
            $argumentos_obligatorios,
            $reflexion->getNumberOfParameters(),
            $clase . '::' . $metodo . '() no recibió los parámetros nuevos de hoja / fila de encabezado.'
        );
    }

    /**
     * @return array
     */
    public function firmas_que_no_pueden_romperse()
    {
        return [
            'analyzer de articulos'   => [AiExcelAnalyzer::class, 'analyze', 1],
            'cadena de identificacion' => [AiExcelAnalyzer::class, 'analyze_identification_chain', 2],
            'duplicados'              => [ExcelDuplicateStats::class, 'analyze', 5],
            'formatos numericos'      => [ExcelNumericFormatStats::class, 'analyze', 2],
            'analyzer de clientes'    => [AiClientAnalyzer::class, 'analyze', 1],
            'analyzer de proveedores' => [AiProviderAnalyzer::class, 'analyze', 1],
        ];
    }

    /* ---------------------------------------------------------------------
     * La hoja elegida atraviesa los lectores del analyzer.
     * ------------------------------------------------------------------- */

    /**
     * Los lectores del analyzer tienen que leer la hoja que se les pide, no siempre la
     * primera. Sobre 12_tres_hojas.xlsx, la hoja 1 ("Notas") tiene 3 filas de texto libre:
     * su "encabezado" es la fila 1 y quedan 2 filas de datos. La hoja 0 tiene 7.
     *
     * Si el `break` de "sólo la primera hoja" siguiera vivo en cualquiera de los dos
     * lectores, los dos números darían lo de la hoja 0 y nadie se enteraría.
     *
     * @return void
     */
    public function test_los_lectores_del_analyzer_leen_la_hoja_que_se_les_pide()
    {
        $archivo = $this->fixture('12_tres_hojas.xlsx');

        $muestra = $this->invocar_analyzer('read_sample_rows', [$archivo, 1, null]);

        $this->assertSame(['Estas son notas internas del vendedor'], $muestra['headers']);

        $this->assertSame(
            [
                ['Los precios de la lista no incluyen IVA'],
                ['Actualizado por Marcela el 12 de agosto'],
            ],
            $muestra['rows']
        );

        $this->assertSame(2, $this->invocar_analyzer('count_data_rows', [$archivo, 1, null]));
        $this->assertSame(7, $this->invocar_analyzer('count_data_rows', [$archivo, 0, null]));
    }

    /* ---------------------------------------------------------------------
     * B2 — un nombre de encabezado que cubre dos columnas AVISA.
     * ------------------------------------------------------------------- */

    /**
     * Enriquece un mapeo contra los encabezados (ya con las fusiones propagadas) de un
     * fixture y devuelve el mapeo y los avisos que salieron.
     *
     * @param  string $archivo
     * @param  array  $items  Lo que "devolvió Claude", en ese orden
     * @return array  ['headers' => [...], 'mapeo' => [...], 'avisos' => [...]]
     */
    protected function mapear($archivo, array $items)
    {
        $encabezado = ExcelHeaderDetector::detectar_en($this->fixture($archivo), 0);

        $mapeo = $this->invocar_analyzer('enrich_column_mapping', [$items, $encabezado['columnas']]);

        $avisos = $this->invocar_analyzer('detectar_columnas_ambiguas', [$mapeo, $encabezado['columnas']]);

        return ['headers' => $encabezado['columnas'], 'mapeo' => $mapeo, 'avisos' => $avisos];
    }

    /**
     * Índice de columna que se llevó cada system_property del mapeo.
     *
     * @param  array $mapeo
     * @return array  [system_property => excel_column_letter]
     */
    protected function letras_por_propiedad(array $mapeo)
    {
        $letras = [];

        foreach ($mapeo as $item) {
            $letras[$item['system_property']] = $item['excel_column_letter'];
        }

        return $letras;
    }

    /**
     * 🔴 EL TEST MÁS CARO DE LA MISIÓN. Lo que mide no es que el mapeo acierte —no puede
     * acertar—, sino que NO se equivoque en silencio.
     *
     * 13_cabecera_fusionada.xlsx tiene "PRECIOS" fusionado sobre E1:F1, con costo en E y
     * precio en F en los datos. Después de propagar la fusión, las columnas E y F se llaman
     * las dos "PRECIOS", y no hay NADA en el archivo que diga cuál es cuál: el reparto de
     * índices se hace en el orden en que Claude devolvió los ítems, que es un orden que nadie
     * garantiza. Por eso el análisis tiene que devolver el aviso para el paso 2.
     *
     * @return void
     */
    public function test_un_encabezado_fusionado_avisa_que_cubre_dos_columnas()
    {
        $resultado = $this->mapear('13_cabecera_fusionada.xlsx', [
            ['excel_column' => 'PRECIOS', 'system_property' => 'costo',  'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'precio', 'confidence' => 0.9],
        ]);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'PRECIOS', 'PRECIOS'],
            $resultado['headers'],
            'La fusión E1:F1 tiene que dejar el mismo nombre en las dos columnas.'
        );

        $this->assertSame(['costo' => 'E', 'precio' => 'F'], $this->letras_por_propiedad($resultado['mapeo']));

        $this->assertCount(1, $resultado['avisos'], 'Un nombre cubriendo dos columnas tiene que avisar.');

        $aviso = $resultado['avisos'][0];

        $this->assertSame('PRECIOS', $aviso['nombre']);
        $this->assertSame([4, 5], $aviso['columnas']);
        $this->assertSame(['E', 'F'], $aviso['letras']);

        $this->assertStringContainsString('«PRECIOS» cubre las columnas E y F', $aviso['mensaje']);
        $this->assertStringContainsString('costo → E', $aviso['mensaje']);
        $this->assertStringContainsString('precio → F', $aviso['mensaje']);
    }

    /**
     * 🔴 EL CASO QUE CUESTA PLATA, Y EL MOTIVO DE QUE EL AVISO EXISTA.
     *
     * Mismo archivo, mismas columnas, pero Claude devuelve precio primero. El reparto en orden
     * le da E a precio y F a costo: el catálogo entero del cliente se importa con costo y
     * precio INVERTIDOS. Es peor que el bug que la lista de índices vino a matar (los dos
     * ítems leyendo E), porque aquel al menos dejaba precio == costo, que se ve de una.
     *
     * El sistema no puede saber cuál es cuál —la información no está en el archivo—, así que
     * lo único que se le puede exigir es que LO DIGA. Este test fija eso: pase lo que pase con
     * el orden, el aviso sale y nombra qué se llevó cada columna, para que el usuario lo mire
     * antes de tocar la base.
     *
     * Si alguien "simplifica" detectar_columnas_ambiguas() a elegir en silencio, acá se pone
     * rojo.
     *
     * @return void
     */
    public function test_el_orden_de_claude_no_puede_invertir_costo_y_precio_en_silencio()
    {
        $al_derecho = $this->mapear('13_cabecera_fusionada.xlsx', [
            ['excel_column' => 'PRECIOS', 'system_property' => 'costo',  'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'precio', 'confidence' => 0.9],
        ]);

        $al_reves = $this->mapear('13_cabecera_fusionada.xlsx', [
            ['excel_column' => 'PRECIOS', 'system_property' => 'precio', 'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'costo',  'confidence' => 0.9],
        ]);

        /* El orden de Claude SÍ cambia el mapeo: eso es exactamente lo que no se puede evitar. */
        $this->assertSame(['costo' => 'E', 'precio' => 'F'], $this->letras_por_propiedad($al_derecho['mapeo']));
        $this->assertSame(['precio' => 'E', 'costo' => 'F'], $this->letras_por_propiedad($al_reves['mapeo']));

        /* Y en los dos casos el usuario se entera antes de importar. */
        $this->assertCount(1, $al_derecho['avisos']);
        $this->assertCount(1, $al_reves['avisos'], 'Invertido y sin aviso es el peor escenario posible.');

        $this->assertStringContainsString('precio → E', $al_reves['avisos'][0]['mensaje']);
        $this->assertStringContainsString('costo → F', $al_reves['avisos'][0]['mensaje']);

        $this->assertStringContainsString(
            'no tiene forma de saber cuál es cuál',
            $al_reves['avisos'][0]['mensaje'],
            'El aviso tiene que decir que el sistema no puede decidirlo, no sugerir que acertó.'
        );
    }

    /**
     * Tres ítems para dos columnas: el sobrante se lleva el último índice repetido, que es el
     * defecto que la lista de índices dice haber matado, con el repetido al final en vez de al
     * principio. No se puede repartir lo que no existe — pero se denuncia.
     *
     * @return void
     */
    public function test_tres_items_para_dos_columnas_denuncian_que_dos_leen_la_misma_celda()
    {
        $resultado = $this->mapear('13_cabecera_fusionada.xlsx', [
            ['excel_column' => 'PRECIOS', 'system_property' => 'costo',              'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'precio',             'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'margen_de_ganancia', 'confidence' => 0.9],
        ]);

        $this->assertSame(
            ['costo' => 'E', 'precio' => 'F', 'margen_de_ganancia' => 'F'],
            $this->letras_por_propiedad($resultado['mapeo'])
        );

        $this->assertCount(1, $resultado['avisos']);

        $this->assertStringContainsString(
            'más de una propiedad leyendo la columna F',
            $resultado['avisos'][0]['mensaje'],
            'Dos propiedades sobre la misma celda tienen que aparecer en el aviso.'
        );
    }

    /**
     * Un archivo normal no dispara ningún aviso. Es la mitad que hace que el aviso sirva: una
     * alerta amarilla que aparece siempre es una alerta que se deja de leer.
     *
     * @return void
     */
    public function test_un_archivo_normal_no_dispara_ningun_aviso_de_columna_ambigua()
    {
        $resultado = $this->mapear('01_codigos_de_proveedor.xlsx', [
            ['excel_column' => 'costo',  'system_property' => 'costo',  'confidence' => 0.9],
            ['excel_column' => 'precio', 'system_property' => 'precio', 'confidence' => 0.9],
        ]);

        $this->assertSame([], $resultado['avisos']);
        $this->assertSame(['costo' => 'E', 'precio' => 'F'], $this->letras_por_propiedad($resultado['mapeo']));
    }

    /**
     * Un nombre repetido en dos columnas que NADIE mapeó no es un problema del usuario y no
     * tiene que gastarle una alerta amarilla.
     *
     * @return void
     */
    public function test_un_nombre_repetido_que_no_llego_al_mapeo_no_avisa()
    {
        $resultado = $this->mapear('13_cabecera_fusionada.xlsx', [
            ['excel_column' => 'nombre', 'system_property' => 'nombre', 'confidence' => 0.9],
        ]);

        $this->assertSame([], $resultado['avisos']);
    }

    /* ---------------------------------------------------------------------
     * B4 — clientes y proveedores reciben lo mismo que artículos.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 LA PROPAGACIÓN DE FUSIONES NO ERA UNA FEATURE DE ARTÍCULOS.
     *
     * Se había entregado sólo en AiExcelAnalyzer: a Claude se le mandaba, para clientes y
     * proveedores, el encabezado `[..., "PRECIOS", ""]` —con el vacío como nombre de columna—
     * y el análisis no devolvía ni el aviso ni la hoja ni el encabezado. El modal es
     * compartido: al usuario de clientes se le mostraba la misma pantalla que al de artículos.
     *
     * Los tres analizadores tienen que resolver el encabezado IGUAL sobre el mismo archivo.
     *
     * @return void
     */
    public function test_los_tres_analizadores_reciben_las_fusiones_propagadas()
    {
        $archivo = $this->fixture('13_cabecera_fusionada.xlsx');

        $esperado = [
            'fila'                => 1,
            'motivo'              => 'primera_fila_con_contenido',
            'confianza'           => 'alta',
            'columnas'            => ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'PRECIOS', 'PRECIOS'],
            'columnas_sin_nombre' => [],
            'fusiones_aplicadas'  => 1,
            'origen'              => 'automatico',
        ];

        foreach ($this->analizadores() as $etiqueta => $analizador) {
            $reflexion = new \ReflectionMethod($analizador, 'resolver_encabezado');
            $reflexion->setAccessible(true);

            $this->assertSame(
                $esperado,
                $reflexion->invokeArgs($analizador, [$archivo, 0, null]),
                'El analizador de ' . $etiqueta . ' no resuelve el encabezado como el de artículos.'
            );
        }
    }

    /**
     * El aviso de columnas ambiguas es palabra por palabra el mismo en los tres analizadores.
     *
     * Los tres tienen su propia copia del método (son tres clases casi idénticas, y unificarlas
     * es otra misión): este test es lo que impide que las copias se separen sin que nadie se
     * entere, que es como se perdió la propagación de fusiones en clientes y proveedores.
     *
     * @return void
     */
    public function test_los_tres_analizadores_arman_el_mismo_aviso()
    {
        $headers = ['codigo', 'nombre', 'PRECIOS', 'PRECIOS'];

        $items = [
            ['excel_column' => 'PRECIOS', 'system_property' => 'costo',  'confidence' => 0.9],
            ['excel_column' => 'PRECIOS', 'system_property' => 'precio', 'confidence' => 0.9],
        ];

        $mensajes = [];

        foreach ($this->analizadores() as $etiqueta => $analizador) {
            $enriquecer = new \ReflectionMethod($analizador, 'enrich_column_mapping');
            $enriquecer->setAccessible(true);

            $detectar = new \ReflectionMethod($analizador, 'detectar_columnas_ambiguas');
            $detectar->setAccessible(true);

            $mapeo  = $enriquecer->invokeArgs($analizador, [$items, $headers]);
            $avisos = $detectar->invokeArgs($analizador, [$mapeo, $headers]);

            $this->assertCount(1, $avisos, 'El analizador de ' . $etiqueta . ' no avisó.');

            /* El reparto en orden también tiene que ser el mismo en los tres. */
            $this->assertSame([2, 3], [
                $mapeo[0]['excel_column_index'],
                $mapeo[1]['excel_column_index'],
            ], 'El analizador de ' . $etiqueta . ' no reparte los índices en orden.');

            /*
             * El texto se compara con los MISMOS argumentos en los tres, y no el de cada
             * aviso: 'costo' y 'precio' no son propiedades válidas de clientes ni de
             * proveedores (cada analizador filtra por su propia lista), así que ahí los
             * mensajes difieren por el contenido, no por la redacción. Lo que no puede
             * divergir es cómo se arma la frase.
             */
            $armar = new \ReflectionMethod($analizador, 'mensaje_de_columna_ambigua');
            $armar->setAccessible(true);

            $mensajes[$etiqueta] = $armar->invokeArgs($analizador, [
                'PRECIOS',
                ['E', 'F'],
                [
                    ['system_property' => 'costo',  'excel_column_index' => 4, 'excel_column_letter' => 'E'],
                    ['system_property' => 'precio', 'excel_column_index' => 5, 'excel_column_letter' => 'F'],
                ],
            ]);
        }

        $this->assertStringContainsString('«PRECIOS» cubre las columnas E y F', $mensajes['articulos']);

        $this->assertSame(
            array_fill_keys(array_keys($mensajes), $mensajes['articulos']),
            $mensajes,
            'Los tres analizadores tienen que decir exactamente lo mismo: el modal es compartido.'
        );
    }

    /**
     * Un análisis completo de CLIENTES, con la llamada a Claude reemplazada por una respuesta
     * fija, tiene que devolver las seis claves del contrato con la SPA y mandarle a Claude el
     * encabezado con la fusión ya propagada (no el vacío).
     *
     * @return void
     */
    public function test_el_analisis_de_clientes_devuelve_hoja_encabezado_y_avisos()
    {
        $analizador = new AiClientAnalyzerConClaudeFalso(1);

        $analizador->respuesta = json_encode([
            'column_mapping' => [
                ['excel_column' => 'nombre',  'system_property' => 'nombre',       'confidence' => 0.9],
                ['excel_column' => 'PRECIOS', 'system_property' => 'saldo_actual', 'confidence' => 0.5],
                ['excel_column' => 'PRECIOS', 'system_property' => 'descripcion',  'confidence' => 0.5],
            ],
        ]);

        $resultado = $analizador->analyze($this->fixture('13_cabecera_fusionada.xlsx'), 'clientes.xlsx');

        $this->assertStringContainsString(
            'PRECIOS | PRECIOS',
            $analizador->ultimo_prompt,
            'A Claude se le seguía mandando la columna cubierta por la fusión con el nombre vacío.'
        );

        $this->assertSame([['indice' => 0, 'nombre' => 'Lista', 'filas' => 5]], $resultado['hojas']);
        $this->assertSame(['indice' => 0, 'nombre' => 'Lista'], $resultado['hoja_elegida']);

        $this->assertSame(1, $resultado['encabezado_detectado']['fila']);
        $this->assertSame('automatico', $resultado['encabezado_detectado']['origen']);
        $this->assertSame('alta', $resultado['encabezado_detectado']['confianza']);

        $this->assertSame([], $resultado['columnas_sin_nombre']);
        $this->assertSame(1, $resultado['fusiones_aplicadas']);

        $this->assertCount(1, $resultado['columnas_ambiguas']);
        $this->assertStringContainsString(
            'saldo_actual → E',
            $resultado['columnas_ambiguas'][0]['mensaje']
        );

        $this->assertSame(4, $resultado['row_count']);
    }

    /**
     * Lo mismo para PROVEEDORES. Es un test aparte y no un dataProvider a propósito: son dos
     * clases distintas con dos copias del código, y la única forma de que las dos queden
     * cubiertas es ejercitar las dos.
     *
     * @return void
     */
    public function test_el_analisis_de_proveedores_devuelve_hoja_encabezado_y_avisos()
    {
        $analizador = new AiProviderAnalyzerConClaudeFalso(1);

        $analizador->respuesta = json_encode([
            'column_mapping' => [
                ['excel_column' => 'nombre',  'system_property' => 'nombre',    'confidence' => 0.9],
                ['excel_column' => 'PRECIOS', 'system_property' => 'telefono',  'confidence' => 0.5],
                ['excel_column' => 'PRECIOS', 'system_property' => 'email',     'confidence' => 0.5],
            ],
        ]);

        $resultado = $analizador->analyze($this->fixture('13_cabecera_fusionada.xlsx'), 'proveedores.xlsx');

        $this->assertStringContainsString('PRECIOS | PRECIOS', $analizador->ultimo_prompt);

        $this->assertSame(['indice' => 0, 'nombre' => 'Lista'], $resultado['hoja_elegida']);
        $this->assertSame(1, $resultado['encabezado_detectado']['fila']);
        $this->assertSame(1, $resultado['fusiones_aplicadas']);
        $this->assertSame([], $resultado['columnas_sin_nombre']);
        $this->assertCount(1, $resultado['columnas_ambiguas']);
        $this->assertSame(4, $resultado['row_count']);
    }

    /**
     * Y la hoja elegida llega hasta el resultado por el mismo camino: el libro se lista una
     * sola vez y el índice se resuelve sobre ese listado (B9), así que si el espejo del
     * resolver se equivocara, el análisis de clientes leería otra hoja.
     *
     * @return void
     */
    public function test_el_analisis_de_clientes_respeta_la_hoja_pedida_por_nombre()
    {
        $analizador = new AiClientAnalyzerConClaudeFalso(1);

        $analizador->respuesta = json_encode([
            'column_mapping' => [
                ['excel_column' => 'Estas son notas internas del vendedor', 'system_property' => 'nombre', 'confidence' => 0.4],
            ],
        ]);

        $resultado = $analizador->analyze(
            $this->fixture('12_tres_hojas.xlsx'),
            'clientes.xlsx',
            ['hoja' => 0, 'hoja_nombre' => 'Notas']
        );

        /* El nombre gana sobre el índice: la hoja 0 es "Lista de precios". */
        $this->assertSame(['indice' => 1, 'nombre' => 'Notas'], $resultado['hoja_elegida']);
        $this->assertSame(2, $resultado['row_count']);

        $this->assertSame(
            [
                ['indice' => 0, 'nombre' => 'Lista de precios', 'filas' => 8],
                ['indice' => 1, 'nombre' => 'Notas', 'filas' => 3],
                ['indice' => 2, 'nombre' => 'Resumen', 'filas' => 2],
            ],
            $resultado['hojas']
        );
    }

    /* ---------------------------------------------------------------------
     * B9 — el libro se lista una sola vez, y el espejo del resolver no miente.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 ESTE TEST ES LA CONTRAPARTE DE UNA DUPLICACIÓN A SABIENDAS.
     *
     * analyze() lista el libro una sola vez y resuelve el índice sobre ese array con
     * resolver_indice_sobre(), en vez de llamar a ExcelWorkbookReader::resolver_indice(), que
     * vuelve a listar por adentro (medido: 196ms + 173ms tirados en todo archivo, aunque tenga
     * una sola hoja). El precio de esa optimización es la MISMA regla escrita en dos lugares,
     * que es la clase de error que ya mordió tres veces en esta misión: acá se comparan las dos
     * respuestas caso por caso para que no se puedan separar sin que se ponga rojo.
     *
     * @dataProvider casos_de_resolucion_de_hoja
     *
     * @param  int|null    $hoja
     * @param  string|null $hoja_nombre
     * @return void
     */
    public function test_el_espejo_del_indice_de_hoja_da_lo_mismo_que_el_reader($hoja, $hoja_nombre)
    {
        $archivo = $this->fixture('12_tres_hojas.xlsx');

        $del_reader = ExcelWorkbookReader::resolver_indice($archivo, $hoja, $hoja_nombre);
        $hojas      = ExcelWorkbookReader::listar_hojas($archivo);

        foreach ($this->analizadores() as $etiqueta => $analizador) {
            $reflexion = new \ReflectionMethod($analizador, 'resolver_indice_sobre');
            $reflexion->setAccessible(true);

            $this->assertSame(
                $del_reader,
                $reflexion->invokeArgs($analizador, [$hojas, $hoja, $hoja_nombre]),
                'El espejo del analizador de ' . $etiqueta . ' se separó de ExcelWorkbookReader::resolver_indice().'
            );
        }
    }

    /**
     * @return array
     */
    public function casos_de_resolucion_de_hoja()
    {
        return [
            'sin nada'                  => [null, null],
            'indice del medio'          => [1, null],
            'indice ultimo'             => [2, null],
            'indice fuera de rango'     => [9, null],
            'indice como texto'         => ['1', null],
            'nombre exacto'             => [null, 'Notas'],
            'el nombre le gana al indice' => [0, 'Notas'],
            'nombre inexistente'        => [null, 'No existe'],
            'nombre y otro indice'      => [1, 'Resumen'],
        ];
    }

    /* ---------------------------------------------------------------------
     * B5 por el camino de la fila de encabezado corregida a mano.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 LA ALERTA AMARILLA FALSA SEGUÍA VIVA CUANDO EL USUARIO CORREGÍA LA FILA A MANO.
     *
     * ExcelHeaderDetector aprendió a distinguir "columna que los datos usan de verdad" de
     * "celda suelta perdida a la derecha", pero AiExcelAnalyzer tenía su propia copia del
     * cálculo de ancho —la última columna con contenido en cualquier fila de la ventana— y esa
     * copia sólo corre por este camino: el de la fila elegida a mano en el paso 1. O sea que el
     * mismo archivo daba una alerta u otra según quién hubiera elegido la fila.
     *
     * El archivo de este test es el caso completo: un título arriba, un encabezado con un
     * nombre REPETIDO (que por eso no califica como candidato y hace que el usuario TENGA que
     * corregir la fila a mano), y una nota suelta en J4, de esas que trae media planilla de
     * proveedor.
     *
     * Con la copia vieja, elegir la fila 2 daba ancho 10 y
     * columnas_sin_nombre = [4, 5, 6, 7, 8, 9]: "Las columnas E, F, G, H, I, J no tienen
     * nombre en el encabezado", sobre un archivo perfecto. A la tercera alerta amarilla sin
     * motivo el usuario deja de leer las alertas amarillas, incluida la de costo y precio.
     *
     * @return void
     */
    public function test_la_fila_elegida_a_mano_usa_el_criterio_del_detector()
    {
        $archivo = $this->archivo_con_nota_suelta();

        /* La detección automática no encuentra candidata: el encabezado repite "PRECIO". */
        $automatico = ExcelHeaderDetector::detectar_en($archivo, 0);

        $this->assertSame(1, $automatico['fila']);
        $this->assertSame('sin_candidata_clara', $automatico['motivo']);

        /* El usuario corrige a la fila 2 en el paso 1 del modal. */
        $encabezado = $this->invocar_analyzer('resolver_encabezado', [$archivo, 0, 2]);

        $this->assertSame(2, $encabezado['fila']);
        $this->assertSame('usuario', $encabezado['origen']);

        $this->assertSame(
            ['codigo', 'nombre', 'PRECIO', 'PRECIO'],
            $encabezado['columnas'],
            'El encabezado se corta en la última columna que los datos usan, no en la nota de J4.'
        );

        $this->assertSame(
            [],
            $encabezado['columnas_sin_nombre'],
            'La nota suelta de J4 no puede inventar seis columnas sin nombre.'
        );
    }

    /**
     * El nombre repetido de ese mismo archivo —"PRECIO" en C y en D, sin ninguna fusión de por
     * medio— también dispara el aviso: el bug de repartir dos propiedades sobre la misma
     * columna es más viejo que las celdas fusionadas.
     *
     * @return void
     */
    public function test_un_nombre_repetido_sin_fusiones_tambien_avisa()
    {
        $encabezado = $this->invocar_analyzer('resolver_encabezado', [$this->archivo_con_nota_suelta(), 0, 2]);

        $mapeo = $this->invocar_analyzer('enrich_column_mapping', [
            [
                ['excel_column' => 'PRECIO', 'system_property' => 'costo',  'confidence' => 0.9],
                ['excel_column' => 'PRECIO', 'system_property' => 'precio', 'confidence' => 0.9],
            ],
            $encabezado['columnas'],
        ]);

        $this->assertSame(['costo' => 'C', 'precio' => 'D'], $this->letras_por_propiedad($mapeo));

        $avisos = $this->invocar_analyzer('detectar_columnas_ambiguas', [$mapeo, $encabezado['columnas']]);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('«PRECIO» cubre las columnas C y D', $avisos[0]['mensaje']);
    }

    /**
     * Escribe, en el directorio temporal del sistema, la planilla del test de la nota suelta.
     *
     * No va a tests/Import/fixtures/ a propósito: los fixtures del repo son la documentación
     * viva de los casos de importación y los genera un script aparte; este archivo existe sólo
     * para este test y se borra en tearDown().
     *
     *   A1  "LISTA DE PRECIOS AGOSTO 2026"   (título solo, no es candidato)
     *   A2  codigo | nombre | PRECIO | PRECIO  (el encabezado, con un nombre repetido)
     *   A3..A6  4 filas de datos
     *   J4  "Promo hasta fin de mes"          (la nota suelta que inflaba el ancho)
     *
     * @return string  Ruta absoluta al archivo
     */
    protected function archivo_con_nota_suelta()
    {
        if (!is_null($this->archivo_temporal) && is_file($this->archivo_temporal)) {
            return $this->archivo_temporal;
        }

        $this->archivo_temporal = sys_get_temp_dir() . '/import_nota_suelta_' . getmypid() . '.xlsx';

        $libro = new Spreadsheet();
        $hoja  = $libro->getActiveSheet();

        $hoja->setCellValueExplicit('A1', 'LISTA DE PRECIOS AGOSTO 2026', DataType::TYPE_STRING);

        $hoja->setCellValueExplicit('A2', 'codigo', DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('B2', 'nombre', DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('C2', 'PRECIO', DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('D2', 'PRECIO', DataType::TYPE_STRING);

        $numero_fila = 3;

        foreach ([100.0, 300.0, 500.0, 700.0] as $costo) {
            $hoja->setCellValueExplicit('A' . $numero_fila, 'PC-' . $numero_fila, DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('B' . $numero_fila, 'Articulo ' . $numero_fila, DataType::TYPE_STRING);
            $hoja->setCellValue('C' . $numero_fila, $costo);
            $hoja->setCellValue('D' . $numero_fila, $costo * 2);

            $numero_fila++;
        }

        $hoja->setCellValueExplicit('J4', 'Promo hasta fin de mes', DataType::TYPE_STRING);

        $writer = new Xlsx($libro);
        $writer->save($this->archivo_temporal);
        $libro->disconnectWorksheets();

        return $this->archivo_temporal;
    }

    /**
     * Los tres analizadores, con la etiqueta con que se los nombra en los mensajes de error.
     *
     * @return array
     */
    protected function analizadores()
    {
        return [
            'articulos'   => new AiExcelAnalyzer(1),
            'clientes'    => new AiClientAnalyzer(1),
            'proveedores' => new AiProviderAnalyzer(1),
        ];
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (!is_null($this->archivo_temporal) && is_file($this->archivo_temporal)) {
            @unlink($this->archivo_temporal);
        }

        $this->archivo_temporal = null;

        parent::tearDown();
    }
}

/**
 * AiClientAnalyzer con la llamada HTTP a Claude reemplazada por una respuesta fija.
 *
 * Es la única forma de ejercitar analyze() de punta a punta —que es donde viven las claves del
 * contrato con la SPA— sin salir a la red. Guarda además el prompt, que es donde se ve si a
 * Claude le llegó el encabezado con la fusión propagada o con el nombre vacío.
 */
class AiClientAnalyzerConClaudeFalso extends AiClientAnalyzer
{
    /** @var string */
    public $respuesta = '';

    /** @var string */
    public $ultimo_prompt = '';

    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $this->ultimo_prompt = $prompt;

        return $this->respuesta;
    }
}

/**
 * Lo mismo para proveedores. Ver AiClientAnalyzerConClaudeFalso.
 */
class AiProviderAnalyzerConClaudeFalso extends AiProviderAnalyzer
{
    /** @var string */
    public $respuesta = '';

    /** @var string */
    public $ultimo_prompt = '';

    /**
     * @param  string $prompt
     * @return string
     */
    protected function call_claude(string $prompt): string
    {
        $this->ultimo_prompt = $prompt;

        return $this->respuesta;
    }
}
