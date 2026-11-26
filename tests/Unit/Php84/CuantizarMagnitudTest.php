<?php

namespace Tests\Unit\Php84;

use App\Http\Controllers\Helpers\Numbers;
use PHPUnit\Framework\TestCase;

/**
 * Misión ft-guardado-margen, QA independiente (26/11/2026): `Numbers::cuantizar()` hacía
 * `round(round($v, 9), 2)`. Un float cerca de 2^21 (~2,1 millones) tiene un ulp mayor que 1e-9, así que
 * el pre-redondeo a 9 decimales ya no absorbe el error y el importe bajaba un centavo en 8.4:
 * costo 2098184.26 con margen 75 = 3671822.4549999991 → 7.4 daba 3671822.46, 8.4 daba 3671822.45.
 *
 * El oráculo es aritmética entera exacta (centavos × (100 + margen) / 100, mitad hacia arriba), sin
 * floats, para no repetir el defecto que se mide. Funciones puras: sin Laravel ni base.
 */
class CuantizarMagnitudTest extends TestCase
{
    /** El caso exacto del rechazo de QA. */
    public function test_caso_costo_2098184_26_margen_75()
    {
        $costo = 2098184.26;
        $calculado = $costo + ($costo * (float) 75 / 100);

        $this->assertSame(3671822.46, Numbers::cuantizar($calculado, 2));
        $this->assertSame(-3671822.46, Numbers::cuantizar(-$calculado, 2));
    }

    /** Valores medidos con round() de PHP 7.4.33 (ver .mision/TESTS.md) que 8.4 con 9 decimales fallaba. */
    public function referencias_7_4()
    {
        return [
            [3731753.1049999991, 3731753.11],
            [7576515.8049999969, 7576515.81],
            [8301481.2049999991, 8301481.21],
            [8539161.2049999963, 8539161.21],
            [3671822.4549999991, 3671822.46],
        ];
    }

    /** @dataProvider referencias_7_4 */
    public function test_referencias_medidas_en_7_4_ambos_signos($valor, $esperado)
    {
        $this->assertSame($esperado, Numbers::cuantizar($valor, 2));
        $this->assertSame(-$esperado, Numbers::cuantizar(-$valor, 2));
    }

    /**
     * Barrido por magnitud (de 1e2 a 1e9) de costo × margen. Para cada magnitud se prueban costos en
     * centavos y márgenes enteros; el esperado sale de aritmética entera.
     */
    public function test_barrido_por_magnitud_costo_por_margen_ambos_signos()
    {
        mt_srand(20261126);
        $fallos = [];
        $casos = 0;
        for ($exp = 2; $exp <= 9; $exp++) {
            for ($i = 0; $i < 3000; $i++) {
                $centavos = mt_rand(1, 999999) * (int) pow(10, max(0, $exp - 4)) + mt_rand(0, 99);
                $margen   = mt_rand(1, 300);
                $costo    = $centavos / 100;
                $calculado = $costo + ($costo * (float) $margen / 100);

                // centavos × (100 + margen) / 100, mitad hacia arriba, todo en enteros
                $num = $centavos * (100 + $margen);
                $esperado_centavos = intdiv($num + 50, 100);
                $esperado = $esperado_centavos / 100;

                foreach ([1, -1] as $signo) {
                    $casos++;
                    $obtenido = Numbers::cuantizar($signo * $calculado, 2);
                    if ($obtenido !== (float) ($signo * $esperado)) {
                        $fallos[] = sprintf('costo=%s margen=%d signo=%d calc=%.17g esperado=%s obtenido=%s',
                            $costo, $margen, $signo, $calculado, $signo * $esperado, $obtenido);
                    }
                }
            }
        }
        $this->assertGreaterThan(40000, $casos);
        $this->assertSame([], array_slice($fallos, 0, 5), count($fallos) . ' de ' . $casos . ' distintos del 7.4');
    }

    /** Medios centavos exactos con un ulp de ruido hacia abajo, en cada magnitud, ambos signos. */
    public function test_medio_centavo_con_ruido_de_un_ulp_en_cada_magnitud()
    {
        $fallos = [];
        for ($exp = 0; $exp <= 9; $exp++) {
            for ($k = 1; $k <= 200; $k++) {
                $centavos_enteros = $k * 7919 * (int) pow(10, max(0, $exp - 3)) + 1; // .xx5 = (c + 0.5)/100
                $v = ($centavos_enteros + 0.5) / 100;
                $abajo = $v - 2.0e-16 * abs($v); // aprox. un ulp hacia abajo
                $esperado = ($centavos_enteros + 1) / 100;
                foreach ([1, -1] as $s) {
                    if (Numbers::cuantizar($s * $abajo, 2) !== (float) ($s * $esperado)) {
                        $fallos[] = sprintf('%.17g', $s * $abajo);
                    }
                }
            }
        }
        $this->assertSame([], array_slice($fallos, 0, 5));
    }

    /** Límite: un importe genuinamente por debajo del medio centavo NO sube, tampoco en millones. */
    public function test_importe_genuinamente_menor_al_medio_centavo_no_sube()
    {
        $this->assertSame(1000000.0, Numbers::cuantizar(1000000.0049, 2));
        $this->assertSame(-1000000.0, Numbers::cuantizar(-1000000.0049, 2));
        $this->assertSame(3671822.45, Numbers::cuantizar(3671822.4549, 2));
        $this->assertSame(123456789.12, Numbers::cuantizar(123456789.1249, 2));
        $this->assertSame(999999999.99, Numbers::cuantizar(999999999.9949, 2));
        $this->assertSame(1.0, Numbers::cuantizar(1.0049999, 2));
    }

    /** Límites del dominio: cero, mínimos y valores ya cuantizados no se mueven. */
    public function test_limites_del_dominio()
    {
        $this->assertSame(0.0, Numbers::cuantizar(0, 2));
        $this->assertSame(0.01, Numbers::cuantizar(0.005, 2));
        $this->assertSame(-0.01, Numbers::cuantizar(-0.005, 2));
        $this->assertSame(1000000000.0, Numbers::cuantizar(1000000000, 2));
        $this->assertSame(999999999.99, Numbers::cuantizar(999999999.99, 2));
    }
}
