<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\ExcelNumericFormatStats;
use Tests\TestCase;

/**
 * ExcelNumericFormatStats::analyze() y el aviso de "cómo vamos a leer los números" de la
 * ventana de importación con IA: las claves nuevas 'lecturas' y 'hay_lecturas'.
 *
 * Usa el fixture 25_formatos_numericos.xlsx (ver generar_formatos_numericos.php, que dice el
 * TIPO de cada celda). Columnas: A nombre, B costo, C precio, D stock; fila 1 = cabecera.
 *
 * Lo que fija:
 *   1. 'lecturas' clasifica las celdas de TEXTO con coma, miles con espacio/NBSP/apóstrofo o
 *      no interpretables, con conteo por tipo y ejemplos con el resultado del parseo real.
 *   2. El punto solo NO entra en 'lecturas' (sigue yendo únicamente por 'columnas') y una celda
 *      numérica nativa no se cuenta como texto.
 *   3. 'columnas' y 'hay_ambiguedad' quedan idénticos a lo que daban antes de agregar las lecturas
 *      (contrato hacia atrás: el SPA viejo dibuja 'columnas' con la tabla de números con punto).
 *
 * No extiende ImportTestCase: no se siembra ni se escribe nada, solo se lee un archivo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class FormatosNumericosLecturasTest extends TestCase
{
    /**
     * Corre analyze() sobre el fixture con las tres columnas numéricas mapeadas.
     *
     * @param  int $max_ejemplos
     * @return array
     */
    protected function analizar($max_ejemplos = 6)
    {
        return ExcelNumericFormatStats::analyze(
            __DIR__ . '/fixtures/25_formatos_numericos.xlsx',
            ['cost' => 1, 'price' => 2, 'stock' => 3],
            ['cost' => 'costo', 'price' => 'precio', 'stock' => 'stock'],
            $max_ejemplos
        );
    }

    public function test_lecturas_de_la_columna_costo_con_conteos_y_tipos()
    {
        $resultado = $this->analizar();

        $this->assertTrue($resultado['hay_lecturas']);

        /* Solo costo: precio es todo punto solo y stock no tiene nada con separadores. */
        $this->assertCount(1, $resultado['lecturas']);

        $costo = $resultado['lecturas'][0];

        $this->assertSame('cost', $costo['campo']);
        $this->assertSame('costo', $costo['nombre_columna_excel']);

        /* 10 celdas de texto no vacías: la numérica (99.5) y la vacía (fila 12) no cuentan. */
        $this->assertSame(10, $costo['celdas_texto']);

        $this->assertEquals([
            'coma_decimal'             => 1, // 12,5
            'miles_punto_decimal_coma' => 2, // 1.234,56 y $ 1.000,50
            'miles_coma_decimal_punto' => 1, // 1,234.56
            'miles_coma'               => 1, // 1,234,567
            'miles_espacio'            => 2, // 1 234,50 y 1<NBSP>000
            'no_interpretable'         => 2, // 1.5,3 y abc
        ], $costo['por_tipo']);

        /* De las 10 celdas de texto, "2.500" (solo punto) es la única que no aparece en ningún tipo: 10 - 1 = 9. */
        $this->assertSame(9, array_sum($costo['por_tipo']));
    }

    public function test_ejemplos_con_resultado_del_parseo_real_y_orden_por_fila()
    {
        $costo = $this->analizar()['lecturas'][0];

        $this->assertCount(6, $costo['ejemplos']);

        $esperados = [
            [2, '12,5', 'coma_decimal', true, '12.5'],
            [3, '1.234,56', 'miles_punto_decimal_coma', true, '1234.56'],
            [4, '1,234.56', 'miles_coma_decimal_punto', true, '1234.56'],
            [5, '1,234,567', 'miles_coma', true, '1234567'],
            [6, '1 234,50', 'miles_espacio', true, '1234.5'],
            [7, '1.5,3', 'no_interpretable', false, null],
        ];

        foreach ($esperados as $i => $esperado) {
            $ejemplo = $costo['ejemplos'][$i];

            $this->assertSame($esperado[0], $ejemplo['fila'], "fila del ejemplo {$i}");
            $this->assertSame($esperado[1], $ejemplo['original'], "original del ejemplo {$i}");
            $this->assertSame($esperado[2], $ejemplo['tipo'], "tipo del ejemplo {$i}");
            $this->assertSame($esperado[3], $ejemplo['interpretable'], "interpretable del ejemplo {$i}");
            $this->assertSame($esperado[4], $ejemplo['resultado'], "resultado del ejemplo {$i}");
            $this->assertSame(
                ['fila', 'original', 'tipo', 'interpretable', 'resultado'],
                array_keys($ejemplo),
                "claves del ejemplo {$i}"
            );
        }
    }

    public function test_los_ejemplos_se_reparten_entre_tipos_y_respetan_el_maximo()
    {
        /*
         * Con máximo 3 entran el primer ejemplo de los 3 primeros tipos por orden de aparición
         * (filas 2, 3 y 4), no los 3 primeros de un mismo tipo, y salen ordenados por fila.
         */
        $costo = $this->analizar(3)['lecturas'][0];

        $this->assertSame([2, 3, 4], array_column($costo['ejemplos'], 'fila'));

        /* Con máximo 8 se completan los tipos con segundo ejemplo, sin repetir ninguno, en orden de fila. */
        $costo = $this->analizar(8)['lecturas'][0];
        $filas = array_column($costo['ejemplos'], 'fila');

        $this->assertSame([2, 3, 4, 5, 6, 7, 10, 11], $filas);
        $this->assertSame(array_unique($filas), $filas);

        /* Y con máximo 10 aparece 'abc' (fila 13), la última celda de texto no vacía con lectura. */
        $filas = array_column($this->analizar(10)['lecturas'][0]['ejemplos'], 'fila');
        $this->assertSame([2, 3, 4, 5, 6, 7, 10, 11, 13], $filas);
    }

    public function test_el_nbsp_y_la_moneda_se_leen_bien_en_los_ejemplos()
    {
        $ejemplos = $this->analizar(10)['lecturas'][0]['ejemplos'];
        $por_fila = [];
        foreach ($ejemplos as $ejemplo) {
            $por_fila[$ejemplo['fila']] = $ejemplo;
        }

        $this->assertSame('$ 1.000,50', $por_fila[10]['original']);
        $this->assertSame('miles_punto_decimal_coma', $por_fila[10]['tipo']);
        $this->assertSame('1000.5', $por_fila[10]['resultado']);

        $this->assertSame("1\xC2\xA0000", $por_fila[11]['original']);
        $this->assertSame('miles_espacio', $por_fila[11]['tipo']);
        $this->assertSame('1000', $por_fila[11]['resultado']);

        $this->assertSame('abc', $por_fila[13]['original']);
        $this->assertSame('no_interpretable', $por_fila[13]['tipo']);
        $this->assertFalse($por_fila[13]['interpretable']);
        $this->assertNull($por_fila[13]['resultado']);
    }

    public function test_el_punto_solo_no_entra_en_lecturas_pero_si_en_columnas()
    {
        $resultado = $this->analizar();

        /* Precio (todo punto solo) y stock (nada con separadores) no aparecen en lecturas. */
        $campos = array_column($resultado['lecturas'], 'campo');
        $this->assertSame(['cost'], $campos);

        /* Pero el punto solo sí sigue yendo por 'columnas'. */
        $this->assertArrayHasKey('price', $resultado['columnas']);
        $this->assertArrayHasKey('cost', $resultado['columnas']);
        $this->assertArrayNotHasKey('stock', $resultado['columnas']);
    }

    /**
     * 'columnas' y 'hay_ambiguedad' tienen que ser EXACTAMENTE lo que daba analyze() antes de
     * agregar las lecturas para este mismo archivo. Los valores de abajo se midieron corriendo la
     * versión anterior de la clase contra este fixture.
     */
    public function test_columnas_y_hay_ambiguedad_quedan_identicos_a_antes()
    {
        $resultado = $this->analizar();

        $this->assertTrue($resultado['hay_ambiguedad']);

        $this->assertSame([
            'cost' => [
                'campo' => 'cost',
                'nombre_columna_excel' => 'costo',
                'total_celdas' => 11,
                'celdas_con_punto' => 5,
                'interpretados_miles' => 1,
                'interpretados_decimal' => 0,
                'nivel_de_riesgo' => 'medio',
                'ejemplos' => [
                    ['fila' => 8, 'original' => '2.500', 'interpretacion' => 'miles', 'resultado' => '2500'],
                ],
            ],
            'price' => [
                'campo' => 'price',
                'nombre_columna_excel' => 'precio',
                'total_celdas' => 3,
                'celdas_con_punto' => 2,
                'interpretados_miles' => 1,
                'interpretados_decimal' => 1,
                'nivel_de_riesgo' => 'alto',
                'ejemplos' => [
                    ['fila' => 2, 'original' => '2.500', 'interpretacion' => 'miles', 'resultado' => '2500'],
                    ['fila' => 3, 'original' => '3330.95', 'interpretacion' => 'decimal', 'resultado' => '3330.95'],
                ],
            ],
        ], $resultado['columnas']);
    }

    public function test_sin_columnas_numericas_el_resultado_vacio_trae_las_claves_nuevas()
    {
        $resultado = ExcelNumericFormatStats::analyze(__DIR__ . '/fixtures/25_formatos_numericos.xlsx', []);

        $this->assertSame([
            'columnas' => [],
            'hay_ambiguedad' => false,
            'lecturas' => [],
            'hay_lecturas' => false,
        ], $resultado);
    }

    public function test_un_archivo_ilegible_devuelve_el_resultado_vacio_con_las_claves_nuevas()
    {
        $resultado = ExcelNumericFormatStats::analyze(__DIR__ . '/fixtures/no_existe.xlsx', ['cost' => 1]);

        $this->assertSame([
            'columnas' => [],
            'hay_ambiguedad' => false,
            'lecturas' => [],
            'hay_lecturas' => false,
        ], $resultado);
    }

    public function test_una_columna_sin_coma_ni_espacio_no_genera_lecturas()
    {
        /* Solo la columna de precio (punto solo + un número nativo): sin lecturas, con ambigüedad. */
        $resultado = ExcelNumericFormatStats::analyze(
            __DIR__ . '/fixtures/25_formatos_numericos.xlsx',
            ['price' => 2],
            ['price' => 'precio']
        );

        $this->assertFalse($resultado['hay_lecturas']);
        $this->assertSame([], $resultado['lecturas']);
        $this->assertTrue($resultado['hay_ambiguedad']);
    }

    /**
     * El resultado de un ejemplo nunca sale en notación científica: "0,00001" tiene que viajar
     * como "0.00001" y no como "1.0E-5", porque la pantalla lo muestra tal cual viene.
     *
     * @dataProvider casos_de_resultado_sin_notacion_cientifica
     */
    public function test_el_resultado_no_sale_en_notacion_cientifica($original, $esperado)
    {
        $metodo = new \ReflectionMethod(ExcelNumericFormatStats::class, 'clasificar_lectura');
        $metodo->setAccessible(true);

        $lectura = $metodo->invoke(null, $original);

        $this->assertTrue($lectura['interpretable']);
        $this->assertSame($esperado, $lectura['resultado']);
    }

    public function casos_de_resultado_sin_notacion_cientifica()
    {
        return [
            'costo chico'           => ['0,00001',      '0.00001'],
            'seis decimales'        => ['0,000123',     '0.000123'],
            'decimal con ceros'     => ['12,50',        '12.5'],
            'entero con miles'      => ['1.234.567,00', '1234567'],
            'coma y punto'          => ['1,234.56',     '1234.56'],
            'negativo'              => ['-1.234,5',     '-1234.5'],
        ];
    }
}
