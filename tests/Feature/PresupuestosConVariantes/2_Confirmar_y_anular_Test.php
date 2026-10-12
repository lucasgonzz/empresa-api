<?php

namespace Tests\Feature\PresupuestosConVariantes;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 2 — confirmar y anular un presupuesto con variantes (misión presupuestos-con-variantes,
 * 8/10/2026). Casos 5, 6, 7 y 8 (confirmación) del plan.
 *
 * Todo en la SUCURSAL 2, para que un descuento que cayera en la sucursal 1 (o en el artículo sin
 * variante) se vea.
 *
 * @group presupuestos_con_variantes
 */
class Confirmar_y_anular_presupuesto_con_variantes_Test extends PresupuestosConVariantesTestCase
{
    /**
     * Caso 5 — confirmar en la sucursal 2 un presupuesto con M×2 y L×3: la venta tiene dos filas,
     * cada una con su variante; bajan (M, suc2) y (L, suc2), la sucursal 1 y la S quedan intactas,
     * los movimientos llevan la variante y el artículo sigue siendo la suma de sus variantes.
     *
     * @test
     */
    public function confirmar_hereda_la_variante_y_descuenta_cada_variante_en_la_sucursal()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        $venta = $this->confirmar($budget);

        $this->assertCount(2, $this->filas($venta, $e['articulo']), 'Un renglón de venta por variante.');

        $m = $this->fila_de($venta, $e['articulo'], $e['m']);
        $l = $this->fila_de($venta, $e['articulo'], $e['l']);

        $this->assertNotNull($m, 'La venta perdió la variante M.');
        $this->assertNotNull($l, 'La venta perdió la variante L.');
        $this->assertEquals(2.0, (float) $m->amount);
        $this->assertEquals(3.0, (float) $l->amount);
        $this->assertEquals('Talle M', $m->variant_description);
        $this->assertEquals('Talle L', $l->variant_description);

        $this->assert_variante($e, 'm', 6, 2, 'Confirmar en suc2');
        $this->assert_variante($e, 'l', 5, 2, 'Confirmar en suc2');
        $this->assert_variante($e, 's', 3, 2, 'Confirmar en suc2 (S no estaba)');
        $this->assertEquals(20.0, $this->stock($e['articulo']));

        $movimientos = DB::table('stock_movements')->where('sale_id', $venta->id)->get();

        $this->assertCount(2, $movimientos, 'Un movimiento por variante.');

        foreach ($movimientos as $movimiento) {
            $this->assertNotNull($movimiento->article_variant_id, 'Ningún movimiento de la venta va al artículo sin variante.');
            $this->assertEquals($e['suc2']->id, (int) $movimiento->from_address_id);
        }
    }

    /**
     * Caso 6 — anular: la venta se borra y el stock vuelve a cada variante en su sucursal.
     *
     * @test
     */
    public function anular_devuelve_el_stock_a_cada_variante_en_su_sucursal()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        $venta = $this->confirmar($budget);

        $this->assert_variante($e, 'm', 6, 2, 'Confirmado');

        $this->postJson('api/budget/'.$budget->id.'/anular')->assertStatus(200);

        $this->assertNull(Sale::where('budget_id', $budget->id)->first(), 'Anular tiene que borrar la venta.');

        $this->assert_variante($e, 'm', 6, 4, 'Anulado');
        $this->assert_variante($e, 'l', 5, 5, 'Anulado');
        $this->assert_variante($e, 's', 3, 2, 'Anulado');
        $this->assertEquals(25.0, $this->stock($e['articulo']));
    }

    /**
     * Caso 7 — regresión: un presupuesto sin variantes (el testigo) confirma y descuenta igual que
     * hoy, y su venta queda sin variante.
     *
     * @test
     */
    public function un_presupuesto_sin_variantes_confirma_igual_que_siempre()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['testigo'], null, 2, 100),
        ], $e['suc2']->id);

        $venta = $this->confirmar($budget);

        $fila = $this->fila_de($venta, $e['testigo'], null);

        $this->assertNotNull($fila);
        $this->assertEquals(2.0, (float) $fila->amount);
        $this->assertNull($fila->variant_description);
        $this->assertEquals(8.0, $this->stock($e['testigo']), 'El testigo baja 2 de su stock global.');

        // El artículo con variantes no se tocó.
        $this->assert_variante($e, 'm', 6, 4, 'Sin variantes');
        $this->assert_variante($e, 'l', 5, 5, 'Sin variantes');
    }

    /**
     * Caso 8 (confirmación) — varios precios de la MISMA variante: las dos filas pasan a la venta
     * con la variante y el stock de la variante en la sucursal baja la suma (1 + 2).
     *
     * @test
     */
    public function varios_precios_de_una_variante_descuentan_la_suma_de_esa_variante()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1, 300),
            $this->renglon_plano($e['articulo'], $e['m'], 2, 250),
        ], $e['suc2']->id);

        $venta = $this->confirmar($budget);

        $filas = $this->filas($venta, $e['articulo']);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertEquals($e['m']->id, (int) $fila->article_variant_id);
        }

        $this->assert_variante($e, 'm', 6, 1, 'Varios precios');
        $this->assert_variante($e, 'l', 5, 5, 'Varios precios (L intacta)');
    }
}
