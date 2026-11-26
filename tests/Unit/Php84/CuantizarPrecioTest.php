<?php

namespace Tests\Unit\Php84;

use App\Http\Controllers\Helpers\Numbers;
use PHPUnit\Framework\TestCase;

/**
 * Misión ft-guardado-margen (26/11/2026): Ferretotal corre PHP 8.4, donde `round()` ya no hace el
 * «pre-redondeo» de 7.4/8.3. Un precio calculado que cae en x,xx5 (2278.43 + 2278.43*50/100 =
 * 3417.6449999999995) bajaba un centavo (3417.64 en vez de 3417.65) y el guardado sin cambios creía
 * que el precio había cambiado.
 *
 * `Numbers::cuantizar()` tiene que dar en 8.4 lo mismo que daba `round()` en 7.4. Funciones puras: sin
 * Laravel ni base. El rojo sólo existe corriéndolo con `cc-php 8.4`; en 7.4 pasa antes y después.
 */
class CuantizarPrecioTest extends TestCase
{
    /** CA1: el valor exacto del caso 17338 de Ferretotal. */
    public function test_medio_centavo_calculado_redondea_arriba()
    {
        $costo_real = 2278.43;
        $calculado  = $costo_real + ($costo_real * (float) 50 / 100);

        $this->assertSame(3417.65, Numbers::cuantizar($calculado, 2));
    }

    /** @return array<string, array{0: float, 1: float}> */
    public function bordes_de_medio_centavo()
    {
        return [
            '0.285 literal'  => [0.285, 0.29],
            '1.005 literal'  => [1.005, 1.01],
            '2.675 literal'  => [2.675, 2.68],
            '1.955 literal'  => [1.955, 1.96],
            '8.325 literal'  => [8.325, 8.33],
            'x,xx5 un ulp abajo (3417.6449999999995)' => [3417.6449999999995, 3417.65],
            'x,xx5 un ulp abajo (66705.344999999987)' => [66705.344999999987, 66705.35],
            'negativo en x,xx5 se aleja de cero'      => [-1.005, -1.01],
        ];
    }

    /**
     * @dataProvider bordes_de_medio_centavo
     */
    public function test_bordes_dan_lo_mismo_que_7_4($valor, $esperado)
    {
        $this->assertSame($esperado, Numbers::cuantizar($valor, 2));
    }

    /** Límite del diseño: lo que genuinamente está por debajo de x,xx5 NO sube. */
    public function test_un_valor_genuinamente_debajo_del_medio_centavo_no_sube()
    {
        $this->assertSame(1.0, Numbers::cuantizar(1.0049999, 2));
        $this->assertSame(2.67, Numbers::cuantizar(2.6749999, 2));
    }

    public function test_valores_que_ya_son_de_dos_decimales_no_se_mueven()
    {
        $this->assertSame(0.0, Numbers::cuantizar(0, 2));
        $this->assertSame(2278.43, Numbers::cuantizar(2278.43, 2));
        $this->assertSame(3339.6, Numbers::cuantizar('3339.60', 2));
        $this->assertSame(100.0, Numbers::cuantizar(100, 2));
    }

    public function test_acepta_otros_decimales()
    {
        $this->assertSame(0.27, Numbers::cuantizar(0.2662, 2));
        $this->assertSame(0.2662, Numbers::cuantizar(0.266200, 4));
    }

    /**
     * En 8.4 el barrido de costo × (1 + margen) en pasos de centavo no puede diferir de lo que daba
     * `round()` en 7.4 (400.000 valores medidos: 0 diferencias en 7.4 y 5.249 de `round()` en 8.4). Acá
     * se fija una muestra con el mismo generador: cuantizar tiene que dar el valor «a mano»
     * (decimal exacto) para cada precio con costo en centavos y margen entero.
     */
    public function test_barrido_de_precios_calculados_coincide_con_la_aritmetica_decimal()
    {
        mt_srand(7);

        for ($i = 0; $i < 20000; $i++) {
            $centavos = mt_rand(1, 5000000);
            $margen   = [0, 5, 10, 15, 20, 25, 30, 40, 50, 60, 75, 100][mt_rand(0, 11)];

            $costo      = $centavos / 100;
            $calculado  = $costo + ($costo * (float) $margen / 100);

            // Aritmética decimal exacta con enteros: centavos*(100+margen)/100 con medio centavo hacia arriba.
            $en_cienmilesimos = $centavos * (100 + $margen);      // = precio en centavos * 100
            $esperado_centavos = intdiv($en_cienmilesimos + 50, 100);
            $esperado          = (float) ($esperado_centavos / 100); // int/int exacto devuelve int: assertSame compara tipo

            $this->assertSame(
                $esperado,
                Numbers::cuantizar($calculado, 2),
                'Costo ' . $costo . ' margen ' . $margen . ' % calculado ' . sprintf('%.17g', $calculado)
            );
        }
    }
}
