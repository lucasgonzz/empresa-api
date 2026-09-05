<?php

namespace Tests\Feature\Stock;

use App\Models\ArticleVariant;
use App\Models\Buyer;
use App\Models\Combo;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de stock (5/9/2026) — variantes, combos, pedidos online y varios precios.
 *
 * Lo que estaba roto en develop d0aa0692 (v4.0.15):
 *
 *  - La venta que nace de confirmar un pedido online no llevaba la variante del renglón: en un
 *    artículo con variantes el movimiento no tocaba ningún stock.
 *  - Actualizar una venta con dos variantes del mismo artículo comparaba por id y se quedaba con
 *    el último renglón: generaba un "Act Venta" espurio, y sacar una variante no la devolvía.
 *  - Los combos descontaban stock aunque la venta tuviera discount_stock en 0, pero el borrado
 *    solo devolvía con discount_stock en 1.
 *  - Con varios precios, el stock descontaba la cantidad del item padre y no la suma de renglones.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Auditoria_variantes_combos_y_pedidos_Test extends AuditoriaStockTestCase
{
    /**
     * Artículo con dos variantes de 10 unidades cada una (stock global = 20).
     *
     * @param string $nombre
     * @return array{0:\App\Models\Article,1:\App\Models\ArticleVariant,2:\App\Models\ArticleVariant}
     */
    protected function articulo_con_variantes($nombre)
    {
        $articulo = $this->crear_articulo($nombre, ['stock' => 20]);

        $m = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle M', 'stock' => 10]);
        $l = ArticleVariant::create(['article_id' => $articulo->id, 'variant_description' => 'Talle L', 'stock' => 10]);

        return [$articulo->fresh(), $m, $l];
    }

    /**
     * @param \App\Models\ArticleVariant $variante
     * @return float
     */
    protected function stock_variante($variante)
    {
        return (float) DB::table('article_variants')->where('id', $variante->id)->value('stock');
    }

    /**
     * @param string $name
     * @return \App\Models\OrderStatus
     */
    protected function order_status($name)
    {
        return OrderStatus::firstOrCreate(['name' => $name]);
    }

    /**
     * @group stock
     * @test
     */
    public function confirmar_un_pedido_online_con_variante_descuenta_la_variante()
    {
        list($articulo, $m, $l) = $this->articulo_con_variantes('zz Auditoria pedido con variante');

        $buyer = Buyer::create([
            'user_id' => $this->usuario()->id,
            'name'    => 'Comprador auditoria',
            'email'   => 'comprador.auditoria.stock@example.com',
        ]);

        $order = Order::create([
            'user_id'         => $this->usuario()->id,
            'buyer_id'        => $buyer->id,
            'order_status_id' => $this->order_status('Sin confirmar')->id,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'total'           => 900,
            'address_id'      => null,
            'fecha_entrega'   => null,
            'seller_id'       => null,
        ]);

        $order->articles()->attach($articulo->id, [
            'amount'     => 3,
            'price'      => 300,
            'cost'       => $articulo->cost,
            'variant_id' => $m->id,
        ]);

        $this->putJson('api/order/'.$order->id, [
            'order_status_id' => $this->order_status('Confirmado')->id,
        ])->assertStatus(200);

        $venta = Sale::where('order_id', $order->id)->first();
        $this->assertNotNull($venta, 'Confirmar el pedido tenía que crear la venta.');

        $pivot = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertEquals($m->id, (int) $pivot->article_variant_id, 'El renglón de la venta tiene que decir qué variante se vendió.');

        $this->assertEquals(7.0, $this->stock_variante($m), 'La variante M tenía que bajar de 10 a 7.');
        $this->assertEquals(10.0, $this->stock_variante($l), 'La variante L no se vendió.');
        $this->assertEquals(17.0, $this->stock($articulo), 'El stock del artículo es la suma de sus variantes.');

        $movimiento = $this->movimientos($articulo, 'Venta')->last();
        $this->assertEquals($m->id, (int) $movimiento->article_variant_id);
        $this->assertEquals(-3.0, (float) $movimiento->amount);
    }

    /**
     * @group stock
     * @test
     */
    public function actualizar_una_venta_con_dos_variantes_del_mismo_articulo_mueve_solo_la_diferencia()
    {
        list($articulo, $m, $l) = $this->articulo_con_variantes('zz Auditoria dos variantes');

        $renglones = [
            ['article' => $articulo, 'amount' => 2, 'price' => 300, 'article_variant_id' => $m->id],
            ['article' => $articulo, 'amount' => 3, 'price' => 300, 'article_variant_id' => $l->id],
        ];

        $venta = $this->crear_venta($renglones);

        $this->assertEquals(8.0, $this->stock_variante($m));
        $this->assertEquals(7.0, $this->stock_variante($l));
        $this->assertEquals(15.0, $this->stock($articulo));

        $movimientos_antes = $this->movimientos($articulo)->count();

        /* 1. Guardar sin cambiar nada: ni un movimiento nuevo. */
        $this->actualizar_venta($venta, $renglones)->assertStatus(200);

        $this->assertEquals(8.0, $this->stock_variante($m), 'Guardar sin cambios no puede mover la variante M.');
        $this->assertEquals(7.0, $this->stock_variante($l), 'Guardar sin cambios no puede mover la variante L.');
        $this->assertEquals($movimientos_antes, $this->movimientos($articulo)->count(), 'Guardar sin cambios no deja movimientos.');

        /* 2. La M sube de 2 a 4: se mueve la diferencia de la M y nada de la L. */
        $this->actualizar_venta($venta, [
            ['article' => $articulo, 'amount' => 4, 'price' => 300, 'article_variant_id' => $m->id],
            ['article' => $articulo, 'amount' => 3, 'price' => 300, 'article_variant_id' => $l->id],
        ])->assertStatus(200);

        $this->assertEquals(6.0, $this->stock_variante($m));
        $this->assertEquals(7.0, $this->stock_variante($l));
        $this->assertEquals(13.0, $this->stock($articulo));

        $ajuste = $this->movimientos($articulo, 'Act Venta')->last();
        $this->assertNotNull($ajuste);
        $this->assertEquals($m->id, (int) $ajuste->article_variant_id);
        $this->assertEquals(-2.0, (float) $ajuste->amount);

        /* 3. Se saca la L: vuelven sus 3 unidades, la M queda como estaba. */
        $this->actualizar_venta($venta, [
            ['article' => $articulo, 'amount' => 4, 'price' => 300, 'article_variant_id' => $m->id],
        ])->assertStatus(200);

        $this->assertEquals(6.0, $this->stock_variante($m));
        $this->assertEquals(10.0, $this->stock_variante($l), 'Sacar la variante L de la venta tenía que devolverle sus 3 unidades.');
        $this->assertEquals(16.0, $this->stock($articulo));

        $devuelto = $this->movimientos($articulo, 'Se elimino de la venta')->last();
        $this->assertNotNull($devuelto);
        $this->assertEquals($l->id, (int) $devuelto->article_variant_id);
        $this->assertEquals(3.0, (float) $devuelto->amount);

        /* 4. Borrar la venta repone la M y deja las dos en 10. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(10.0, $this->stock_variante($m));
        $this->assertEquals(10.0, $this->stock_variante($l));
        $this->assertEquals(20.0, $this->stock($articulo));
    }

    /**
     * @group stock
     * @test
     */
    public function un_combo_descuenta_y_devuelve_solo_si_la_venta_descuenta_stock()
    {
        $componente = $this->crear_articulo('zz Auditoria componente de combo', ['stock' => 20]);

        $combo = Combo::create([
            'num'     => 9001,
            'name'    => 'zz Combo auditoria',
            'price'   => 500,
            'cost'    => 200,
            'user_id' => $this->usuario()->id,
        ]);

        $combo->articles()->attach($componente->id, ['amount' => 2]);

        $renglon_combo = [
            'is_combo'     => true,
            'id'           => $combo->id,
            'name'         => $combo->name,
            'amount'       => 1,
            'price_vender' => 500,
            'articles'     => [
                ['id' => $componente->id, 'name' => $componente->name, 'pivot' => ['amount' => 2]],
            ],
        ];

        /* Venta que NO descuenta stock: el combo no toca el componente. */
        $sin_descuento = $this->crear_venta([$renglon_combo], ['discount_stock' => 0]);

        $this->assertEquals(20.0, $this->stock($componente), 'Con discount_stock en 0 el combo no descuenta.');
        $this->assertEquals(0, $this->movimientos($componente)->count());

        $this->deleteJson('api/sale/'.$sin_descuento->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente), 'Borrar una venta sin descuento de stock no repone nada.');

        /* Venta que SÍ descuenta: 1 combo x 2 unidades del componente. */
        $con_descuento = $this->crear_venta([$renglon_combo], ['discount_stock' => 1]);

        $this->assertEquals(18.0, $this->stock($componente), 'El combo tenía que descontar 2 del componente.');

        $venta_combo = $this->movimientos($componente, 'Venta')->last();
        $this->assertEquals($con_descuento->id, (int) $venta_combo->sale_id);
        $this->assertEquals(-2.0, (float) $venta_combo->amount);

        $this->deleteJson('api/sale/'.$con_descuento->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($componente), 'Borrar la venta tenía que devolver las 2 unidades del combo.');
    }

    /**
     * @group stock
     * @test
     */
    public function con_varios_precios_el_stock_descuenta_la_suma_de_los_renglones()
    {
        $articulo = $this->crear_articulo('zz Auditoria varios precios', ['stock' => 20]);

        $renglon = [
            'is_article'     => true,
            'id'             => $articulo->id,
            'name'           => $articulo->name,
            'price_vender'   => 300,
            'amount'         => 1,
            'varios_precios' => [
                ['id' => 0, 'price_vender' => 300, 'amount' => 2],
                ['id' => 1, 'price_vender' => 280, 'amount' => 3],
            ],
        ];

        $venta = $this->crear_venta([$renglon]);

        $this->assertEquals(2, DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->count(), 'Varios precios deja un renglón por precio.');

        $this->assertEquals(15.0, $this->stock($articulo), 'El stock tenía que descontar 2 + 3, no la cantidad del item padre.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(20.0, $this->stock($articulo), 'Borrar la venta repone los dos renglones.');
    }
}
