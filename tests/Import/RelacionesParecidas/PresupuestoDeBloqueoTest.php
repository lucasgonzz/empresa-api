<?php

namespace Tests\Import\RelacionesParecidas;

use App\Http\Controllers\Helpers\import\relaciones\RelacionesParecidasDetector;
use Tests\TestCase;

/**
 * El bloqueo por bigramas también gasta presupuesto (misión importacion-relaciones-parecidas,
 * tandas 4 y 5).
 *
 * Con 1999 valores contra 20.000 existentes el chequeo tardaba 46-73 s con el presupuesto de
 * comparaciones sin tocar: cada valor compara 20 como máximo, pero para elegirlos recorre las
 * listas del índice de todos sus bigramas, y eso no se contaba. Ahora se cobra antes de recorrer;
 * agotado, los valores que faltan quedan sin candidatos y la corrida sale `recortado`. Y los
 * candidatos se buscan de los valores con más filas a los de menos, para que lo que se quede sin
 * revisar sea lo que menos pesa.
 *
 * Tanda 5 (con nombres reales de varias palabras de rubro, 1999 x 3000 ya agotaba los 15 M y el
 * primer valor que no entraba vaciaba el saldo): un valor que no entra se saltea SOLO ÉL; los
 * bigramas comunes de la lista no se usan para bloquear; y el chequeo reparte el presupuesto por
 * columna.
 *
 * Sin base: el detector es puro.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PresupuestoDeBloqueoTest extends TestCase
{
    protected function existentes()
    {
        return [
            ['id' => 1, 'nombre' => 'Ferretería Del Norte'],
            ['id' => 2, 'nombre' => 'Bulonería Tomi'],
            ['id' => 3, 'nombre' => 'Martillos'],
        ];
    }

    public function test_sin_presupuesto_de_bloqueo_no_se_recorre_nada_y_sale_recortado()
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, 0);

        $grupo = RelacionesParecidasDetector::analizar_grupo(['Martillo' => 1, 'Bulonera Tomy' => 1], $this->existentes(), [], true, $presupuesto);

        $this->assertTrue($grupo['recortado']);
        $this->assertSame('nuevo', $grupo['valores']['Martillo']['estado']);
        $this->assertSame('nuevo', $grupo['valores']['Bulonera Tomy']['estado']);

        /* Ni una comparación: el corte es antes de recorrer las listas. */
        $this->assertSame(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $presupuesto->restante);
    }

    public function test_agotado_en_el_medio_se_quedan_sin_revisar_los_de_menos_filas()
    {
        /* Cuánto cuesta revisar "Martillo" solo. */
        $medida = RelacionesParecidasDetector::nuevo_presupuesto();
        RelacionesParecidasDetector::analizar_grupo(['Martillo' => 5], $this->existentes(), [], true, $medida);
        $costo_martillo = RelacionesParecidasDetector::PRESUPUESTO_BLOQUEO - $medida->bloqueo_restante;

        $this->assertGreaterThan(0, $costo_martillo);

        /* Con eso justo, y "Martillo" ÚLTIMO en el archivo pero con más filas: se revisa él. */
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $costo_martillo);

        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 5],
            $this->existentes(),
            [],
            true,
            $presupuesto
        );

        $this->assertTrue($grupo['recortado']);
        $this->assertSame(0, $presupuesto->bloqueo_restante);

        $this->assertSame('consultar', $grupo['valores']['Martillo']['estado']);
        $this->assertSame(3, $grupo['valores']['Martillo']['candidatos'][0]['id']);

        $this->assertSame('nuevo', $grupo['valores']['Bulonera Tomy']['estado']);
        $this->assertSame('nuevo', $grupo['valores']['Ferret. del Norte']['estado']);
    }

    public function test_con_el_presupuesto_de_siempre_no_cambia_nada()
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto();

        $grupo = RelacionesParecidasDetector::analizar_grupo(
            ['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 5],
            $this->existentes(),
            [],
            true,
            $presupuesto
        );

        $this->assertFalse($grupo['recortado']);

        foreach (['Ferret. del Norte' => 1, 'Bulonera Tomy' => 2, 'Martillo' => 3] as $valor => $id) {
            $this->assertSame('consultar', $grupo['valores'][$valor]['estado'], $valor);
            $this->assertSame($id, $grupo['valores'][$valor]['candidatos'][0]['id'], $valor);
        }

        /* El orden de los valores en el resultado sigue siendo el del archivo. */
        $this->assertSame(['Ferret. del Norte', 'Bulonera Tomy', 'Martillo'], array_keys($grupo['valores']));
    }

    public function test_un_valor_que_no_entra_se_saltea_solo_el()
    {
        $existentes = $this->existentes();

        $caro = 'Ferreteria del Norte Martillos';
        $barato = 'Martilo';

        $costo_caro = $this->costo([$caro => 1], $existentes);
        $costo_barato = $this->costo([$barato => 1], $existentes);

        $this->assertGreaterThan($costo_barato, $costo_caro, 'Precondición: uno cuesta más que el otro.');

        /* Alcanza para el barato y no para el caro, que va PRIMERO (más filas). */
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $costo_barato);

        $grupo = RelacionesParecidasDetector::analizar_grupo([$caro => 5, $barato => 1], $existentes, [], true, $presupuesto);

        $this->assertTrue($grupo['recortado']);
        $this->assertSame('nuevo', $grupo['valores'][$caro]['estado'], 'El caro no entra: se saltea.');
        $this->assertSame('consultar', $grupo['valores'][$barato]['estado'], 'El saldo no se vació: el que sigue se revisa.');
        $this->assertSame(3, $grupo['valores'][$barato]['candidatos'][0]['id']);
        $this->assertSame(0, $presupuesto->bloqueo_restante);
    }

    public function test_los_bigramas_comunes_no_se_usan_para_bloquear()
    {
        /* 400 "Distribuidora Norte N": sus bigramas están en casi toda la lista. */
        $existentes = [['id' => 1, 'nombre' => 'Bulonería Tomi']];

        for ($i = 2; $i <= 401; $i++) {
            $existentes[] = ['id' => $i, 'nombre' => 'Distribuidora Norte ' . $i];
        }

        $indice = $this->indice($existentes);

        $this->assertArrayHasKey('bu', $indice['comunes'], '"bu" está en las 400 distribuidoras.');
        $this->assertArrayNotHasKey('ul', $indice['comunes']);

        /* "Bulonera Tomy" comparte "bu" y "ra" con las 400: sin los comunes, eso no se recorre. */
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto();
        $grupo = RelacionesParecidasDetector::analizar_grupo(['Bulonera Tomy' => 1], $existentes, [], true, $presupuesto);

        $this->assertSame('consultar', $grupo['valores']['Bulonera Tomy']['estado']);
        $this->assertSame(1, $grupo['valores']['Bulonera Tomy']['candidatos'][0]['id']);
        $this->assertLessThan(100, RelacionesParecidasDetector::PRESUPUESTO_BLOQUEO - $presupuesto->bloqueo_restante, 'Con "bu" y "ra" serían más de 800 entradas.');

        /* Un valor que SOLO tiene bigramas comunes bloquea con todos: no se queda sin candidatos. */
        $grupo = RelacionesParecidasDetector::analizar_grupo(['Distribuidora' => 1], $existentes, [], true, RelacionesParecidasDetector::nuevo_presupuesto());

        $this->assertSame('consultar', $grupo['valores']['Distribuidora']['estado']);
    }

    public function test_con_pocos_existentes_no_hay_bigramas_comunes()
    {
        /* El piso: en una lista chica el 20 % son dos o tres elementos, y ahí no hay nada que ahorrar. */
        $this->assertSame([], $this->indice($this->existentes())['comunes']);
    }

    public function test_la_cuota_por_columna_no_deja_sin_candidatos_a_la_siguiente()
    {
        $existentes = $this->existentes();

        $primera = ['Ferreteria del Norte Martillos' => 3, 'Martillo' => 2, 'Bulonera del Norte' => 1];
        $segunda = ['Martilo' => 1];

        $costo_segunda = $this->costo($segunda, $existentes);
        $total = 2 * $costo_segunda;

        $this->assertGreaterThanOrEqual($total, $this->costo($primera, $existentes), 'Precondición: la primera sola se lo gastaría todo.');

        /* Sin cuota: la primera se lo gasta y la segunda se queda sin candidatos. */
        $sin_cuota = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $total);
        RelacionesParecidasDetector::analizar_grupo($primera, $existentes, [], true, $sin_cuota);
        $grupo = RelacionesParecidasDetector::analizar_grupo($segunda, $existentes, [], true, $sin_cuota);

        $this->assertSame('nuevo', $grupo['valores']['Martilo']['estado']);

        /* Con cuota (como la abre el chequeo): la primera no pasa de su mitad. */
        $con_cuota = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, $total);

        RelacionesParecidasDetector::abrir_cuota_de_bloqueo($con_cuota, 2);
        $this->assertSame($costo_segunda, $con_cuota->bloqueo_cuota);
        RelacionesParecidasDetector::analizar_grupo($primera, $existentes, [], true, $con_cuota);
        $this->assertGreaterThanOrEqual($costo_segunda, $con_cuota->bloqueo_restante);

        RelacionesParecidasDetector::abrir_cuota_de_bloqueo($con_cuota, 1);
        $grupo = RelacionesParecidasDetector::analizar_grupo($segunda, $existentes, [], true, $con_cuota);

        $this->assertSame('consultar', $grupo['valores']['Martilo']['estado']);
        $this->assertSame(3, $grupo['valores']['Martilo']['candidatos'][0]['id']);
    }

    public function test_lo_que_una_columna_no_usa_queda_para_las_demas()
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto(RelacionesParecidasDetector::PRESUPUESTO_COMPARACIONES, 1000);

        RelacionesParecidasDetector::abrir_cuota_de_bloqueo($presupuesto, 2);
        $this->assertSame(500, $presupuesto->bloqueo_cuota);

        RelacionesParecidasDetector::analizar_grupo(['Martilo' => 1], $this->existentes(), [], true, $presupuesto);
        $usado = 1000 - $presupuesto->bloqueo_restante;
        $this->assertGreaterThan(0, $usado);

        RelacionesParecidasDetector::abrir_cuota_de_bloqueo($presupuesto, 1);
        $this->assertSame(1000 - $usado, $presupuesto->bloqueo_cuota, 'La segunda recibe todo lo que quedó, más que su mitad.');
    }

    /**
     * Cuántas entradas del índice recorre el bloqueo de esos valores (con presupuesto de sobra).
     *
     * @param  array $valores
     * @param  array $existentes
     * @return int
     */
    protected function costo(array $valores, array $existentes)
    {
        $presupuesto = RelacionesParecidasDetector::nuevo_presupuesto();
        RelacionesParecidasDetector::analizar_grupo($valores, $existentes, [], true, $presupuesto);

        return RelacionesParecidasDetector::PRESUPUESTO_BLOQUEO - $presupuesto->bloqueo_restante;
    }

    /**
     * El índice de bigramas de unos existentes (método protegido del detector).
     *
     * @param  array $existentes
     * @return array
     */
    protected function indice(array $existentes)
    {
        $lista = [];

        foreach ($existentes as $existente) {
            $lista[] = ['rasgos' => RelacionesParecidasDetector::rasgos($existente['nombre'])];
        }

        $metodo = new \ReflectionMethod(RelacionesParecidasDetector::class, 'indice_de_bigramas');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $lista);
    }
}
