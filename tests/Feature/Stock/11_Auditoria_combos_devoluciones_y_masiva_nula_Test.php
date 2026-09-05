<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Models\ArticleVariant;
use App\Models\Combo;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de stock (5/9/2026) — segunda tanda, salida de los chequeos independientes.
 *
 * Lo que se cubre:
 *  - Sacar un combo de una venta confirmada no devolvía los artículos que lo componían.
 *  - Activar discount_stock en una venta que ya tenía un combo dejaba el combo sin descontar
 *    (la diferencia contra el combo previo daba 1 − 1 = 0).
 *  - Una nota de crédito sobre una venta que nunca descontó stock igual lo sumaba.
 *  - Sacar de la venta un renglón con una devolución parcial devolvía el renglón entero (y la
 *    parte ya devuelta quedaba sumada dos veces).
 *  - La masiva sobre un artículo SIN stock: fijar 0 lo deja llevando stock, y revertir lo vuelve
 *    a "sin stock" (null), no a 0.
 *  - Una variante que reparte por depósitos: la venta desde una sucursal descuenta ese depósito
 *    de la variante, el de la sucursal en el artículo y los dos globales; borrarla repone todo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Auditoria_combos_devoluciones_y_masiva_nula_Test extends AuditoriaStockTestCase
{
    /**
     * Combo propio de 2 unidades del componente, con su renglón tal como lo manda Vender.
     *
     * @param \App\Models\Article $componente
     * @return array{0:\App\Models\Combo,1:array}
     */
    protected function combo_con($componente)
    {
        $combo = Combo::create([
            'num'     => 9002,
            'name'    => 'zz Combo auditoria 2',
            'price'   => 500,
            'cost'    => 200,
            'user_id' => $this->usuario()->id,
        ]);

        $combo->articles()->attach($componente->id, ['amount' => 2]);

        $renglon = [
            'is_combo'     => true,
            'id'           => $combo->id,
            'name'         => $combo->name,
            'amount'       => 1,
            'price_vender' => 500,
            'articles'     => [
                ['id' => $componente->id, 'name' => $componente->name, 'pivot' => ['amount' => 2]],
            ],
        ];

        return [$combo, $renglon];
    }

    /**
     * @group stock
     * @test
     */
    public function sacar_un_combo_de_una_venta_devuelve_los_articulos_que_lo_componen()
    {
        $componente = $this->crear_articulo('zz Auditoria componente combo sacado', ['stock' => 20]);
        $suelto = $this->crear_articulo('zz Auditoria suelto junto al combo', ['stock' => 10]);

        list($combo, $renglon_combo) = $this->combo_con($componente);

        $renglon_suelto = ['article' => $suelto, 'amount' => 1, 'price' => 300];

        $venta = $this->crear_venta([$renglon_combo, $renglon_suelto]);

        $this->assertEquals(18.0, $this->stock($componente), 'El combo tenía que descontar 2 del componente.');
        $this->assertEquals(9.0, $this->stock($suelto));

        /* Se saca el combo: queda solo el artículo suelto. */
        $this->actualizar_venta($venta, [$renglon_suelto])->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente), 'Sacar el combo de la venta tenía que devolver sus 2 unidades.');
        $this->assertEquals(9.0, $this->stock($suelto), 'El artículo suelto no se tocó.');

        $devuelto = $this->movimientos($componente, 'Se elimino de la venta')->last();
        $this->assertNotNull($devuelto, 'La devolución del combo tiene que quedar en el libro.');
        $this->assertEquals(2.0, (float) $devuelto->amount);
        $this->assertEquals($venta->id, (int) $devuelto->sale_id);

        /* Guardar de nuevo sin el combo no vuelve a devolver nada. */
        $this->actualizar_venta($venta, [$renglon_suelto])->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente), 'Un segundo guardado sin el combo no puede volver a devolverlo.');

        /* Y borrar la venta repone solo el suelto: el combo ya había vuelto. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente));
        $this->assertEquals(10.0, $this->stock($suelto));
    }

    /**
     * @group stock
     * @test
     */
    public function activar_el_descuento_de_stock_en_una_venta_con_combo_lo_descuenta_entero()
    {
        $componente = $this->crear_articulo('zz Auditoria combo activado despues', ['stock' => 20]);

        list($combo, $renglon_combo) = $this->combo_con($componente);

        $venta = $this->crear_venta([$renglon_combo], ['discount_stock' => 0]);

        $this->assertEquals(20.0, $this->stock($componente), 'Con discount_stock en 0 el combo no descuenta.');

        /* Se activa el descuento con el mismo combo: recién ahora se descuenta, entero. */
        $this->actualizar_venta($venta, [$renglon_combo], ['discount_stock' => 1])->assertStatus(200);

        $this->assertEquals(18.0, $this->stock($componente), 'Al activar el descuento el combo tenía que descontar sus 2 unidades.');

        $movimiento = $this->movimientos($componente, 'Venta')->last();
        $this->assertNotNull($movimiento);
        $this->assertEquals(-2.0, (float) $movimiento->amount);
        $this->assertEquals($venta->id, (int) $movimiento->sale_id);

        /* Guardar otra vez, ya con el descuento activo, no mueve nada. */
        $this->actualizar_venta($venta, [$renglon_combo], ['discount_stock' => 1])->assertStatus(200);

        $this->assertEquals(18.0, $this->stock($componente), 'Guardar sin cambios no puede volver a descontar el combo.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente), 'Borrar la venta devuelve las 2 del combo.');
    }

    /**
     * @group stock
     * @test
     */
    public function una_devolucion_sobre_una_venta_que_no_desconto_stock_no_suma_stock()
    {
        $articulo = $this->crear_articulo('zz Auditoria NC sin descuento', ['stock' => 20]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ], ['discount_stock' => 0]);

        $this->assertEquals(20.0, $this->stock($articulo), 'La venta no descuenta stock.');

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
            'id'                       => $venta->id,
            'discount_stock'           => 0,
            'save_nota_credito'        => 1,
            'nota_credito_description' => 'Devolucion sobre venta sin descuento',
            'returned_items'           => [$renglon],
        ]))->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'La NC no puede sumar unidades que la venta nunca descontó.');
        $this->assertEquals(0, $this->movimientos($articulo, 'Nota de credito')->count(), 'Sin descuento previo no hay movimiento de NC.');

        /* La plata sí: la devolución queda registrada en el renglón. */
        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertEquals(2.0, (float) $pivot->returned_amount);

        /* Por el módulo de Devoluciones, con "regresar stock" marcado, pasa lo mismo. */
        $this->postJson('api/devoluciones', [
            'sale_id'                   => $venta->id,
            'client_id'                 => $venta->client_id,
            'address_id'                => $venta->address_id,
            'generar_current_acount'    => false,
            'total_devolucion'          => 300,
            'observaciones'             => 'Devolucion por el modulo',
            'items'                     => [array_merge($renglon, ['stock' => 20, 'unidades_devueltas' => 1, 'returned_amount' => 1])],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => true,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        $this->assertEquals(20.0, $this->stock($articulo), 'Devoluciones tampoco puede sumar lo que nunca salió.');
        $this->assertEquals(0, $this->movimientos($articulo, 'Nota de credito')->count());
    }

    /**
     * @group stock
     * @test
     */
    public function sacar_un_renglon_con_devolucion_parcial_devuelve_solo_lo_pendiente()
    {
        $articulo = $this->crear_articulo('zz Auditoria renglon con NC parcial', ['stock' => 20]);
        $otro = $this->crear_articulo('zz Auditoria otro renglon', ['stock' => 10]);

        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 5, 'price' => 300],
        ]);

        $this->assertEquals(15.0, $this->stock($articulo));

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
            'id'                       => $venta->id,
            'save_nota_credito'        => 1,
            'nota_credito_description' => 'Devolucion de 2',
            'returned_items'           => [$renglon],
        ]))->assertStatus(200);

        $this->assertEquals(17.0, $this->stock($articulo), 'La NC devolvió 2: quedan 3 descontadas.');

        /* Se reemplaza el renglón por otro artículo: vuelven las 3 pendientes, no las 5. */
        $this->actualizar_venta($venta, [
            ['article' => $otro, 'amount' => 1, 'price' => 300],
        ])->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'Sacar el renglón tenía que devolver las 3 que faltaban, no las 5 vendidas.');
        $this->assertEquals(9.0, $this->stock($otro));

        $devuelto = $this->movimientos($articulo, 'Se elimino de la venta')->last();
        $this->assertEquals(3.0, (float) $devuelto->amount);

        /* Borrar la venta ya no toca al artículo sacado: su libro está en cero. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo));
        $this->assertEquals(10.0, $this->stock($otro));
    }

    /**
     * @group stock
     * @test
     */
    public function la_masiva_sobre_un_articulo_sin_stock_lo_deja_en_cero_y_revertir_lo_vuelve_a_sin_stock()
    {
        $user = $this->usuario();

        $articulo = $this->crear_articulo('zz Auditoria masiva sin stock', ['stock' => null]);

        $this->assertNull($this->stock($articulo), 'El artículo arranca sin stock.');

        $criteria = [
            'from_filter'        => false,
            'used_filters'       => [],
            'update_form'        => [
                ['type' => 'number', 'key' => 'set_stock', 'value' => 0],
            ],
            'models_id'          => [$articulo->id],
            'resolved_models_id' => [$articulo->id],
            'filter_form'        => [],
        ];

        $masiva = MasiveUpdateHelper::create_pending_update($user->id, $user->id, 'article', false, $criteria);

        MasiveUpdateHelper::process_update($masiva);

        $this->assertSame(0.0, $this->stock($articulo), 'Fijar 0 sobre un artículo sin stock lo tiene que dejar llevando stock, en 0.');
        $this->assertEquals(0, $this->movimientos($articulo)->count(), 'De null a 0 no hay cantidad que mover: no deja movimiento.');

        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $user->id);

        MasiveUpdateHelper::process_revert($reversion, $masiva->fresh());

        $this->assertNull($this->stock($articulo), 'Revertir tiene que devolver el artículo a "sin stock", no dejarlo en 0.');

        /* Y de null a 25: el movimiento deja 25, y revertir vuelve a null con el libro en cero. */
        $criteria['update_form'] = [['type' => 'number', 'key' => 'set_stock', 'value' => 25]];

        $masiva = MasiveUpdateHelper::create_pending_update($user->id, $user->id, 'article', false, $criteria);

        MasiveUpdateHelper::process_update($masiva);

        $this->assertEquals(25.0, $this->stock($articulo));
        $this->assertEquals(25.0, (float) $this->movimientos($articulo)->last()->amount);

        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $user->id);

        MasiveUpdateHelper::process_revert($reversion, $masiva->fresh());

        $this->assertNull($this->stock($articulo), 'Revertir vuelve a "sin stock".');
        $this->assertEquals(0.0, (float) $this->movimientos($articulo)->sum('amount'), 'El libro queda en neto cero.');
    }

    /**
     * @group stock
     * @test
     */
    public function vender_y_borrar_una_variante_con_depositos_mueve_el_deposito_de_la_variante_y_el_del_articulo()
    {
        $user = $this->usuario();

        $a = $this->sucursal();
        $b = $this->segunda_sucursal();

        $articulo = $this->crear_articulo('zz Auditoria variante con depositos', ['stock' => 20]);

        $m = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle M', 'stock' => 10]);
        $l = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle L', 'stock' => 10]);

        /* M reparte 6 en A y 4 en B; L, 5 y 5. El artículo se arma desde las variantes, por el camino real. */
        DB::table('address_article_variant')->insert([
            ['article_variant_id' => $m->id, 'address_id' => $a->id, 'amount' => 6],
            ['article_variant_id' => $m->id, 'address_id' => $b->id, 'amount' => 4],
            ['article_variant_id' => $l->id, 'address_id' => $a->id, 'amount' => 5],
            ['article_variant_id' => $l->id, 'address_id' => $b->id, 'amount' => 5],
        ]);

        ArticleHelper::setArticleStockFromAddresses($articulo->fresh(), false, $user->id);

        $this->assertEquals(20.0, $this->stock($articulo));
        $this->assertEquals(11.0, $this->stock_en_deposito($articulo, $a->id), 'El artículo en A es la suma de sus variantes en A.');
        $this->assertEquals(9.0, $this->stock_en_deposito($articulo, $b->id));

        /* Venta desde A de 2 unidades de la M. */
        $venta = $this->crear_venta([
            ['article' => $articulo, 'amount' => 2, 'price' => 300, 'article_variant_id' => $m->id],
        ], ['address_id' => $a->id]);

        $this->assertEquals(4.0, $this->deposito_variante($m, $a->id), 'La M en A tenía que bajar de 6 a 4.');
        $this->assertEquals(4.0, $this->deposito_variante($m, $b->id), 'La M en B no se toca.');
        $this->assertEquals(8.0, (float) DB::table('article_variants')->where('id', $m->id)->value('stock'), 'El global de la M es la suma de sus depósitos.');
        $this->assertEquals(10.0, (float) DB::table('article_variants')->where('id', $l->id)->value('stock'), 'La L no se toca.');
        $this->assertEquals(9.0, $this->stock_en_deposito($articulo, $a->id), 'El artículo en A baja con la variante.');
        $this->assertEquals(9.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(18.0, $this->stock($articulo));

        $movimiento = $this->movimientos($articulo, 'Venta')->last();
        $this->assertEquals($m->id, (int) $movimiento->article_variant_id);
        $this->assertEquals($a->id, (int) $movimiento->from_address_id);
        $this->assertEquals(-2.0, (float) $movimiento->amount);

        /* Borrar la venta repone en el mismo depósito de la misma variante. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(6.0, $this->deposito_variante($m, $a->id), 'Borrar la venta tenía que devolver las 2 a la M en A.');
        $this->assertEquals(4.0, $this->deposito_variante($m, $b->id));
        $this->assertEquals(10.0, (float) DB::table('article_variants')->where('id', $m->id)->value('stock'));
        $this->assertEquals(11.0, $this->stock_en_deposito($articulo, $a->id));
        $this->assertEquals(9.0, $this->stock_en_deposito($articulo, $b->id));
        $this->assertEquals(20.0, $this->stock($articulo));
    }

    /**
     * @param \App\Models\ArticleVariant $variante
     * @param int $address_id
     * @return float|null
     */
    protected function deposito_variante($variante, $address_id)
    {
        $fila = DB::table('address_article_variant')->where('article_variant_id', $variante->id)->where('address_id', $address_id)->first();

        return is_null($fila) || is_null($fila->amount) ? null : (float) $fila->amount;
    }
}
