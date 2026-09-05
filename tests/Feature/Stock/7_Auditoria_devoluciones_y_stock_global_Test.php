<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\SaleController;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de stock (5/9/2026) — lo que se devuelve al stock vuelve al lugar de donde salió, y
 * una sola vez.
 *
 * Los cuatro caminos que acá se custodian estaban rotos en develop d0aa0692 (v4.0.15):
 *
 *  - Borrar la venta de un artículo con stock GLOBAL abría un depósito con lo repuesto y pisaba
 *    el stock global (20 → 19 → 1). Lo mismo cualquier ingreso con depósito sobre ese artículo.
 *  - La nota de crédito desde Vender quedaba como "Ingreso manual" sin venta: al borrar la venta
 *    después se reponía la cantidad completa (lo devuelto volvía dos veces), y en un artículo
 *    con unidades_individuales la cantidad devuelta se multiplicaba.
 *  - Borrar una venta dos veces (dos clics antes de que termine el primero) devolvía el stock dos
 *    veces: `destroy()` corría sin transacción ni candado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Auditoria_devoluciones_y_stock_global_Test extends AuditoriaStockTestCase
{
    /**
     * @group stock
     * @test
     */
    public function borrar_una_venta_repone_el_stock_global_sin_abrir_un_deposito()
    {
        $articulo = $this->crear_articulo('zz Auditoria stock global', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 3, 'price' => 300],
        ]);

        $this->assertEquals(17.0, $this->stock($articulo), 'La venta tenía que descontar 3 del stock global.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'Borrar la venta tenía que devolver el stock global a 20.');

        $this->assertEquals(0, $this->cantidad_de_depositos($articulo), 'El borrado no puede abrirle un depósito a un artículo que lleva stock global.');

        $devolucion = $this->movimientos($articulo, 'Se elimino la venta')->last();

        $this->assertNotNull($devolucion, 'Faltó el movimiento "Se elimino la venta".');
        $this->assertEquals(3.0, (float) $devolucion->amount);
        $this->assertEquals($venta->id, (int) $devolucion->sale_id);
        $this->assertEquals(20.0, (float) $devolucion->stock_resultante, 'El stock_resultante tiene que ser el stock real después del movimiento.');
    }

    /**
     * Un ingreso que elige depósito sobre un artículo que lleva stock global va al global. Solo
     * "Creacion de deposito" (el reparto explícito) abre el primer depósito.
     *
     * @group stock
     * @test
     */
    public function un_ingreso_con_deposito_sobre_stock_global_va_al_global_y_solo_el_reparto_abre_el_deposito()
    {
        $articulo = $this->crear_articulo('zz Auditoria ingreso a deposito', ['stock' => 20]);
        $sucursal = $this->sucursal();

        $this->postJson('api/stock-movement', [
            'model_id'      => $articulo->id,
            'amount'        => 5,
            'to_address_id' => $sucursal->id,
        ])->assertStatus(201);

        $this->assertEquals(25.0, $this->stock($articulo), 'El ingreso tenía que sumar al stock global.');
        $this->assertEquals(0, $this->cantidad_de_depositos($articulo), 'Un ingreso manual no abre depósitos en un artículo con stock global.');

        /* El reparto explícito (modal de crear depósitos) SÍ abre el depósito con lo repartido. */
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => 25,
            'to_address_id'                => $sucursal->id,
            'concepto_stock_movement_name' => 'Creacion de deposito',
        ])->assertStatus(201);

        $this->assertEquals(25.0, $this->stock_en_deposito($articulo, $sucursal->id), 'El reparto tenía que abrir el depósito con 25.');
        $this->assertEquals(25.0, $this->stock($articulo), 'Con el depósito abierto, el stock global es la suma de depósitos.');

        /* Y desde ahí el artículo reparte: una venta sale del depósito y el borrado vuelve a él. */
        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 4, 'price' => 300],
        ]);

        $this->assertEquals(21.0, $this->stock_en_deposito($articulo, $sucursal->id));
        $this->assertEquals(21.0, $this->stock($articulo));

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(25.0, $this->stock_en_deposito($articulo, $sucursal->id));
        $this->assertEquals(25.0, $this->stock($articulo));
    }

    /**
     * @group stock
     * @test
     */
    public function la_nota_de_credito_desde_vender_queda_como_nota_de_credito_y_no_se_multiplica()
    {
        /* unidades_individuales = 6: un "Ingreso manual" de 2 se convertiría en 12. */
        $articulo = $this->crear_articulo('zz Auditoria NC desde vender', ['stock' => 20, 'unidades_individuales' => 6]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ]);

        $this->assertEquals(15.0, $this->stock($articulo));

        /* El panel "Nota de crédito" de Vender: la venta se actualiza con save_nota_credito y returned_items. */
        $renglon = [
            'is_article'         => true,
            'id'                 => $articulo->id,
            'name'               => $articulo->name,
            'price_vender'       => 300,
            'amount'             => 5,
            'returned_amount'    => 2,
            'unidades_devueltas' => 2,
            'discount'           => null,
            'costo_real'         => $articulo->costo_real,
        ];

        $this->putJson('api/sale/'.$venta->id, $this->payload_venta([$renglon], [
            'id'                      => $venta->id,
            'save_nota_credito'       => 1,
            'nota_credito_description' => 'Devolucion de 2 unidades',
            'returned_items'          => [$renglon],
        ]))->assertStatus(200);

        $this->assertEquals(17.0, $this->stock($articulo), 'La NC tenía que devolver 2 unidades, ni 12 ni 0.');

        $nc = $this->movimientos($articulo, 'Nota de credito')->last();

        $this->assertNotNull($nc, 'La devolución de Vender tiene que quedar con concepto "Nota de credito", no "Ingreso manual".');
        $this->assertEquals(2.0, (float) $nc->amount, 'La cantidad devuelta no se multiplica por unidades_individuales.');
        $this->assertEquals($venta->id, (int) $nc->sale_id, 'El movimiento de la NC tiene que quedar atado a la venta.');
        $this->assertNotNull($nc->nota_credito_id, 'El movimiento de la NC tiene que quedar atado a la nota de crédito.');
        $this->assertEquals(0, $this->cantidad_de_depositos($articulo), 'La NC no abre depósitos en un artículo con stock global.');

        $this->assertEquals(0, $this->movimientos($articulo, 'Ingreso manual')->count(), 'No tiene que haber ningún "Ingreso manual" espurio.');

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertEquals(2.0, (float) $pivot->returned_amount);

        /* Devolver de más desde el mismo panel (quedan 3 sin devolver, se piden 4): 422 con motivo, y nada se mueve. */
        $renglon_excedido = $renglon;
        $renglon_excedido['returned_amount'] = 4;
        $renglon_excedido['unidades_devueltas'] = 4;

        $respuesta = $this->putJson('api/sale/'.$venta->id, $this->payload_venta([$renglon_excedido], [
            'id'                      => $venta->id,
            'save_nota_credito'       => 1,
            'nota_credito_description' => 'Devolucion de mas',
            'returned_items'          => [$renglon_excedido],
        ]));
        $respuesta->assertStatus(422);
        $this->assertTrue((bool) json_decode($respuesta->getContent(), true)['devolucion_excedida']);
        $this->assertEquals(17.0, $this->stock($articulo), 'La devolución rechazada no puede mover stock.');

        /* Borrar la venta después repone lo que quedaba vendido (3), no las 5. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'Borrar la venta tenía que reponer 3 (5 vendidas menos 2 ya devueltas), y dejar 20.');

        $devolucion = $this->movimientos($articulo, 'Se elimino la venta')->last();
        $this->assertEquals(3.0, (float) $devolucion->amount);
    }

    /**
     * @group stock
     * @test
     */
    public function borrar_la_nota_de_credito_deshace_su_devolucion_de_stock()
    {
        $articulo = $this->crear_articulo('zz Auditoria borrar NC', ['stock' => 20, 'unidades_individuales' => 6]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ]);

        $renglon = [
            'is_article'         => true,
            'id'                 => $articulo->id,
            'name'               => $articulo->name,
            'price_vender'       => 300,
            'amount'             => 5,
            'returned_amount'    => 2,
            'unidades_devueltas' => 2,
            'discount'           => null,
            'costo_real'         => $articulo->costo_real,
        ];

        $this->putJson('api/sale/'.$venta->id, $this->payload_venta([$renglon], [
            'id'                      => $venta->id,
            'save_nota_credito'       => 1,
            'nota_credito_description' => 'Devolucion de 2 unidades',
            'returned_items'          => [$renglon],
        ]))->assertStatus(200);

        $this->assertEquals(17.0, $this->stock($articulo));

        $nota_credito = CurrentAcount::where('sale_id', $venta->id)->where('status', 'nota_credito')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($nota_credito, 'La NC tenía que quedar en la cuenta corriente.');

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assertEquals(15.0, $this->stock($articulo), 'Borrar la NC tenía que sacar del stock las 2 unidades que había devuelto.');

        $reverso = $this->movimientos($articulo, 'Nota de credito')->last();
        $this->assertEquals(-2.0, (float) $reverso->amount, 'El reverso de la NC se registra como "Nota de credito" en negativo, sin multiplicar.');
        $this->assertEquals($venta->id, (int) $reverso->sale_id);

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertEquals(0.0, (float) $pivot->returned_amount);

        /* Y borrar la venta ahora repone las 5 enteras, porque la devolución quedó anulada. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo));
    }

    /**
     * Dos borrados de la misma venta (el segundo con una instancia vieja, como un segundo request
     * que ya la había cargado) devuelven el stock UNA sola vez.
     *
     * @group stock
     * @test
     */
    public function eliminar_la_venta_dos_veces_devuelve_el_stock_una_sola_vez()
    {
        $articulo = $this->crear_articulo('zz Auditoria doble borrado', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 2, 'price' => 300],
        ]);

        $this->assertEquals(18.0, $this->stock($articulo));

        /* La "segunda pestaña": ya tiene la venta cargada antes de que la primera la borre. */
        $instancia_vieja = Sale::find($venta->id);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo));

        $resultado = DeleteSaleHelper::eliminar_venta($instancia_vieja, new SaleController());

        $this->assertFalse($resultado, 'La segunda baja tenía que detectar que la venta ya estaba eliminada.');

        $this->assertEquals(20.0, $this->stock($articulo), 'El segundo borrado no puede volver a reponer el stock.');

        $this->assertEquals(1, $this->movimientos($articulo, 'Se elimino la venta')->count(), 'Tiene que haber un solo "Se elimino la venta".');

        /* Y el endpoint responde 404 sin tocar nada. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(404);

        $this->assertEquals(20.0, $this->stock($articulo));
    }
}
