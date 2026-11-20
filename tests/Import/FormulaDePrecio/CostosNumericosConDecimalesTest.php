<?php

namespace Tests\Import\FormulaDePrecio;

use App\Models\Article;
use Tests\Import\ImportTestCase;

/**
 * Misión importacion-formula-precio (20/11/2026): un costo en una celda NUMÉRICA del Excel con
 * decimales se importa con su valor, en cualquier modo de interpretación del punto. Antes el CSV
 * intermedio lo escribía «74.984» y el parseo lo leía como 74984 (bug reproducido con la lista
 * real de un cliente: 18.374 costos mil veces más caros). Las celdas de TEXTO siguen la
 * interpretación que eligió el usuario, como siempre.
 *
 * Fixture: 44_costos_numericos_con_decimales.xlsx (generar_formula_de_precio.php).
 */
class CostosNumericosConDecimalesTest extends ImportTestCase
{
    const FIXTURE = '44_costos_numericos_con_decimales.xlsx';

    /**
     * Costo importado por código de proveedor.
     *
     * @param  string $codigo
     * @return float|null
     */
    private function costo($codigo)
    {
        $articulo = Article::where('user_id', $this->tenant->id)->where('provider_code', $codigo)->first();

        return $articulo ? (float) $articulo->cost : null;
    }

    public function test_en_auto_los_numeros_con_decimales_se_leen_bien()
    {
        $this->importar(self::FIXTURE, ['interpretacion_punto' => 'auto']);

        $this->assertEqualsWithDelta(74.984, $this->costo('TD-01'), 0.000001);
        $this->assertEqualsWithDelta(0.746, $this->costo('TD-02'), 0.000001);
        $this->assertEqualsWithDelta(141388.5429, $this->costo('TD-03'), 0.000001);
        // El texto «2.500» sigue leyéndose como miles en 'auto', como siempre.
        $this->assertEqualsWithDelta(2500, $this->costo('TD-04'), 0.000001);
        $this->assertEqualsWithDelta(2500, $this->costo('TD-05'), 0.000001);
    }

    public function test_en_siempre_miles_los_numeros_con_decimales_no_se_rompen()
    {
        $this->importar(self::FIXTURE, ['interpretacion_punto' => 'siempre_miles']);

        $this->assertEqualsWithDelta(74.984, $this->costo('TD-01'), 0.000001);
        $this->assertEqualsWithDelta(141388.5429, $this->costo('TD-03'), 0.000001);
        $this->assertEqualsWithDelta(2500, $this->costo('TD-04'), 0.000001);
    }

    public function test_en_siempre_decimal_el_texto_sigue_la_eleccion_del_usuario()
    {
        $this->importar(self::FIXTURE, ['interpretacion_punto' => 'siempre_decimal']);

        $this->assertEqualsWithDelta(74.984, $this->costo('TD-01'), 0.000001);
        $this->assertEqualsWithDelta(2.5, $this->costo('TD-04'), 0.000001);
    }
}
