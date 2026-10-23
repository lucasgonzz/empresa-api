<?php

namespace Tests\Feature\SucursalSiempre;

use App\Models\Address;
use App\Models\Provider;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * D3 de la misión sucursal-siempre: un movimiento SIN sucursal sobre un artículo que REPARTE va a la
 * sucursal principal (`from` si resta, `to` si suma) en vez de perderse. Hoy (antes de la misión) el
 * stock no cambiaba: estos tests fallaban.
 */
class Red_de_la_principal_Test extends SucursalSiempreTestCase
{
    /**
     * Venta sin sucursal (Vender sin sucursal elegida) sobre un artículo que reparte.
     *
     * @test
     */
    public function una_venta_sin_sucursal_descuenta_de_la_principal()
    {
        $principal = $this->principal();
        $otra = $this->otra_sucursal();

        $articulo = $this->crear_articulo('zz Red venta sin sucursal');
        $this->con_fila($articulo, $principal->id, 10);
        $this->con_fila($articulo, $otra->id, 5);

        $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], ['address_id' => null]);

        $this->assertEquals(8.0, $this->stock_en_deposito($articulo, $principal->id), 'La venta tenía que salir de la principal.');
        $this->assertEquals(5.0, $this->stock_en_deposito($articulo, $otra->id));
        $this->assertEquals(13.0, $this->stock($articulo), 'El global baja con la venta.');

        $movimiento = $this->movimientos($articulo)->last();
        $this->assertEquals($principal->id, $movimiento->from_address_id, 'El libro dice de dónde salió.');
        $this->assertNull($movimiento->to_address_id);
    }

    /**
     * El pedido de Tienda Nube nace con `address_id = 0` (TiendaNubeOrderService): mismo camino.
     *
     * @test
     */
    public function un_pedido_con_sucursal_cero_como_tienda_nube_descuenta_de_la_principal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Red pedido tienda nube');
        $this->con_fila($articulo, $principal->id, 7);

        $this->crear_venta([['article' => $articulo, 'amount' => 3, 'price' => 100]], ['address_id' => 0]);

        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(4.0, $this->stock($articulo));
    }

    /**
     * Compra a proveedor sin depósito (`POST api/provider-order` con address_id null): suma en la principal.
     *
     * @test
     */
    public function una_compra_sin_deposito_suma_en_la_principal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Red compra sin deposito');
        $this->con_fila($articulo, $principal->id, 4);

        $proveedor = Provider::where('user_id', $this->usuario()->id)->where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();
        $this->assertNotNull($proveedor);

        $payload = [
            'provider_id'                            => $proveedor->id,
            'address_id'                             => null,
            'modo_facturacion'                       => 'automatico',
            'update_prices'                          => 0,
            'update_stock'                           => 1,
            'total_with_iva'                         => 1,
            'precios_incluyen_iva'                   => 0,
            'moneda_id'                              => 2,
            'generate_current_acount'                => 0,
            'total_from_provider_order_afip_tickets' => 0,
            'provider_order_status_id'               => 2,
            'days_to_advise'                         => null,
            'numero_comprobante'                     => null,
            'articles'                               => [[
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => $articulo->provider_code,
                'pivot'         => [
                    'cost' => 100, 'amount' => 6, 'received' => 6, 'price' => null, 'discount' => null,
                    'iva_id' => $articulo->iva_id, 'cost_in_dollars' => 0, 'update_provider' => 0,
                ],
            ]],
        ];

        $this->postJson('api/provider-order', $payload)->assertStatus(201);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $principal->id), 'Lo recibido tenía que entrar en la principal.');
        $this->assertEquals(10.0, $this->stock($articulo));
    }

    /**
     * Un movimiento manual sin sucursal (el "Asignar stock" del Listado con una sola sucursal): suma
     * en la principal, y con dos sucursales en la MARCADA como principal.
     *
     * @test
     */
    public function un_ingreso_sin_sucursal_va_a_la_marcada_como_principal()
    {
        $primera = $this->principal();
        $marcada = $this->otra_sucursal('zz Sucursal marcada');
        $marcada->default_address = 1;
        $marcada->save();

        $articulo = $this->crear_articulo('zz Red ingreso manual');
        $this->con_fila($articulo, $primera->id, 2);

        $this->postJson('api/stock-movement', [
            'model_id' => $articulo->id,
            'amount'   => 5,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(5.0, $this->stock_en_deposito($articulo, $marcada->id), 'Va a la marcada, aunque el artículo no tuviera fila ahí.');
        $this->assertEquals(2.0, $this->stock_en_deposito($articulo, $primera->id));
        $this->assertEquals(7.0, $this->stock($articulo));
    }

    /**
     * Un artículo que NO reparte (stock global, sin filas) no cambia: el movimiento sin sucursal va al
     * global como siempre, sin abrirle ninguna fila.
     *
     * @test
     */
    public function un_articulo_sin_filas_sigue_en_el_global()
    {
        $articulo = $this->crear_articulo('zz Red sin filas', ['stock' => 9]);

        $this->crear_venta([['article' => $articulo, 'amount' => 1, 'price' => 100]], ['address_id' => null]);

        $this->assertEquals(8.0, $this->stock($articulo));
        $this->assertSame(0, $this->cantidad_de_depositos($articulo));
    }

    /**
     * Variante que reparte: el movimiento sin sucursal de la variante va a la fila de la variante en
     * la principal.
     *
     * @test
     */
    public function una_variante_que_reparte_usa_la_principal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Red variante');
        $variante = $this->variante($articulo, 0);
        $variante->addresses()->attach($principal->id, ['amount' => 6]);

        $this->postJson('api/stock-movement', [
            'model_id'           => $articulo->id,
            'article_variant_id' => $variante->id,
            'amount'             => -2,
            'concepto_stock_movement_name' => 'Ingreso manual',
        ])->assertStatus(201);

        $this->assertEquals(4.0, $this->stock_de_variante_en($variante, $principal->id));
        $this->assertEquals(4.0, $this->stock_de_variante($variante));
        $this->assertEquals(4.0, $this->stock($articulo));
    }

    /**
     * Un comercio sin sucursales: nada cambia (no hay principal).
     *
     * @test
     */
    public function sin_sucursales_no_cambia_nada()
    {
        $dueno = $this->dueno_nuevo('sin-sucursales');

        $this->assertNull(Address::principal_de($dueno->id));

        $data = \App\Http\Controllers\Helpers\address\SucursalVigenteHelper::aplicar_red_de_la_principal(
            ['model_id' => 1, 'amount' => -1],
            $this->crear_articulo('zz Red sin sucursales'),
            null,
            $dueno->id
        );

        $this->assertArrayNotHasKey('from_address_id', $data);
    }
}
