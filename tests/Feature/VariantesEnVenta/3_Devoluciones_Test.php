<?php

namespace Tests\Feature\VariantesEnVenta;

use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 3 — devoluciones sobre una venta con el mismo artículo en varias variantes (misión
 * variantes-mismo-articulo-en-vender, 8/10/2026). Tests 5, 6 y 7 del plan, más A3 (las unidades
 * devueltas de un renglón sin variante y de varios precios) y el camino viejo de A2 (NC sin
 * movimientos atados).
 *
 * La devolución va por `POST api/devoluciones` con la forma que arma la SPA, y la NC se borra por
 * `DELETE api/current-acount/client/{id}`, que es lo que hace el botón de la cuenta corriente.
 *
 * @group variantes_en_venta
 */
class Devoluciones_Test extends VariantesEnVentaTestCase
{
    /**
     * La venta del escenario: M×2 y L×3 desde la sucursal 1.
     *
     * @param  array  $e
     * @return \App\Models\Sale
     */
    protected function venta_m2_l3($e)
    {
        return $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id]);
    }

    /**
     * Test 5 — devolver L×1 a la sucursal de la venta: vuelve a (L, suc1), la M no se toca, el
     * `returned_amount` queda solo en la fila de la L y el pivot de la NC guarda la variante.
     *
     * @test
     */
    public function devolver_una_variante_vuelve_a_esa_variante_y_marca_solo_su_renglon()
    {
        $e = $this->escenario();

        $venta = $this->venta_m2_l3($e);

        $fila_l = $this->fila_de($venta, $e['articulo'], $e['l']);

        $nota_credito = $this->devolver($venta, [$this->item_devolucion($fila_l, 1)], $e['suc1']->id);

        $this->assert_variante($e, 'l', 3, 5, 'Devolución de L×1 a la sucursal 1');
        $this->assert_variante($e, 'm', 4, 4, 'Devolución de L×1 (la M no se toca)');

        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount, 'La fila de la L registra la unidad devuelta.');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->returned_amount, 'La fila de la M no devolvió nada.');

        $movimiento = $this->movimientos($e['articulo'], 'Nota de credito')->last();

        $this->assertEquals($e['l']->id, (int) $movimiento->article_variant_id);
        $this->assertEquals($nota_credito->id, (int) $movimiento->nota_credito_id);
        $this->assertEquals($e['suc1']->id, (int) $movimiento->to_address_id);

        $this->assertTrue(Schema::hasColumn('article_current_acount', 'article_variant_id'), 'El pivot de la NC tiene que tener la columna de la variante.');

        $pivot_nc = DB::table('article_current_acount')->where('current_acount_id', $nota_credito->id)->get();

        $this->assertCount(1, $pivot_nc);
        $this->assertEquals($e['l']->id, (int) $pivot_nc->first()->article_variant_id, 'El pivot de la NC guarda la variante devuelta.');
    }

    /**
     * Test 6 — devolver L×1 a la sucursal 2 (distinta de la de la venta) y después borrar la NC: la
     * unidad sale de la sucursal 2, que es adonde había entrado, y de la L. Y la venta vuelve a
     * poder devolver sus 3 L.
     *
     * @test
     */
    public function borrar_la_nc_saca_de_la_misma_variante_y_del_mismo_deposito_al_que_entro()
    {
        $e = $this->escenario();

        $venta = $this->venta_m2_l3($e);

        $nota_credito = $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)], $e['suc2']->id);

        $this->assert_variante($e, 'l', 2, 6, 'Devolución de L×1 a la sucursal 2');

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assert_variante($e, 'l', 2, 5, 'Borrar la NC: la unidad sale de la sucursal 2, adonde había entrado');
        $this->assert_variante($e, 'm', 4, 4, 'Borrar la NC: la M no se toca');

        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount, 'La fila de la L vuelve a no tener nada devuelto.');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->returned_amount, 'La fila de la M sigue sin nada devuelto.');

        $reverso = $this->movimientos($e['articulo'], 'Nota de credito')->last();

        $this->assertEquals(-1.0, (float) $reverso->amount);
        $this->assertEquals($e['l']->id, (int) $reverso->article_variant_id);
        $this->assertEquals($nota_credito->id, (int) $reverso->nota_credito_id);

        /* El libro quedó coherente: se pueden volver a devolver las 3 L, y borrar la venta repone todo. */
        $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 3)], $e['suc1']->id);

        $this->assert_variante($e, 'l', 5, 5, 'Devolver las 3 L después de borrar la NC');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE de la venta');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE de la venta (la L ya estaba devuelta entera)');
    }

    /**
     * Test 7 — devolución SIN sucursal en el request de una variante que reparte por depósitos: va
     * al depósito de la venta, no se pierde.
     *
     * @test
     */
    public function devolver_sin_sucursal_una_variante_con_depositos_va_al_deposito_de_la_venta()
    {
        $e = $this->escenario();

        $venta = $this->venta_m2_l3($e);

        $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)], null);

        $this->assert_variante($e, 'l', 3, 5, 'Devolución sin sucursal: la unidad entra en la sucursal de la venta');
        $this->assertEquals(21.0, $this->stock($e['articulo']), 'La unidad devuelta no se puede perder.');
    }

    /**
     * A3 — un renglón SIN variante de un artículo que en la misma venta también va con variante (lo
     * que deja hoy escanear el código del padre): devolverlo marca solo la fila sin variante.
     *
     * @test
     */
    public function devolver_el_renglon_sin_variante_no_marca_las_filas_con_variante()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            ['article' => $e['articulo'], 'amount' => 1, 'price' => self::PRECIO],
        ], ['address_id' => $e['suc1']->id]);

        $fila_sin_variante = $this->fila_de($venta, $e['articulo'], null);

        $this->assertNotNull($fila_sin_variante, 'La venta tiene el renglón sin variante.');

        $this->devolver($venta, [$this->item_devolucion($fila_sin_variante, 1)], $e['suc1']->id);

        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], null)->returned_amount, 'La fila sin variante registra la devolución.');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->returned_amount, 'La fila de la M no devolvió nada: no se la puede marcar.');
    }

    /**
     * A3 — varios precios: dos filas del mismo artículo (sin variante), una de 100 × 2 y otra de
     * 50 × 3. La SPA manda un ítem por fila, cada uno con SU acumulado: 1 de la de 100 y 3 de la de
     * 50. Cada fila tiene que quedar con lo suyo (nunca más devuelto que vendido).
     *
     * @test
     */
    public function varios_precios_cada_fila_queda_con_sus_unidades_devueltas()
    {
        $e = $this->escenario();

        $testigo = $e['testigo'];

        $renglon = [
            'is_article'     => true,
            'id'             => $testigo->id,
            'name'           => $testigo->name,
            'price_vender'   => 100,
            'amount'         => 5,
            'varios_precios' => [
                ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
                ['id' => 0, 'price_vender' => 50, 'amount' => 3, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
            ],
        ];

        $venta = $this->crear_venta([$renglon], ['address_id' => $e['suc1']->id, 'total' => 350, 'sub_total' => 350]);

        $filas = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $testigo->id)->orderBy('id')->get();

        $this->assertCount(2, $filas, 'Varios precios deja dos filas.');

        $de_100 = $filas->first(function ($f) { return (float) $f->price == 100; });
        $de_50 = $filas->first(function ($f) { return (float) $f->price == 50; });

        $this->devolver($venta, [
            $this->item_devolucion($de_100, 1),
            $this->item_devolucion($de_50, 3),
        ], $e['suc1']->id);

        $this->assertEquals(1.0, (float) DB::table('article_sale')->where('id', $de_100->id)->value('returned_amount'), 'La fila de 100 devolvió 1.');
        $this->assertEquals(3.0, (float) DB::table('article_sale')->where('id', $de_50->id)->value('returned_amount'), 'La fila de 50 devolvió 3.');
        $this->assertEquals(9.0, $this->stock($testigo), 'Vuelven las 4 unidades: 10 − 5 + 4.');
    }

    /**
     * A2, camino viejo — una NC de antes de esta misión no tiene la variante en su pivot ni (si es
     * anterior a la auditoría de stock) movimientos atados. Borrarla sigue el criterio de siempre: el
     * renglón con más devuelto y la sucursal de la venta.
     *
     * @test
     */
    public function borrar_una_nc_vieja_sin_movimientos_atados_sigue_el_criterio_de_siempre()
    {
        $e = $this->escenario();

        $venta = $this->venta_m2_l3($e);

        $nota_credito = $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)], $e['suc1']->id);

        $this->assert_variante($e, 'l', 3, 5, 'Devolución de L×1');

        // La NC queda como una vieja: sin variante en el pivot y sin movimientos atados.
        if (Schema::hasColumn('article_current_acount', 'article_variant_id')) {
            DB::table('article_current_acount')->where('current_acount_id', $nota_credito->id)->update(['article_variant_id' => null]);
        }

        DB::table('stock_movements')->where('nota_credito_id', $nota_credito->id)->update(['nota_credito_id' => null]);

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assert_variante($e, 'l', 2, 5, 'Borrar la NC vieja: sale de la L (el renglón con más devuelto) en la sucursal de la venta');
        $this->assert_variante($e, 'm', 4, 4, 'Borrar la NC vieja: la M no se toca');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount);
    }
}
