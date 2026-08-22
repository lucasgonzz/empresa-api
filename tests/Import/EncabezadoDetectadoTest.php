<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\excel\ExcelHeaderDetector;
use App\Http\Controllers\Helpers\import\excel\ExcelWorkbookReader;
use Tests\TestCase;

/**
 * Detección de la fila de encabezado y celdas fusionadas (unidad 1 de la misión de
 * importación de Excel).
 *
 * Los dos defectos que cubre:
 *
 *   - Cabecera fusionada: Excel no escribe la celda cubierta por una fusión, así que
 *     "PRECIOS" sobre E1:F1 dejaba la columna F sin nombre y el mapeo perdía una columna
 *     entera.
 *   - Encabezado corrido: con un título y la razón social arriba de la tabla, la regla
 *     vieja tomaba el título como encabezado. Mapeaba las 8 columnas contra una sola celda
 *     y contaba título y razón social como filas de datos. Todo en silencio.
 *
 * No extiende ImportTestCase: acá no se toca la base ni el tenant sembrado, sólo se leen
 * archivos. Ver el comentario equivalente en CadenaIdentificacionTest.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class EncabezadoDetectadoTest extends TestCase
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

    /**
     * Cuatro de los tests de este archivo prueban el efecto de la regla nueva sobre
     * AiExcelAnalyzer, que es de la UNIDAD 2 de esta misión, no de la unidad 1. La partición
     * de la misión pone los tests acá (este archivo es exclusivo de la unidad 1) y el código
     * allá, así que hasta que la unidad 2 aterrice esos cuatro no pueden pasar.
     *
     * Se saltean en vez de quedar en rojo —un rojo nuevo en la suite es indistinguible de
     * una regresión y le arruina la línea de base a las otras unidades—, y se prenden solos:
     * el marcador es el tercer parámetro `array $opciones = []` que la unidad 2 le agrega a
     * `analyze()` (§2 del plan). Nadie tiene que volver a editar este archivo.
     *
     * 🔴 NINGUNA ASERCIÓN DE ESOS TESTS ESTÁ AFLOJADA. Lo que se saltea es el test entero,
     * y sólo mientras su dependencia no está en el árbol.
     *
     * @return void
     */
    protected function saltear_si_la_unidad_2_no_aterrizo()
    {
        $metodo = new \ReflectionMethod(AiExcelAnalyzer::class, 'analyze');

        if ($metodo->getNumberOfParameters() < 3) {
            $this->markTestSkipped(
                'Depende de la unidad 2 (AiExcelAnalyzer con $opciones). Se activa solo cuando esa unidad esté mergeada.'
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Test 3 — cabecera fusionada.
     * ------------------------------------------------------------------- */

    /**
     * "PRECIOS" fusionado sobre E1:F1 tiene que llegar como nombre de las DOS columnas.
     *
     * Ojo con el motivo: una cabecera con dos nombres iguales no puede ser candidata (falla
     * "todas distintas"), así que la regla cae al fallback de la regla vieja —primera fila
     * con contenido— y devuelve confianza baja. Está bien y es deliberado: elige la misma
     * fila que elegía antes, y la confianza baja es lo que hace que la SPA muestre el campo
     * de fila de encabezado abierto para que lo revise el usuario.
     *
     * @return void
     */
    public function test_una_cabecera_fusionada_sobre_dos_columnas_llega_con_los_dos_nombres()
    {
        $resultado = ExcelHeaderDetector::detectar_en($this->fixture('13_cabecera_fusionada.xlsx'), 0);

        $this->assertSame(1, $resultado['fila']);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'PRECIOS', 'PRECIOS'],
            $resultado['columnas']
        );

        foreach ($resultado['columnas'] as $indice => $columna) {
            $this->assertNotSame('', $columna, 'La columna ' . $indice . ' quedó sin nombre.');
        }

        $this->assertSame([], $resultado['columnas_sin_nombre']);
        $this->assertSame(1, $resultado['fusiones_aplicadas']);
    }

    /**
     * El riesgo más caro de la misión (T3): después de propagar "PRECIOS" sobre E y F, dos
     * ítems del mapeo con el mismo excel_column tienen que recibir índices DISTINTOS.
     *
     * Si los dos reciben el 4, costo y precio del catálogo de un cliente salen de la misma
     * celda y no hay ningún error que lo denuncie.
     *
     * @return void
     */
    public function test_dos_columnas_con_el_mismo_nombre_no_apuntan_al_mismo_indice()
    {
        $this->saltear_si_la_unidad_2_no_aterrizo();

        $encabezado = ExcelHeaderDetector::detectar_en($this->fixture('13_cabecera_fusionada.xlsx'), 0);

        $mapeo = [
            ['excel_column' => 'codigo_de_barras',    'system_property' => 'codigo_de_barras',    'confidence' => 1],
            ['excel_column' => 'sku',                 'system_property' => 'sku',                 'confidence' => 1],
            ['excel_column' => 'codigo_de_proveedor', 'system_property' => 'codigo_de_proveedor', 'confidence' => 1],
            ['excel_column' => 'nombre',              'system_property' => 'nombre',              'confidence' => 1],
            ['excel_column' => 'PRECIOS',             'system_property' => 'costo',               'confidence' => 1],
            ['excel_column' => 'PRECIOS',             'system_property' => 'precio',              'confidence' => 1],
        ];

        $enriquecido = $this->invocar_analyzer('enrich_column_mapping', [$mapeo, $encabezado['columnas']]);

        $indices_por_propiedad = [];

        foreach ($enriquecido as $item) {
            $indices_por_propiedad[$item['system_property']] = $item['excel_column_index'];
        }

        $this->assertSame(4, $indices_por_propiedad['costo'], 'El costo tiene que salir de la columna E.');
        $this->assertSame(5, $indices_por_propiedad['precio'], 'El precio tiene que salir de la columna F, no de la E.');
    }

    /**
     * Si las fusiones no se pueden leer (zip corrupto, o el archivo simplemente no las
     * declara), el encabezado queda con un agujero. No se puede adivinar el nombre, pero
     * SÍ hay que avisar: la columna sin nombre viaja en `columnas_sin_nombre` y la SPA la
     * muestra como alerta amarilla.
     *
     * La ventana de abajo es, celda por celda, lo que devuelve leer_ventana() sobre
     * 13_cabecera_fusionada.xlsx cuando ExcelSheetInspector::fusiones() devuelve null: F1
     * no existe en el XML del archivo (Excel no escribe la celda cubierta por una fusión),
     * así que la fila 1 tiene 5 valores y las de datos 6.
     *
     * @return void
     */
    public function test_si_no_se_pueden_leer_las_fusiones_se_avisa_que_hay_columnas_sin_nombre()
    {
        $ventana_sin_propagar = [
            1 => ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'PRECIOS'],
            2 => ['7799401', 'SKU-F-1', 'PC-F-1', 'Articulo fusion 1', '100', '200'],
            3 => ['7799402', 'SKU-F-2', 'PC-F-2', 'Articulo fusion 2', '300', '600'],
            4 => ['7799403', 'SKU-F-3', 'PC-F-3', 'Articulo fusion 3', '500', '900'],
            5 => ['7799404', 'SKU-F-4', 'PC-F-4', 'Articulo fusion 4', '700', '1400'],
        ];

        $resultado = ExcelHeaderDetector::detectar($ventana_sin_propagar);

        $this->assertSame([5], $resultado['columnas_sin_nombre']);
        $this->assertSame('', $resultado['columnas'][5]);
    }

    /* ---------------------------------------------------------------------
     * Test 4 — encabezado corrido.
     * ------------------------------------------------------------------- */

    /**
     * Con título y razón social arriba, el encabezado de verdad está en la fila 4.
     *
     * @return void
     */
    public function test_con_titulo_y_razon_social_arriba_se_detecta_el_encabezado_real()
    {
        $resultado = ExcelHeaderDetector::detectar_en($this->fixture('14_encabezado_corrido.xlsx'), 0);

        $this->assertSame(4, $resultado['fila']);
        $this->assertSame('encabezado_corrido', $resultado['motivo']);
        $this->assertSame('alta', $resultado['confianza']);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva'],
            $resultado['columnas']
        );
    }

    /**
     * El mapeo se arma con los nombres reales de la fila 4, no con el título.
     *
     * @return void
     */
    public function test_con_el_encabezado_corrido_el_mapeo_se_arma_con_los_nombres_reales()
    {
        $this->saltear_si_la_unidad_2_no_aterrizo();

        $muestra = $this->invocar_analyzer('read_sample_rows', [$this->fixture('14_encabezado_corrido.xlsx')]);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva'],
            $muestra['headers']
        );

        $this->assertNotContains('LISTA DE PRECIOS AGOSTO 2026', $muestra['headers']);
    }

    /**
     * El que pincha la trampa T1: el conteo de filas de datos tiene que dar 5 (las filas
     * 5 a 9), no 8. Título, razón social y encabezado no son datos.
     *
     * @return void
     */
    public function test_con_el_encabezado_corrido_el_conteo_de_filas_no_cuenta_el_titulo()
    {
        $this->saltear_si_la_unidad_2_no_aterrizo();

        $filas = $this->invocar_analyzer('count_data_rows', [$this->fixture('14_encabezado_corrido.xlsx')]);

        $this->assertSame(5, $filas);
    }

    /* ---------------------------------------------------------------------
     * Test 5 — encabezado en la fila 1: el caso normal no cambia.
     * ------------------------------------------------------------------- */

    /**
     * Sobre el fixture existente 01_codigos_de_proveedor.xlsx (que NO se toca): la regla
     * nueva sigue eligiendo la fila 1 y la lectura de muestra devuelve exactamente lo mismo
     * que devolvía antes de la misión.
     *
     * Los valores literales de abajo son los que ya producía el código de producción antes
     * de esta misión, medidos sobre el archivo: si alguno cambia, cambió el comportamiento
     * del caso normal, que es lo único que esta misión no puede tocar.
     *
     * @return void
     */
    public function test_con_el_encabezado_en_la_fila_1_se_sigue_eligiendo_la_fila_1()
    {
        $archivo = $this->fixture('01_codigos_de_proveedor.xlsx');

        $resultado = ExcelHeaderDetector::detectar_en($archivo, 0);

        $this->assertSame(1, $resultado['fila']);
        $this->assertSame('primera_fila_con_contenido', $resultado['motivo']);
        $this->assertSame('alta', $resultado['confianza']);

        $muestra = $this->invocar_analyzer('read_sample_rows', [$archivo]);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva'],
            $muestra['headers']
        );

        $this->assertSame(
            [
                ['', 'SKU-NUEVO-UNICO', 'PC-100',   'Art unico prov A EDITADO',  '111', '222',  '11', '21'],
                ['', 'SKU-NUEVO-DUP',   'PC-DUP',   'Art PC repetido EDITADO',   '333', '444',  '33', '21'],
                ['', '',                'PC-CROSS', 'Art PC cruzado EDITADO',    '555', '666',  '55', '21'],
                ['', '',                'PC-NUEVO', 'Articulo nuevo por PC',     '777', '888',  '77', '21'],
                ['', '',                'PC-1500',  'Art solo prov C EDITADO',   '999', '1110', '99', '21'],
                ['', '',                'S/N',      'Placeholder SN sin codigo', '100', '200',  '5',  '21'],
                ['', '',                '-',        'Placeholder guion sin cod', '120', '240',  '6',  '21'],
            ],
            $muestra['rows']
        );
    }

    /* ---------------------------------------------------------------------
     * Test 6 — .xls viejo.
     * ------------------------------------------------------------------- */

    /**
     * Un .xls viejo (BIFF, no es un zip) no se puede leer, y el mensaje que ve el usuario
     * tiene que decirle qué hacer SIN filtrar la ruta absoluta del servidor.
     *
     * La IOException de OpenSpout dice "Could not open C:\...\storage\app\...\archivo.xls
     * for reading!". Ese texto subía entero hasta la pantalla del cliente.
     *
     * @return void
     */
    public function test_un_xls_viejo_falla_con_un_mensaje_claro_y_sin_la_ruta_del_servidor()
    {
        $archivo = $this->fixture('16_viejo.xls');

        $this->assertFileExists($archivo);

        try {
            ExcelWorkbookReader::abrir($archivo, 0, true);

            $this->fail('Abrir un .xls viejo tenía que lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();

            $this->assertStringContainsString('.xlsx', $mensaje, 'El mensaje tiene que decirle al usuario qué hacer.');

            $this->assertStringNotContainsString($archivo, $mensaje);
            $this->assertStringNotContainsString('Could not open', $mensaje);
            $this->assertStringNotContainsString(':\\', $mensaje);
            $this->assertStringNotContainsString('/var/', $mensaje);
            $this->assertStringNotContainsString('16_viejo', $mensaje);
        }
    }

    /**
     * Y el análisis completo tiene que llegar al usuario como \RuntimeException, no como la
     * IOException de OpenSpout: es lo que hace que RunExcelAnalysisJob caiga en el
     * catch (\RuntimeException) y guarde el mensaje limpio en vez de concatenar la ruta.
     *
     * @return void
     */
    public function test_el_analisis_de_un_xls_viejo_llega_al_usuario_como_runtime_exception()
    {
        $this->saltear_si_la_unidad_2_no_aterrizo();

        $analyzer = new AiExcelAnalyzer(1);

        $this->expectException(\RuntimeException::class);

        $analyzer->analyze($this->fixture('16_viejo.xls'), '16_viejo.xls');
    }
}
