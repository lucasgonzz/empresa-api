<?php

namespace Tests\Feature\AjustesDelCliente;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Combo;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Surchage;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión descuentos-recargos-por-cliente (23/9/2026): un pedido de la tienda que se priceó con los
 * descuentos y recargos del cliente llega a la venta con esos mismos ajustes colgados y con los
 * renglones A PRECIO SIN AJUSTAR.
 *
 * EL CONTRATO. La tienda guarda en `article_order.price` el precio que pagó el comprador, YA
 * ajustado (un artículo de 1000 con 10% de descuento y 5% de recargo va a 945), y deja en
 * `discount_order` / `order_surchage` la foto de los porcentajes. Al confirmar, la venta tiene que
 * quedar igual a una hecha en Vender: renglón a 1000 y los pivots 10 y 5 en `discount_sale` /
 * `sale_surchage`.
 *
 * POR QUÉ NO ALCANZA CON COPIAR EL 945. `SaleHelper::getTotalSale()` —y la factura, y los puntos—
 * recalculan el total desde la venta aplicando los pivots sobre los renglones. Con el renglón a 945
 * y los pivots encima el comprobante saldría por 893,03: los ajustes aplicados dos veces. Cada
 * test afirma las dos cosas: el `sales.total` que se copió del pedido y el `getTotalSale()`
 * recalculado desde lo persistido, que tienen que coincidir.
 *
 * Y el caso que no puede cambiar: un pedido SIN pivots (tienda vieja, comprador sin cliente) da la
 * venta de siempre, sin ajustes y con el renglón al precio del pedido.
 *
 * `EmpresaTestCase` + `PedidosDePrueba`, el mismo fixture que tests/Feature/Pedidos. Confirma con
 * el payload mínimo (solo `order_status_id`), que no recalcula `orders.total`.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Pedido_con_ajustes_del_cliente_a_la_venta_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** Precio del artículo sin ajustes. */
    const PRECIO_DE_LISTA = 1000;

    /** 1000 × 0,90 × 1,05: lo que cobró la tienda. */
    const PRECIO_AJUSTADO = 945;

    const CANTIDAD = 2;

    const PORCENTAJE_DESCUENTO = 10;

    const PORCENTAJE_RECARGO = 5;

    /** Tolerancia: un centavo por renglón, la del redondeo de `round(precio / factor, 2)`. */
    const DELTA = 0.02;

    /** @var \App\Models\Discount */
    protected $descuento;

    /** @var \App\Models\Surchage */
    protected $recargo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();

        $this->descuento = Discount::create([
            'name'       => 'zz Descuento del cliente (pedido)',
            'percentage' => self::PORCENTAJE_DESCUENTO,
            'user_id'    => $this->user_id(),
        ]);

        $this->recargo = Surchage::create([
            'name'       => 'zz Recargo del cliente (pedido)',
            'percentage' => self::PORCENTAJE_RECARGO,
            'user_id'    => $this->user_id(),
        ]);
    }

    /**
     * Pedido "Sin confirmar" con UN renglón, sin pivots de ajustes. Mismo molde que
     * `PedidosDePrueba::crear_pedido()` (comprador, depósito, estado) con el precio que se pida.
     *
     * @param  float  $precio
     * @return \App\Models\Order
     */
    protected function pedido_con_un_renglon($precio)
    {
        $comprador = $this->crear_comprador($this->cliente_cc()->id);

        $pedido = Order::create([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $this->user_id(),
            'address_id'      => $this->deposito()->id,
            'total'           => $precio * self::CANTIDAD,
        ]);

        $pedido->articles()->attach($this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->id, [
            'price'  => $precio,
            'amount' => self::CANTIDAD,
        ]);

        return $pedido;
    }

    /**
     * Cuelga del pedido los ajustes, tal como lo hace `tienda-api` al crearlo.
     *
     * @param  \App\Models\Order  $pedido
     * @return void
     */
    protected function colgar_ajustes($pedido)
    {
        DB::table('discount_order')->insert([
            'order_id'    => $pedido->id,
            'discount_id' => $this->descuento->id,
            'percentage'  => self::PORCENTAJE_DESCUENTO,
        ]);

        DB::table('order_surchage')->insert([
            'order_id'    => $pedido->id,
            'surchage_id' => $this->recargo->id,
            'percentage'  => self::PORCENTAJE_RECARGO,
        ]);
    }

    /**
     * Confirma el pedido y devuelve la venta que nació.
     *
     * @param  \App\Models\Order  $pedido
     * @return \App\Models\Sale
     */
    protected function confirmar($pedido)
    {
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido no creó la venta.');

        return $venta;
    }

    /**
     * 🔴 EL CASO DEL CONTRATO: 10% + 5% y renglón a 945 → venta con los pivots y el renglón a 1000.
     *
     * @group ajustes_del_cliente
     * @group pedidos
     * @test
     */
    public function el_pedido_con_ajustes_llega_a_la_venta_con_el_renglon_sin_ajustar()
    {
        $pedido = $this->pedido_con_un_renglon(self::PRECIO_AJUSTADO);
        $this->colgar_ajustes($pedido);

        $venta = $this->confirmar($pedido);

        /* Los pivots de la venta, con el porcentaje del pedido. */
        $descuentos = DB::table('discount_sale')->where('sale_id', $venta->id)->get();
        $recargos = DB::table('sale_surchage')->where('sale_id', $venta->id)->get();

        $this->assertCount(1, $descuentos, 'La venta no recibió el descuento del pedido.');
        $this->assertEquals($this->descuento->id, (int) $descuentos->first()->discount_id);
        $this->assertEquals(self::PORCENTAJE_DESCUENTO, (float) $descuentos->first()->percentage);

        $this->assertCount(1, $recargos, 'La venta no recibió el recargo del pedido.');
        $this->assertEquals($this->recargo->id, (int) $recargos->first()->surchage_id);
        $this->assertEquals(self::PORCENTAJE_RECARGO, (float) $recargos->first()->percentage);

        /* El renglón, a precio sin ajustar. */
        $renglon = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        $this->assertNotNull($renglon);
        $this->assertEquals(self::PRECIO_DE_LISTA, (float) $renglon->price, 'El renglón de la venta quedó con el precio ajustado: los pivots lo ajustarían dos veces.', self::DELTA);

        /* El total: el copiado del pedido y el recalculado desde la venta, iguales. */
        $total_del_pedido = self::PRECIO_AJUSTADO * self::CANTIDAD;

        $this->assertEquals($total_del_pedido, (float) $venta->total, 'La venta no arrastró el total del pedido.');

        $recalculado = SaleHelper::getTotalSale(Sale::find($venta->id), true, true, false, true);

        $this->assertEquals($total_del_pedido, $recalculado, 'El total recalculado desde la venta no coincide con lo que cobró la tienda.', self::DELTA * self::CANTIDAD);
    }

    /**
     * 🔴 Sin pivots, la venta de siempre: sin ajustes y con el renglón al precio del pedido.
     *
     * @group ajustes_del_cliente
     * @group pedidos
     * @test
     */
    public function el_pedido_sin_ajustes_da_la_venta_de_siempre()
    {
        $pedido = $this->pedido_con_un_renglon(self::PRECIO_AJUSTADO);

        $venta = $this->confirmar($pedido);

        $this->assertEquals(0, DB::table('discount_sale')->where('sale_id', $venta->id)->count(), 'Un pedido sin pivots no puede darle descuentos a la venta.');
        $this->assertEquals(0, DB::table('sale_surchage')->where('sale_id', $venta->id)->count(), 'Un pedido sin pivots no puede darle recargos a la venta.');

        $renglon = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        $this->assertEquals(self::PRECIO_AJUSTADO, (float) $renglon->price, 'Sin ajustes el renglón tiene que ir al precio del pedido, sin tocar.');
        $this->assertEquals(self::PRECIO_AJUSTADO * self::CANTIDAD, (float) $venta->total);
    }

    /**
     * Un descuento que el dueño borró DESPUÉS de que entró el pedido igual llega a la venta, con el
     * porcentaje del pedido: los precios se calcularon con él.
     *
     * @group ajustes_del_cliente
     * @group pedidos
     * @test
     */
    public function el_descuento_borrado_despues_del_pedido_igual_llega_a_la_venta()
    {
        $pedido = $this->pedido_con_un_renglon(self::PRECIO_AJUSTADO);
        $this->colgar_ajustes($pedido);

        /* El dueño lo borra y además le cambia el porcentaje al recargo. */
        $this->descuento->delete();
        $this->recargo->percentage = 50;
        $this->recargo->save();

        $venta = $this->confirmar($pedido);

        $this->assertEquals(self::PORCENTAJE_DESCUENTO, (float) DB::table('discount_sale')->where('sale_id', $venta->id)->value('percentage'), 'El descuento borrado no llegó a la venta.');
        $this->assertEquals(self::PORCENTAJE_RECARGO, (float) DB::table('sale_surchage')->where('sale_id', $venta->id)->value('percentage'), 'La venta tomó el porcentaje de hoy y no la foto del pedido.');

        $renglon = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        $this->assertEquals(self::PRECIO_DE_LISTA, (float) $renglon->price, '', self::DELTA);
    }

    /**
     * El combo del pedido también vuelve a su precio sin ajustar (decisión 2 de Lucas: los ajustes
     * van sobre todo el carrito).
     *
     * @group ajustes_del_cliente
     * @group pedidos
     * @group combos
     * @test
     */
    public function el_combo_del_pedido_vuelve_a_su_precio_sin_ajustar()
    {
        $combo = Combo::create([
            'num'     => 9700,
            'name'    => 'zz Combo con ajustes del cliente '.uniqid(),
            'price'   => 500,
            'cost'    => 200,
            'user_id' => $this->user_id(),
        ]);

        /* 500 × 0,90 × 1,05 = 472,50 */
        $precio_ajustado = 472.5;

        $pedido = $this->pedido_con_un_renglon(self::PRECIO_AJUSTADO);

        $pedido->combos()->attach($combo->id, [
            'amount' => 3,
            'price'  => $precio_ajustado,
            'cost'   => 200,
        ]);

        $pedido->total = (float) $pedido->total + ($precio_ajustado * 3);
        $pedido->save();

        $this->colgar_ajustes($pedido);

        $venta = $this->confirmar($pedido);

        $fila = DB::table('combo_sale')->where('sale_id', $venta->id)->first();

        $this->assertNotNull($fila, 'El combo no llegó a la venta.');
        $this->assertEquals(500, (float) $fila->price, 'El combo quedó con el precio ajustado en la venta.', self::DELTA);

        $total_del_pedido = self::PRECIO_AJUSTADO * self::CANTIDAD + $precio_ajustado * 3;

        $this->assertEquals($total_del_pedido, (float) $venta->total);

        $recalculado = SaleHelper::getTotalSale(Sale::find($venta->id), true, true, false, true);

        $this->assertEquals($total_del_pedido, $recalculado, 'Con el combo, el total recalculado desde la venta no coincide con el del pedido.', 0.1);
    }

    /**
     * El pedido muestra sus ajustes: la relación con el porcentaje del pivot viaja en el GET.
     *
     * @group ajustes_del_cliente
     * @group pedidos
     * @test
     */
    public function el_get_del_pedido_trae_sus_ajustes()
    {
        $pedido = $this->pedido_con_un_renglon(self::PRECIO_AJUSTADO);
        $this->colgar_ajustes($pedido);

        $model = Order::where('id', $pedido->id)->withAll()->first()->toArray();

        $this->assertEquals([$this->descuento->id], array_column($model['discounts'], 'id'));
        $this->assertEquals(self::PORCENTAJE_DESCUENTO, (float) $model['discounts'][0]['pivot']['percentage']);
        $this->assertEquals([$this->recargo->id], array_column($model['surchages'], 'id'));
        $this->assertEquals(self::PORCENTAJE_RECARGO, (float) $model['surchages'][0]['pivot']['percentage']);
    }
}
