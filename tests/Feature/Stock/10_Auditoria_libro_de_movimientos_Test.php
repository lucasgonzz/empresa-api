<?php

namespace Tests\Feature\Stock;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de stock (5/9/2026) — el libro de movimientos de la venta es la fuente de verdad.
 *
 * Lo que se midió en producción y acá se custodia:
 *
 *  - Borrar una venta reponía la cantidad del renglón aunque nunca se hubiera descontado (el
 *    artículo no llevaba stock cuando se vendió) o aunque una devolución ya la hubiera repuesto
 *    sin registrar `returned_amount`. Ahora repone exactamente lo que la venta tiene en su libro.
 *  - Una segunda nota de crédito por doble clic devolvía dos veces las mismas unidades. Ahora se
 *    rechaza: no se puede devolver más de lo que la venta tiene sin devolver.
 *  - Restaurar desde la papelera deshace exactamente lo que el borrado repuso.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Auditoria_libro_de_movimientos_Test extends AuditoriaStockTestCase
{
    /**
     * Payload de una devolución por el módulo Devoluciones, con la forma que manda la SPA.
     *
     * @param \App\Models\Sale $venta
     * @param \App\Models\Article $articulo
     * @param float $unidades
     * @param array $extra
     * @return array
     */
    protected function payload_devolucion($venta, $articulo, $unidades, $extra = [])
    {
        return array_merge([
            'sale_id'                  => $venta->id,
            'client_id'                => $venta->client_id,
            'address_id'               => $venta->address_id,
            'total_devolucion'         => 300 * $unidades,
            'observaciones'            => 'Devolucion de prueba',
            'generar_current_acount'   => 1,
            'update_unidades_devueltas' => 0,
            'regresar_stock'           => 1,
            'facturar_nota_credito'    => null,
            'discounts'                => [],
            'surchages'                => [],
            'descriptions'             => [],
            'items'                    => [
                [
                    'is_article'         => true,
                    'id'                 => $articulo->id,
                    'name'               => $articulo->name,
                    'stock'              => $this->stock($articulo),
                    'unidades_devueltas' => $unidades,
                    'returned_amount'    => $unidades,
                    'price_vender'       => 300,
                    'discount'           => null,
                    'costo_real'         => $articulo->costo_real,
                ],
            ],
        ], $extra);
    }

    /**
     * @group stock
     * @test
     */
    public function borrar_la_venta_repone_solo_lo_que_la_devolucion_no_repuso_aunque_no_se_haya_registrado()
    {
        $articulo = $this->crear_articulo('zz Auditoria libro devolucion sin registrar', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ]);

        $this->assertEquals(15.0, $this->stock($articulo));

        /* Devolución por Devoluciones con "regresar stock" pero SIN "actualizar unidades devueltas". */
        $this->postJson('api/devoluciones', $this->payload_devolucion($venta, $articulo, 2))->assertStatus(201);

        $this->assertEquals(17.0, $this->stock($articulo), 'La devolución tenía que reponer 2.');

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertTrue(is_null($pivot->returned_amount) || (float) $pivot->returned_amount == 0.0, 'Sin "actualizar unidades devueltas" el renglón no registra la devolución (es el caso medido en producción).');

        $nc = $this->movimientos($articulo, 'Nota de credito')->last();
        $this->assertEquals($venta->id, (int) $nc->sale_id);
        $this->assertNotNull($nc->nota_credito_id, 'La devolución de Devoluciones tiene que quedar atada a su NC.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'Borrar la venta repone 3 (lo que quedaba descontado), no las 5 del renglón.');

        $this->assertEquals(0.0, (float) DB::table('stock_movements')->where('sale_id', $venta->id)->sum('amount'), 'Después del borrado el neto de la venta en el libro es 0.');
    }

    /**
     * @group stock
     * @test
     */
    public function borrar_la_venta_no_repone_un_renglon_que_nunca_desconto()
    {
        /* Sin stock cargado: el artículo no lleva stock, la venta no descuenta. */
        $sin_stock = $this->crear_articulo('zz Auditoria libro sin stock al vender');
        $con_stock = $this->crear_articulo('zz Auditoria libro con stock al vender', ['stock' => 20]);

        $this->assertNull($this->stock($sin_stock));

        $venta = $this->crear_venta([
            ['article' => $sin_stock, 'amount' => 4, 'price' => 300],
            ['article' => $con_stock, 'amount' => 2, 'price' => 300],
        ]);

        $this->assertNull($this->stock($sin_stock), 'Un artículo sin stock no descuenta.');
        $this->assertEquals(18.0, $this->stock($con_stock));

        /* Después de la venta el comercio le carga stock al artículo (un ingreso manual de 10). */
        $this->postJson('api/stock-movement', ['model_id' => $sin_stock->id, 'amount' => 10])->assertStatus(201);
        $this->assertEquals(10.0, $this->stock($sin_stock));

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(10.0, $this->stock($sin_stock), 'Borrar la venta no puede reponer 4 unidades que nunca se descontaron.');
        $this->assertEquals(20.0, $this->stock($con_stock), 'El renglón que sí descontó vuelve entero.');

        $this->assertEquals(0, $this->movimientos($sin_stock, 'Se elimino la venta')->count());
    }

    /**
     * @group stock
     * @test
     */
    public function una_segunda_devolucion_que_excede_lo_vendido_se_rechaza()
    {
        $articulo = $this->crear_articulo('zz Auditoria libro doble NC', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 2, 'price' => 300],
        ]);

        $this->assertEquals(18.0, $this->stock($articulo));

        $this->postJson('api/devoluciones', $this->payload_devolucion($venta, $articulo, 2))->assertStatus(201);

        $this->assertEquals(20.0, $this->stock($articulo));

        /* El doble clic: la misma devolución otra vez. */
        $respuesta = $this->postJson('api/devoluciones', $this->payload_devolucion($venta, $articulo, 2));
        $respuesta->assertStatus(422);
        $this->assertTrue((bool) json_decode($respuesta->getContent(), true)['devolucion_excedida']);

        $this->assertEquals(20.0, $this->stock($articulo), 'La segunda devolución no puede volver a sumar.');
        $this->assertEquals(1, DB::table('current_acounts')->where('sale_id', $venta->id)->where('status', 'nota_credito')->count(), 'La segunda NC tampoco puede entrar a la cuenta corriente.');

        /* Una devolución parcial legítima sigue pasando mientras quede algo por devolver. */
        $venta2 = $this->crear_venta([
            ['article' => $articulo, 'amount' => 3, 'price' => 300],
        ]);

        $this->postJson('api/devoluciones', $this->payload_devolucion($venta2, $articulo, 1))->assertStatus(201);
        $this->postJson('api/devoluciones', $this->payload_devolucion($venta2, $articulo, 2))->assertStatus(201);
        $this->postJson('api/devoluciones', $this->payload_devolucion($venta2, $articulo, 1))->assertStatus(422);

        $this->assertEquals(20.0, $this->stock($articulo));
    }

    /**
     * @group stock
     * @test
     */
    public function restaurar_desde_la_papelera_deshace_exactamente_lo_que_el_borrado_repuso()
    {
        $articulo = $this->crear_articulo('zz Auditoria libro papelera', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ]);

        /* Devolución sin registrar en el renglón, como en producción. */
        $this->postJson('api/devoluciones', $this->payload_devolucion($venta, $articulo, 2))->assertStatus(201);
        $this->assertEquals(17.0, $this->stock($articulo));

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);
        $this->assertEquals(20.0, $this->stock($articulo));

        $this->putJson('api/papelera/restaurar/sale/'.$venta->id)->assertStatus(200);

        $this->assertNull(Sale::withTrashed()->find($venta->id)->deleted_at, 'La venta tenía que volver de la papelera.');
        $this->assertEquals(17.0, $this->stock($articulo), 'Restaurar vuelve a descontar las 3 que el borrado repuso, no las 5 del renglón.');
        $this->assertEquals(-3.0, (float) DB::table('stock_movements')->where('sale_id', $venta->id)->sum('amount'), 'El libro de la venta vuelve a su neto original.');
    }
}
