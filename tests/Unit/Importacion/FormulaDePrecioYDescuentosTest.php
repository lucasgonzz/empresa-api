<?php

namespace Tests\Unit\Importacion;

use App\Http\Controllers\Helpers\import\article\CodigosDeBarraRepetidos;
use App\Http\Controllers\Helpers\import\article\DescuentosDelExcel;
use App\Http\Controllers\Helpers\import\article\DetectorDeFormulaDePrecio;
use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Http\Controllers\Helpers\LocalImportHelper;
use PHPUnit\Framework\TestCase;

/**
 * Misión importacion-formula-precio (20/11/2026): lectura algorítmica (sin IA) del Excel de
 * artículos. Casos tomados de la lista real de un cliente (ferretería, 55.519 filas):
 *  - IVA «'21 %» con la comilla de texto de Excel adentro del valor;
 *  - descuentos negativos encadenados con «+» («-25+-30+-17»);
 *  - precio de venta = Importe Base × descuentos ÷ Eq.C × (1 + Beneficio) × (1 + IVA);
 *  - códigos de barras repetidos con códigos de proveedor distintos («0054» y «0054_1»).
 * No usa base ni red.
 */
class FormulaDePrecioYDescuentosTest extends TestCase
{
    /**
     * El IVA se limpia de comilla, «%», coma decimal y ceros de más.
     */
    public function test_el_iva_se_limpia_de_comilla_porcentaje_y_ceros()
    {
        $this->assertSame('21', LocalImportHelper::normalizar_porcentaje_de_iva("'21 %"));
        $this->assertSame('21', LocalImportHelper::normalizar_porcentaje_de_iva('21 %'));
        $this->assertSame('10.5', LocalImportHelper::normalizar_porcentaje_de_iva('10,5 %'));
        $this->assertSame('10.5', LocalImportHelper::normalizar_porcentaje_de_iva("'10,50%"));
        $this->assertSame('0', LocalImportHelper::normalizar_porcentaje_de_iva('0,00%'));
        $this->assertSame('Exento', LocalImportHelper::normalizar_porcentaje_de_iva('Exento'));
        $this->assertSame('', LocalImportHelper::normalizar_porcentaje_de_iva('%'));
    }

    /**
     * «+» y «;» encadenan descuentos igual que el «_» del sistema.
     */
    public function test_los_descuentos_encadenados_con_mas_se_separan()
    {
        $this->assertSame(['-25', '-30', '-17'], DescuentosDelExcel::separar('-25+-30+-17'));
        $this->assertSame(['10', '5'], DescuentosDelExcel::separar('10_5'));
        $this->assertSame(['10', '5'], DescuentosDelExcel::separar('10;5'));
        $this->assertSame(['-12,5', '-3'], DescuentosDelExcel::separar("'-12,5%+-3%"));
        $this->assertSame(['5'], DescuentosDelExcel::separar('+5'));
        $this->assertSame(['33'], DescuentosDelExcel::separar(33.0));
        $this->assertSame([], DescuentosDelExcel::separar(null));
    }

    /**
     * Sin la opción, la celda solo cambia de separador; con la opción, se invierte el signo.
     */
    public function test_la_opcion_invierte_el_signo_de_los_descuentos()
    {
        $this->assertSame('-25_-30_-17', DescuentosDelExcel::normalizar_celda('-25+-30+-17', false));
        $this->assertSame('25_30_17', DescuentosDelExcel::normalizar_celda('-25+-30+-17', true));
        // Un positivo en una columna con signo es un recargo: queda como descuento negativo.
        $this->assertSame('25_-5', DescuentosDelExcel::normalizar_celda('-25+5', true));
        $this->assertSame('0', DescuentosDelExcel::normalizar_celda('0', true));
        $this->assertNull(DescuentosDelExcel::normalizar_celda('', true));
    }

    /**
     * Las dos lecturas del signo dan el mismo multiplicador para el mismo efecto.
     */
    public function test_multiplicador_segun_la_lectura_del_signo()
    {
        $this->assertEqualsWithDelta(0.75 * 0.70 * 0.83, DescuentosDelExcel::multiplicador('-25+-30+-17', 'con_signo'), 0.000001);
        $this->assertEqualsWithDelta(0.75 * 0.70 * 0.83, DescuentosDelExcel::multiplicador('25_30_17', 'del_sistema'), 0.000001);
        $this->assertNull(DescuentosDelExcel::multiplicador('consultar', 'con_signo'));
    }

    /**
     * El detector llega al precio de venta final desde el costo más de base, con todas las
     * variables, y no a un paso intermedio con menos variables.
     */
    public function test_el_detector_encuentra_la_formula_del_precio_de_venta()
    {
        $encabezados = [
            0 => 'Código', 1 => 'Descripción', 2 => "Importe\nBase", 3 => 'Descuentos',
            4 => "Importe\nCompra", 5 => 'Beneficio', 6 => 'Precio S\\Iva', 7 => 'Precio C\\Iva',
            8 => 'Import. TOTAL', 9 => 'Eq.C', 10 => 'Iva.V',
        ];

        $filas = [];
        $descuentos = ['-33', '-25+-30+-17', '-10', '', '-20+5', '-15+-5'];
        $equivalencias = [1, 20, 1, 12, 1, 1];
        for ($i = 0; $i < 60; $i++) {
            $base = 1000 + $i * 37.5;
            $descuento = $descuentos[$i % 6];
            $unidades = $equivalencias[$i % 6];
            $beneficio = [30, 50, 45][$i % 3];
            $iva = $i % 7 === 0 ? '10,5 %' : '21 %';
            $compra = $base * (DescuentosDelExcel::multiplicador($descuento, 'con_signo') ?? 1);
            $sin_iva = $compra / $unidades * (1 + $beneficio / 100);
            $con_iva = $sin_iva * (1 + ($i % 7 === 0 ? 10.5 : 21) / 100);
            $filas[$i + 2] = ['#' . $i, 'Artículo ' . $i, $base, $descuento, $compra, $beneficio, $sin_iva, $con_iva, $con_iva, $unidades, $iva];
        }

        $resultado = (new DetectorDeFormulaDePrecio($encabezados, $filas, []))->detectar();

        $this->assertTrue($resultado['encontrada']);
        $this->assertSame('I', $resultado['columnas']['precio']['letra'], 'El objetivo es el precio final, no el sin IVA.');
        $this->assertSame('C', $resultado['columnas']['costo']['letra'], 'Arranca desde el costo antes de descuentos.');
        $this->assertSame('D', $resultado['columnas']['descuento']['letra']);
        $this->assertSame('J', $resultado['columnas']['unidad']['letra']);
        $this->assertSame('F', $resultado['columnas']['margen']['letra']);
        $this->assertSame('K', $resultado['columnas']['iva']['letra']);
        $this->assertSame('con_signo', $resultado['lectura_de_descuentos']);
        $this->assertSame(1.0, (float) $resultado['tasa']);
        $this->assertStringContainsString('«Import. TOTAL» (columna I)', $resultado['oracion']);

        // Con margen, el precio no se mapea: el sistema lo calcula.
        $this->assertArrayHasKey('precio', $resultado['mapeo_sugerido']);
        $this->assertNull($resultado['mapeo_sugerido']['precio']);
        $this->assertSame(2, $resultado['mapeo_sugerido']['costo']);

        // Las columnas intermedias se detectan para no importarlas.
        $intermedias = array_column($resultado['intermedias'], 'letra');
        $this->assertContains('E', $intermedias);
        $this->assertContains('G', $intermedias);
        $this->assertContains('H', $intermedias);
    }

    /**
     * Código de barras repetido: se omite cuando el código de proveedor distingue las filas;
     * si el código de proveedor también se repite (o no hay), la fila sigue con el de barras.
     */
    public function test_codigos_de_barras_repetidos_por_codigo_de_proveedor()
    {
        $repetidos = new CodigosDeBarraRepetidos();
        $repetidos->agregar('54', '0054');
        $repetidos->agregar('54', '0054_1');
        $repetidos->agregar('7790001', 'PC-1');
        $repetidos->agregar('68083', 'X');
        $repetidos->agregar('68083', 'X');
        $repetidos->agregar('68083', null);
        $repetidos->agregar('111111111', 'A');
        $repetidos->agregar('111111111', 'B');
        $repetidos->agregar('0', 'C');

        $mapa = $repetidos->mapa_de_omision();

        $this->assertTrue(CodigosDeBarraRepetidos::se_omite($mapa, '54', '0054'));
        $this->assertTrue(CodigosDeBarraRepetidos::se_omite($mapa, '54', '0054_1'));
        $this->assertFalse(CodigosDeBarraRepetidos::se_omite($mapa, '7790001', 'PC-1'), 'Un código único se sigue usando.');
        $this->assertFalse(CodigosDeBarraRepetidos::se_omite($mapa, '68083', 'X'), 'Sin un código de proveedor que distinga, sigue por barras.');

        $resumen = $repetidos->resumen();
        $this->assertSame(2, $resumen['grupos']);
        $this->assertSame(5, $resumen['filas']);
        $this->assertSame(2, $resumen['filas_por_codigo_de_proveedor']);
        $this->assertSame(3, $resumen['filas_que_se_pisan']);
        $this->assertSame(3, $resumen['codigos_de_relleno']);

        $this->assertNull(CodigosDeBarraRepetidos::normalizar_codigo_de_barras('111111111'));
        $this->assertNull(CodigosDeBarraRepetidos::normalizar_codigo_de_barras('0'));
        $this->assertSame('7790001', CodigosDeBarraRepetidos::normalizar_codigo_de_barras('7790001'));
        $this->assertSame('111', CodigosDeBarraRepetidos::normalizar_codigo_de_barras('111'));
    }

    /**
     * ProcessRow reescribe la celda de descuentos antes de leerla, y con la opción de barras
     * repetidas saca el código de barras de la fila (sin forzarlo a blanco).
     */
    public function test_process_row_aplica_las_opciones_de_lectura()
    {
        $directorio = sys_get_temp_dir() . '/' . uniqid('barras_');
        $ruta = $directorio . '.barras_a_omitir';
        file_put_contents($ruta, serialize(['54' => ['0054' => true]]));

        $procesador = $this->procesador(
            ['descuentos' => 1, 'recargos' => 2],
            [
                'descuentos_negativos_descuentan'          => true,
                'barras_repetidas_por_codigo_de_proveedor' => true,
                'archivo_de_barras_a_omitir'               => $ruta,
            ]
        );

        $fila = $this->invocar($procesador, 'normalizar_celdas_de_descuentos_y_recargos', [['X', '-25+-30+-17', '5+F3']]);
        $this->assertSame('25_30_17', $fila[1]);
        $this->assertSame('5_F3', $fila[2], 'Los recargos no se invierten.');

        $data = ['bar_code' => '54', 'provider_code' => '0054'];
        $this->invocar_por_referencia($procesador, 'aplicar_barras_repetidas_por_codigo_de_proveedor', $data);
        $this->assertArrayNotHasKey('bar_code', $data);

        $data = ['bar_code' => '7790001', 'provider_code' => 'PC-1'];
        $this->invocar_por_referencia($procesador, 'aplicar_barras_repetidas_por_codigo_de_proveedor', $data);
        $this->assertSame('7790001', $data['bar_code']);

        $data = ['bar_code' => '111111111', 'provider_code' => 'Z'];
        $this->invocar_por_referencia($procesador, 'aplicar_barras_repetidas_por_codigo_de_proveedor', $data);
        $this->assertArrayNotHasKey('bar_code', $data, 'Un código de relleno no se carga.');

        @unlink($ruta);

        // Sin opciones: la celda solo cambia de separador y el código de barras queda.
        $sin_opciones = $this->procesador(['descuentos' => 1], []);
        $fila = $this->invocar($sin_opciones, 'normalizar_celdas_de_descuentos_y_recargos', [['X', '-25+-30']]);
        $this->assertSame('-25_-30', $fila[1]);
        $data = ['bar_code' => '111111111', 'provider_code' => 'Z'];
        $this->invocar_por_referencia($sin_opciones, 'aplicar_barras_repetidas_por_codigo_de_proveedor', $data);
        $this->assertSame('111111111', $data['bar_code']);
    }

    /**
     * ProcessRow sin constructor (no toca la base), con columnas y opciones de lectura.
     *
     * @param  array $columnas
     * @param  array $opciones
     * @return ProcessRow
     */
    private function procesador(array $columnas, array $opciones)
    {
        $reflexion = new \ReflectionClass(ProcessRow::class);
        $procesador = $reflexion->newInstanceWithoutConstructor();
        foreach (['columns' => $columnas, 'opciones_de_lectura' => $opciones, 'forced_blank_props' => []] as $nombre => $valor) {
            $propiedad = $reflexion->getProperty($nombre);
            $propiedad->setAccessible(true);
            $propiedad->setValue($procesador, $valor);
        }
        return $procesador;
    }

    /**
     * Invoca un método protegido.
     *
     * @param  object $objeto
     * @param  string $metodo
     * @param  array  $argumentos
     * @return mixed
     */
    private function invocar($objeto, $metodo, array $argumentos)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);
        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * Invoca un método protegido que recibe un array por referencia.
     *
     * @param  object $objeto
     * @param  string $metodo
     * @param  array  $data
     * @return void
     */
    private function invocar_por_referencia($objeto, $metodo, array &$data)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);
        $reflexion->invokeArgs($objeto, [&$data]);
    }
}
