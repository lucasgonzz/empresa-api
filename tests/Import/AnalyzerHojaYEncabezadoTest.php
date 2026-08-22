<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
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
}
