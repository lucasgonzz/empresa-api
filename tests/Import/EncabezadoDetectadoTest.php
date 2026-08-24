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
     * 🔴 Y TIENE QUE GANAR POR LA REGLA, NO DE CASUALIDAD. Al principio este caso devolvía
     * `sin_candidata_clara` con confianza baja: el duplicado que deja la propagación sacaba
     * a la fila 1 de candidata, no quedaba ninguna, y el fallback —"primera fila con
     * contenido"— la elegía igual. Acertaba la fila por el camino equivocado, y en cuanto el
     * mismo encabezado fusionado estaba corrido (fixture 17) el fallback devolvía la fila 1
     * y erraba. Por eso acá se asierta el motivo y la confianza, no sólo el número de fila.
     *
     * @return void
     */
    public function test_una_cabecera_fusionada_sobre_dos_columnas_llega_con_los_dos_nombres()
    {
        $resultado = ExcelHeaderDetector::detectar_en($this->fixture('13_cabecera_fusionada.xlsx'), 0);

        $this->assertSame(1, $resultado['fila']);
        $this->assertSame('primera_fila_con_contenido', $resultado['motivo']);
        $this->assertSame('alta', $resultado['confianza']);

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
     * 14_encabezado_corrido.xlsx trae, además del título y la razón social, el CUIT al lado
     * de la razón social y una fecha de vigencia al lado del título — que es como viene una
     * lista de proveedor de verdad. Sin esas dos celdas el fixture medía menos de lo que
     * decía medir: la regla pasaba este test y se caía con cualquier lista real, porque el
     * corte por fila de datos se disparaba con dos celdas si una era numérica. No las saques.
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

        $this->assertSame([], $resultado['columnas_sin_nombre']);
    }

    /**
     * El caso completo, el que se ve en cámara al filmar la demo con una lista de verdad:
     * título FUSIONADO sobre seis columnas, razón social con CUIT, fila de vigencia con
     * fecha, y el encabezado corrido Y ADEMÁS fusionado ("PRECIOS" sobre E4:F4).
     *
     * Los tres rasgos rompían la regla por motivos distintos y cada arreglo desactivaba al
     * otro, que es lo que hace que este fixture valga como test:
     *
     *   - el CUIT y la fecha disparaban el corte por fila de datos en la fila 2, así que el
     *     encabezado de la fila 4 no se miraba nunca;
     *   - el encabezado fusionado deja "PRECIOS" duplicado y eso lo sacaba de candidato;
     *   - el título fusionado, al propagarse, queda con seis celdas llenas y le ganaría por
     *     cantidad al encabezado si las celdas propagadas contaran como evidencia.
     *
     * Antes de arreglarlo, medido: `fila=1, sin_candidata_clara`. Con eso el mapeo se armaba
     * con `["LISTA DE PRECIOS AGOSTO 2026","","","","",""]`, cinco vacíos viajaban a Claude y
     * se importaban la razón social y la fila del encabezado como si fueran artículos.
     *
     * @return void
     */
    public function test_una_lista_de_proveedor_real_completa_se_detecta_entera()
    {
        $resultado = ExcelHeaderDetector::detectar_en($this->fixture('17_lista_proveedor_real.xlsx'), 0);

        $this->assertSame(4, $resultado['fila']);
        $this->assertSame('encabezado_corrido', $resultado['motivo']);
        $this->assertSame('alta', $resultado['confianza']);

        $this->assertSame(
            ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'PRECIOS', 'PRECIOS'],
            $resultado['columnas'],
            'El encabezado fusionado tiene que llegar con las dos columnas nombradas.'
        );

        $this->assertSame([], $resultado['columnas_sin_nombre']);

        $this->assertNotContains(
            'LISTA DE PRECIOS AGOSTO 2026',
            $resultado['columnas'],
            'El título fusionado se coló como nombre de columna.'
        );
    }

    /**
     * Los dos renglones de membrete que tumbaban la regla, uno por uno y aislados de todo lo
     * demás: la razón social con el CUIT al lado, y el "Vigencia desde:" con la fecha.
     *
     * Cada uno son dos celdas con un número o una fecha adentro. El corte por fila de datos
     * los tomaba por datos y frenaba la búsqueda antes de llegar al encabezado. Lo que los
     * separa de una fila de datos de verdad no es tener números: es cuántas columnas de la
     * tabla llenan.
     *
     * @dataProvider renglones_de_membrete
     *
     * @param  array  $renglon
     * @param  string $descripcion
     * @return void
     */
    public function test_un_renglon_de_membrete_no_corta_la_busqueda_del_encabezado($renglon, $descripcion)
    {
        $cabecera = ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva'];

        $ventana = [
            1 => ['LISTA DE PRECIOS AGOSTO 2026'],
            2 => $renglon,
            3 => [],
            4 => $cabecera,
        ];

        for ($fila = 5; $fila <= 9; $fila++) {
            $ventana[$fila] = ['779950' . $fila, 'SKU-C-' . $fila, 'PC-C-' . $fila, 'Articulo ' . $fila, '100', '200', '10', '21'];
        }

        $resultado = ExcelHeaderDetector::detectar($ventana);

        $this->assertSame(4, $resultado['fila'], 'Cortó en el membrete: ' . $descripcion);
        $this->assertSame('encabezado_corrido', $resultado['motivo']);
        $this->assertSame($cabecera, $resultado['columnas']);
    }

    /**
     * @return array
     */
    public function renglones_de_membrete()
    {
        return [
            'razon social + CUIT'  => [['Distribuidora Bianchi S.A.', '30712345679'], 'razón social con el CUIT al lado'],
            'vigencia + fecha ISO' => [['Vigencia desde:', '2026-08-01'], '"Vigencia desde:" con la fecha al lado'],
            'telefono'             => [['Tel:', '3541445566'], 'un teléfono'],
            'tres celdas'          => [['Lista', 'Vigencia', '2026-08-01'], 'tres celdas de membrete con una fecha'],
        ];
    }

    /* ---------------------------------------------------------------------
     * La alerta amarilla de columnas sin nombre: cuándo NO tiene que salir.
     * ------------------------------------------------------------------- */

    /**
     * 🔴 UNA ALERTA QUE SALE SIN MOTIVO ES PEOR QUE NO TENER ALERTA. A la tercera vez que la
     * amarilla aparece sobre un archivo perfecto, el usuario deja de leer las amarillas —
     * incluida la que sí importa.
     *
     * El ancho contra el que se decide "esta columna no tiene nombre" se medía contra
     * CUALQUIERA de las 20 primeras filas. Una nota suelta en J3 ("Promo hasta fin de mes"),
     * que aparece en media planilla de proveedor, corría el ancho hasta la columna J y la
     * alerta salía con `columnas_sin_nombre=[8,9]` sobre una planilla impecable. Con la nota
     * en AD2 eran 27 letras de columna en la alerta.
     *
     * @dataProvider notas_sueltas
     *
     * @param  int $columna_de_la_nota  0-based
     * @param  int $fila_de_la_nota     1-based
     * @return void
     */
    public function test_una_nota_suelta_a_la_derecha_no_dispara_la_alerta($columna_de_la_nota, $fila_de_la_nota)
    {
        $ventana = [
            1 => ['codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock_actual', 'iva'],
        ];

        for ($fila = 2; $fila <= 8; $fila++) {
            $ventana[$fila] = ['779950' . $fila, 'SKU-' . $fila, 'PC-' . $fila, 'Articulo ' . $fila, '100', '200', '10', '21'];
        }

        $ventana[$fila_de_la_nota][$columna_de_la_nota] = 'Promo hasta fin de mes';

        $resultado = ExcelHeaderDetector::detectar($ventana);

        $this->assertSame(1, $resultado['fila']);

        $this->assertSame(
            [],
            $resultado['columnas_sin_nombre'],
            'La nota suelta se contó como una columna que el encabezado no nombra.'
        );

        $this->assertCount(8, $resultado['columnas'], 'La tabla tiene 8 columnas, no más.');
    }

    /**
     * @return array
     */
    public function notas_sueltas()
    {
        return [
            'nota en J3'  => [9, 3],
            'nota en AD2' => [29, 2],
            'nota en I5'  => [8, 5],
        ];
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
     * 🔴 EL MENSAJE SE ASIERTA, NO ALCANZA CON EL TIPO. Antes esto era un `expectException`
     * pelado: pasaba con CUALQUIER \RuntimeException, incluida una que hubiera venido de
     * otro lado con la ruta del servidor adentro — que es exactamente lo que el test dice
     * estar evitando. Por eso se captura a mano y se revisa el texto.
     *
     * @return void
     */
    public function test_el_analisis_de_un_xls_viejo_llega_al_usuario_como_runtime_exception()
    {
        $this->saltear_si_la_unidad_2_no_aterrizo();

        $analyzer = new AiExcelAnalyzer(1);

        $archivo = $this->fixture('16_viejo.xls');

        try {
            $analyzer->analyze($archivo, '16_viejo.xls');

            $this->fail('Analizar un .xls viejo tenía que lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();

            $this->assertSame(
                ExcelWorkbookReader::MENSAJE_ARCHIVO_ILEGIBLE,
                $mensaje,
                'El usuario tiene que recibir el mensaje limpio, no cualquier RuntimeException.'
            );

            $this->assertStringNotContainsString($archivo, $mensaje);
            $this->assertStringNotContainsString(':\\', $mensaje);
            $this->assertStringNotContainsString('/var/', $mensaje);
        }
    }
}
