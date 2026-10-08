<?php

namespace Tests\Feature\VariantesEnVenta;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\ArticleVariant;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 6 — bordes de devoluciones y de borrar la NC, pedidos por la revisión independiente de la
 * misión variantes-mismo-articulo-en-vender (8/10/2026): varios precios al borrar la NC (A2), el
 * precio editado en Devoluciones (A3), las decisiones de A2 (artículo sin movimiento atado,
 * `returned_amount` que no baja de cero, NC con libro pero sin variante en el pivot) y A4 con una
 * variante que no reparte por depósitos.
 *
 * @group variantes_en_venta
 */
class Devoluciones_bordes_Test extends VariantesEnVentaTestCase
{
    /**
     * Venta del testigo con varios precios: una fila de 100 × 2 y otra de 50 × 3.
     *
     * @param  array  $e
     * @param  float  $total  Distinto en cada test (guarda anti-duplicados de 5 segundos).
     * @return array  [venta, fila de 100, fila de 50]
     */
    protected function venta_varios_precios($e, $total)
    {
        $testigo = $e['testigo'];

        $venta = $this->crear_venta([[
            'is_article'     => true,
            'id'             => $testigo->id,
            'name'           => $testigo->name,
            'price_vender'   => 100,
            'amount'         => 5,
            'cost'           => (float) $testigo->cost,
            'varios_precios' => [
                ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
                ['id' => 0, 'price_vender' => 50, 'amount' => 3, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
            ],
        ]], ['address_id' => $e['suc1']->id, 'total' => $total, 'sub_total' => $total]);

        $de_100 = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $testigo->id)->where('price', 100)->first();
        $de_50 = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $testigo->id)->where('price', 50)->first();

        $this->assertNotNull($de_100);
        $this->assertNotNull($de_50);

        return [$venta, $de_100, $de_50];
    }

    /**
     * @param  object  $fila
     * @return float
     */
    protected function devueltas($fila)
    {
        return (float) DB::table('article_sale')->where('id', $fila->id)->value('returned_amount');
    }

    /**
     * A2 con varios precios: la NC devolvió 1 de la fila de 100 y 3 de la de 50. Borrarla deja las
     * dos filas en 0 (cada renglón de la NC baja SU fila, la del mismo precio) y el stock como
     * después de la venta.
     *
     * @test
     */
    public function borrar_una_nc_de_varios_precios_baja_cada_fila_la_suya()
    {
        $e = $this->escenario();

        list($venta, $de_100, $de_50) = $this->venta_varios_precios($e, 352);

        $nota_credito = $this->devolver($venta, [
            $this->item_devolucion($de_100, 1),
            $this->item_devolucion($de_50, 3),
        ], $e['suc1']->id);

        $this->assertEquals(1.0, $this->devueltas($de_100));
        $this->assertEquals(3.0, $this->devueltas($de_50));
        $this->assertEquals(9.0, $this->stock($e['testigo']), '10 − 5 + 4.');

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assertEquals(0.0, $this->devueltas($de_100), 'La fila de 100 vuelve a 0: la unidad de la NC era suya.');
        $this->assertEquals(0.0, $this->devueltas($de_50), 'La fila de 50 vuelve a 0.');
        $this->assertEquals(5.0, $this->stock($e['testigo']), 'Salen las 4 que había repuesto la NC.');
    }

    /**
     * A3 con el precio editado en Devoluciones: se devuelve solo la fila de 50 × 3, con el precio
     * cambiado a 45. La fila se reconoce por la cantidad vendida y no se reparte entre las dos.
     *
     * @test
     */
    public function una_fila_con_el_precio_editado_se_reconoce_por_la_cantidad()
    {
        $e = $this->escenario();

        list($venta, $de_100, $de_50) = $this->venta_varios_precios($e, 353);

        $item = $this->item_devolucion($de_50, 2);
        $item['price_vender'] = 45;

        $this->devolver($venta, [$item], $e['suc1']->id);

        $this->assertEquals(2.0, $this->devueltas($de_50), 'Las 2 devueltas son de la fila de 50.');
        $this->assertEquals(0.0, $this->devueltas($de_100), 'La fila de 100 no devolvió nada.');
    }

    /**
     * Decisión de A2: en una NC con libro (movimientos atados), un artículo que no repuso nada (el
     * ítem llegó sin stock y no generó movimiento) no toca el stock al borrarla.
     *
     * @test
     */
    public function borrar_la_nc_no_saca_stock_de_un_articulo_que_la_nc_no_repuso()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon_testigo($e, 1),
        ], ['address_id' => $e['suc1']->id]);

        $testigo = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $e['testigo']->id)->first();

        $item_testigo = $this->item_devolucion($testigo, 1);
        $item_testigo['stock'] = null;

        $nota_credito = $this->devolver($venta, [
            $this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['m']), 1),
            $item_testigo,
        ], $e['suc1']->id);

        $this->assertEquals(9.0, $this->stock($e['testigo']), 'El testigo no se repuso (el ítem llegó sin stock).');
        $this->assert_variante($e, 'm', 5, 4, 'La M sí se repuso');

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assertEquals(9.0, $this->stock($e['testigo']), 'Borrar la NC no puede sacar del testigo lo que la NC nunca repuso.');
        $this->assert_variante($e, 'm', 4, 4, 'La M vuelve a como estaba después de la venta');
    }

    /**
     * Decisión de A2: una NC guardada SIN "actualizar unidades devueltas" no sumó nada al renglón;
     * borrarla no lo deja en negativo.
     *
     * @test
     */
    public function borrar_la_nc_no_deja_el_returned_amount_en_negativo()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $nota_credito = $this->devolver(
            $venta,
            [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)],
            $e['suc1']->id,
            ['update_unidades_devueltas' => false]
        );

        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount);

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount, 'No puede quedar en −1.');
        $this->assert_variante($e, 'l', 2, 5, 'El stock igual se deshace');
    }

    /**
     * NC con movimientos atados pero sin la variante en el pivot (las NC entre la auditoría de stock
     * y esta misión): la variante y el depósito salen del movimiento atado.
     *
     * @test
     */
    public function nc_con_libro_pero_sin_variante_en_el_pivot_se_deshace_por_el_movimiento()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $nota_credito = $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)], $e['suc2']->id);

        DB::table('article_current_acount')->where('current_acount_id', $nota_credito->id)->update(['article_variant_id' => null]);

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assert_variante($e, 'l', 2, 5, 'Sale de la L y de la sucursal 2, adonde había entrado');
        $this->assert_variante($e, 'm', 4, 4, 'La M no se toca');
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->returned_amount);
        $this->assertEquals(0.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->returned_amount);
    }

    /**
     * A4 con una variante que NO reparte por depósitos en un artículo cuyas otras variantes sí: la
     * devolución va a su stock global aunque el operador elija sucursal, y no se le abre un depósito.
     *
     * @test
     */
    public function devolver_una_variante_sin_depositos_va_a_su_stock_global()
    {
        $e = $this->escenario();

        $xl = ArticleVariant::create(['article_id' => $e['articulo']->id, 'variant_description' => 'Talle XL', 'stock' => 1]);

        ArticleHelper::setArticleStockFromAddresses($e['articulo']->fresh(), false, $this->usuario()->id);

        $venta = $this->crear_venta([$this->renglon($e, $xl, 1)], ['address_id' => $e['suc1']->id]);

        $this->assertEquals(0.0, $this->stock_variante($xl), 'La XL vendió su única unidad.');

        $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $xl), 1)], $e['suc2']->id);

        $this->assertEquals(1.0, $this->stock_variante($xl), 'La unidad vuelve al stock de la XL.');
        $this->assertEquals(0, DB::table('address_article_variant')->where('article_variant_id', $xl->id)->count(), 'A la XL no se le abre un depósito: sigue llevando stock global.');
        $this->assertEquals(26.0, $this->stock($e['articulo']), '25 de M, L y S más la XL.');
    }
}
