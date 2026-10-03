<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 4 — EL CONTRATO CON LAS VENTAS: la API guarda lo que manda la SPA y NO re-deriva el precio
 * del renglon desde el catalogo ni desde el ajuste de la sucursal.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUE ESTE TEST EXISTE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El ajuste de la sucursal lo aplica la SPA: en Vender, `getPriceVender()` mete el recargo o el
 *  descuento ADENTRO del precio de cada articulo, y manda el renglon ya con ese precio (100 de
 *  catalogo + 10 % de recargo = `price_vender` 110) y el total ya sumado (110). La API guarda
 *  renglon y total tal cual, como hace con cualquier otro ajuste que vive adentro del precio (el
 *  del metodo de pago, las cuotas).
 *
 *  Si algun dia alguien hiciera que `SaleController` recalculara el precio desde el catalogo, o que
 *  le aplicara el ajuste de la sucursal por su cuenta, el comprobante quedaria con un precio
 *  distinto del que vio el vendedor en pantalla (o con el ajuste cobrado DOS veces). Este test fija
 *  el contrato y avisa.
 *
 *  Para que el test pruebe algo, el articulo tiene el precio de catalogo en 100 y el renglon llega en
 *  110: una API que re-derivara desde el catalogo guardaria 100, una que re-aplicara el 10 % sobre
 *  lo que manda la SPA guardaria 121.
 *
 *  ⚠️ `SaleController::store()` tiene una guarda anti-duplicados de 5 segundos por comercio, cliente y
 *  TOTAL: un test que crea dos ventas les tiene que dar totales distintos.
 *
 * @group sucursales
 */
class Ajuste_de_precios_y_venta_Test extends SucursalesTestCase
{
    /**
     * Un articulo con el precio de catalogo en 100, creado para este test (el centinela del fixture
     * tiene otro precio y no sirve para el 100 -> 110 de la cuenta).
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_100()
    {
        return Article::create([
            'name'        => 'zz Art ajuste sucursal '.uniqid(),
            'user_id'     => $this->comercio()->id,
            'price'       => 100,
            'final_price' => 100,
        ]);
    }

    /**
     * Payload de POST api/sale de mostrador (sin cliente ni cuenta corriente) desde una sucursal, con
     * UN renglon de articulo. Sin metodo de pago: un request que no habla del cobro no se rechaza.
     *
     * @param  int    $address_id
     * @param  int    $article_id
     * @param  float  $price_vender  El precio que mando la SPA, con el ajuste ya adentro.
     * @param  float  $total
     * @return array
     */
    protected function payload_venta_desde_sucursal($address_id, $article_id, $price_vender, $total)
    {
        return [
            'client_id'                        => null,
            'address_id'                       => $address_id,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $article_id,
                'price_vender' => $price_vender,
                'amount'       => 1,
            ]],
        ];
    }

    /**
     * Test 7 del plan — POST sale desde una sucursal con recargo del 10 %, con el renglon ya en 110 y
     * el total en 110: el renglon queda en 110, `sales.total` queda en 110 y la venta recuerda la
     * sucursal. NO se vuelve a aplicar el 10 % (121) ni se vuelve al catalogo (100).
     *
     * @test
     */
    public function la_venta_desde_una_sucursal_con_recargo_guarda_el_precio_que_manda_la_spa()
    {
        $sucursal = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'recargo',
            'ajuste_precio_porcentaje' => 10,
        ]);

        $articulo = $this->articulo_de_100();

        $response = $this->postJson('api/sale', $this->payload_venta_desde_sucursal($sucursal, $articulo->id, 110, 110));

        $response->assertStatus(201);

        $sale_id = $response->json('model.id');

        $this->assertNotNull($sale_id, 'POST api/sale no devolvio la venta creada.');

        /* El renglon, leido directo de la tabla. */
        $fila = DB::table('article_sale')->where('sale_id', $sale_id)->where('article_id', $articulo->id)->get();

        $this->assertCount(1, $fila, 'La venta tiene que tener un unico renglon del articulo.');

        $this->assertEqualsWithDelta(
            110,
            (float) $fila->first()->price,
            self::DELTA,
            'El renglon tiene que guardar el precio que mando la SPA (100 de catalogo + 10 % = 110): ni el catalogo (100) ni el ajuste aplicado de nuevo (121).'
        );

        /* El total de la venta. */
        $venta = DB::table('sales')->where('id', $sale_id)->first();

        $this->assertEqualsWithDelta(110, (float) $venta->total, self::DELTA, 'sales.total tiene que ser el que mando la SPA.');

        $this->assertEquals($sucursal, (int) $venta->address_id, 'La venta tiene que quedar con la sucursal desde la que se hizo.');

        /* Y el total que reconstruye la API sumando renglones coincide: total = renglones. */
        $this->assertEqualsWithDelta(
            110,
            SaleHelper::getTotalSale(Sale::find($sale_id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar 110: la suma de los renglones no puede discrepar del total que vio el vendedor.'
        );

        /* El catalogo no se toco: el ajuste vive en la venta, no en el articulo. */
        $this->assertEqualsWithDelta(100, (float) $articulo->fresh()->price, self::DELTA, 'La venta no puede cambiar el precio de catalogo del articulo.');
    }

    /**
     * Test 8 — lo mismo con un DESCUENTO del 10 % (100 -> 90): el renglon y el total quedan en 90.
     * Cubre el otro signo del ajuste, que un recargo solo no prueba.
     *
     * @test
     */
    public function la_venta_desde_una_sucursal_con_descuento_guarda_el_precio_que_manda_la_spa()
    {
        $sucursal = $this->crear_sucursal([
            'ajuste_precio_tipo'       => 'descuento',
            'ajuste_precio_porcentaje' => 10,
        ]);

        $articulo = $this->articulo_de_100();

        $response = $this->postJson('api/sale', $this->payload_venta_desde_sucursal($sucursal, $articulo->id, 90, 90));

        $response->assertStatus(201);

        $sale_id = $response->json('model.id');

        $this->assertEqualsWithDelta(
            90,
            (float) DB::table('article_sale')->where('sale_id', $sale_id)->where('article_id', $articulo->id)->value('price'),
            self::DELTA,
            'El renglon tiene que guardar el precio que mando la SPA (100 - 10 % = 90).'
        );

        $this->assertEqualsWithDelta(90, (float) DB::table('sales')->where('id', $sale_id)->value('total'), self::DELTA, 'sales.total tiene que ser 90.');

        $this->assertEqualsWithDelta(
            90,
            SaleHelper::getTotalSale(Sale::find($sale_id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar 90.'
        );
    }
}
