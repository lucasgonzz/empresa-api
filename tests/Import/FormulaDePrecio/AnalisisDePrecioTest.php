<?php

namespace Tests\Import\FormulaDePrecio;

use App\Models\Article;
use App\Models\ImportHistory;
use Illuminate\Support\Facades\Notification;
use Tests\Import\RelacionesParecidas\RelacionesParecidasTestCase;

/**
 * Misión importacion-formula-precio (20/11/2026), de punta a punta con base:
 *  - get-recomendacion con `analizar_precio` trae `analisis_de_precio` (fórmula, descuentos y
 *    códigos de barras repetidos), calculado sin IA;
 *  - la importación con las dos opciones nuevas llega al mismo precio de venta que el Excel y
 *    resuelve los códigos de barras repetidos por código de proveedor;
 *  - sin las opciones, la importación se comporta como siempre (salvo leer el «+» como «_»).
 *
 * Fixture: 43_formula_de_precio.xlsx (generar_formula_de_precio.php documenta cada celda).
 * Extiende RelacionesParecidasTestCase solo por sus ayudas de get-recomendacion y de IA falsa.
 */
class AnalisisDePrecioTest extends RelacionesParecidasTestCase
{
    const FIXTURE = '43_formula_de_precio.xlsx';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * Mapeo 0-based del fixture para get-recomendacion (sin margen ni precio, para ver que la
     * fórmula los propone igual).
     *
     * @return array
     */
    private function columnas_del_fixture()
    {
        return [
            'codigo_de_barras'    => 0,
            'codigo_de_proveedor' => 1,
            'nombre'              => 2,
            'costo'               => 3,
            'descuentos'          => 4,
            'iva'                 => 8,
        ];
    }

    /**
     * Configuración 1-based para ArticleController@import, con el mapeo que sugiere la fórmula.
     *
     * @param  bool $con_opciones  true = las dos opciones nuevas prendidas.
     * @return array
     */
    private function config_de_importacion($con_opciones)
    {
        return [
            'prop_codigo_de_barras'    => 1,
            'prop_sku'                 => -1,
            'prop_codigo_de_proveedor' => 2,
            'prop_nombre'              => 3,
            'prop_costo'               => 4,
            'prop_descuentos'          => 5,
            'prop_margen_de_ganancia'  => 7,
            'prop_u_individuales'      => 8,
            'prop_iva'                 => 9,
            'prop_precio'              => -1,
            'prop_stock_actual'        => -1,
            'descuentos_negativos_descuentan'          => $con_opciones,
            'barras_repetidas_por_codigo_de_proveedor' => $con_opciones,
        ];
    }

    /**
     * Artículo creado por la importación, por código de proveedor.
     *
     * @param  string $codigo
     * @return Article|null
     */
    private function por_codigo($codigo)
    {
        return Article::where('user_id', $this->tenant->id)->where('provider_code', $codigo)->first();
    }

    public function test_la_recomendacion_con_la_bandera_trae_el_analisis_del_precio()
    {
        $this->falsear_ia(function () {
            return null;
        });

        $data = [
            'excel_path'      => $this->copiar_a_storage(self::FIXTURE),
            'provider_id'     => null,
            'column_mapping'  => $this->column_mapping($this->columnas_del_fixture()),
            'model'           => 'article',
            'analizar_precio' => true,
        ];

        $respuesta = $this->postJson('/api/ai-excel-import/get-recomendacion', $data)->assertStatus(202);
        $estado = $this->getJson('/api/ai-excel-import/analysis/' . $respuesta->json('analysis_uuid'))->assertStatus(200);
        $this->assertSame('listo', $estado->json('estado'), json_encode($estado->json()));

        $analisis = $estado->json('resultado.analisis_de_precio');
        $this->assertNotNull($analisis, 'Con la bandera tiene que venir el análisis.');

        // La fórmula: D → descuentos E → ÷ H → margen G → IVA I = J, y F es intermedia.
        $formula = $analisis['formula'];
        $this->assertTrue($formula['encontrada'], $formula['oracion']);
        $this->assertSame('J', $formula['columnas']['precio']['letra']);
        $this->assertSame('D', $formula['columnas']['costo']['letra']);
        $this->assertSame('E', $formula['columnas']['descuento']['letra']);
        $this->assertSame('H', $formula['columnas']['unidad']['letra']);
        $this->assertSame('G', $formula['columnas']['margen']['letra']);
        $this->assertSame('I', $formula['columnas']['iva']['letra']);
        $this->assertSame('con_signo', $formula['lectura_de_descuentos']);
        $this->assertContains('F', array_column($formula['intermedias'], 'letra'));
        $this->assertSame(6, $formula['filas_que_cierran']);

        // Descuentos negativos: se recomienda el tilde.
        $this->assertSame(5, $analisis['descuentos']['con_negativos']);
        $this->assertSame(2, $analisis['descuentos']['encadenados_con_mas']);
        $this->assertTrue($analisis['descuentos']['recomendar_negativos_descuentan']);

        // Códigos de barras: «54» y «111111111» repetidos, con códigos de proveedor distintos.
        $barras = $analisis['codigos_de_barra'];
        $this->assertSame(2, $barras['grupos']);
        $this->assertSame(4, $barras['filas_por_codigo_de_proveedor']);
        $this->assertSame(0, $barras['filas_que_se_pisan']);

        // Ninguna llamada a la IA de más: solo la de la recomendación de siempre.
        $this->assertSame(1, $this->llamadas_recomendacion);
    }

    public function test_con_las_opciones_el_precio_coincide_con_el_excel_y_las_barras_se_resuelven()
    {
        $import = $this->importar(self::FIXTURE, $this->config_de_importacion(true));

        $this->assertSame('terminado', $import->fresh()->status, (string) $import->fresh()->error_message);
        $this->assertCount(6, $this->articulos_creados(), 'Cada fila es un artículo distinto.');

        // FP-01: 1000 × 0,75 × 0,70 × 1,30 × 1,21 = 825,825 (el Import. TOTAL del Excel).
        $esmalte = $this->por_codigo('FP-01');
        $this->assertSame('7790101', (string) $esmalte->bar_code, 'Un código de barras único se carga.');
        $this->assertEqualsWithDelta(825.83, (float) $esmalte->final_price, 0.02);
        $this->assertEqualsWithDelta(21, (float) $esmalte->iva->percentage, 0.001, "El IVA «'21 %» es 21.");

        // 0054: bulto de 20 → 2000 × 0,90 ÷ 20 × 1,50 × 1,21 = 163,35.
        $pack = $this->por_codigo('0054');
        $this->assertNull($pack->bar_code, 'Código de barras repetido con código de proveedor distinto: no se carga.');
        $this->assertEqualsWithDelta(163.35, (float) $pack->final_price, 0.02);
        $this->assertNull($this->por_codigo('0054_1')->bar_code);

        // FP-04: «-20+5» → 500 × 0,80 × 1,05 × 1,40 × 1,105 = 649,74.
        $tornillo = $this->por_codigo('FP-04');
        $this->assertNull($tornillo->bar_code, '«111111111» repetido con código de proveedor distinto: no se carga.');
        $this->assertEqualsWithDelta(649.74, (float) $tornillo->final_price, 0.02);

        $this->assertNull($this->por_codigo('FP-05')->bar_code);
        $this->assertEqualsWithDelta(181.5, (float) $this->por_codigo('FP-06')->final_price, 0.02);

        $operaciones = json_encode(ImportHistory::find($import->id)->operaciones, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Descuentos negativos descuentan', $operaciones);
    }

    public function test_un_articulo_cargado_antes_por_codigo_de_barras_se_actualiza_y_no_se_duplica()
    {
        // Cargado antes por código de barras, sin código de proveedor (decisión de Lucas, 20/11/2026).
        $viejo = Article::create([
            'user_id'  => $this->tenant->id,
            'name'     => 'Arandela grower (cargada antes)',
            'bar_code' => '54',
            'cost'     => 1,
        ]);

        $this->importar(self::FIXTURE, $this->config_de_importacion(true));

        $viejo = Article::find($viejo->id);
        $this->assertSame('0054', (string) $viejo->provider_code, 'La primera fila del grupo toma el artículo existente.');
        $this->assertSame('54', (string) $viejo->bar_code, 'Su código de barras no se toca.');

        // La segunda fila del grupo no cae sobre el mismo artículo: crea el suyo.
        $unidad = $this->por_codigo('0054_1');
        $this->assertNotNull($unidad);
        $this->assertNotSame((int) $viejo->id, (int) $unidad->id);
        $this->assertSame(1, Article::where('user_id', $this->tenant->id)->where('provider_code', '0054')->count());
    }

    public function test_sin_las_opciones_se_importa_como_siempre()
    {
        $import = $this->importar(self::FIXTURE, $this->config_de_importacion(false));

        $this->assertSame('terminado', $import->fresh()->status, (string) $import->fresh()->error_message);

        // Sin la opción, «54» y «111111111» identifican: las filas repetidas se pisan entre sí.
        $this->assertCount(4, $this->articulos_creados());

        // El «+» igual se lee como «_»: −25 y −30 quedan como dos descuentos (negativos, suman).
        $esmalte = $this->por_codigo('FP-01');
        $porcentajes = $esmalte->article_discounts()->pluck('percentage')->map(function ($valor) {
            return (float) $valor;
        })->sort()->values()->all();
        $this->assertSame([-30.0, -25.0], $porcentajes);
        $this->assertGreaterThan(825.83, (float) $esmalte->final_price, 'Un descuento negativo suma al costo.');
    }
}
