<?php

namespace Tests\Feature\VariosPreciosFilaSinPrecio;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RecargosEnPrecios\RecargosEnPreciosTestCase;

/**
 * `varios_precios`: una fila SIN `price_vender` no puede tirar un 500 (mision
 * panchito-venta-price-vender, 1/11/2026).
 *
 * Panchito (4.3.21) no podia guardar ventas desde el 26/10: `POST api/sale` daba 500
 * (`Undefined array key "price_vender"` en `SaleHelper::fila_de_varios_precios()`) y el SPA
 * reintentaba la venta offline cada 30 segundos. La SPA puede mandar una fila sin la clave porque
 * `JSON.stringify` descarta las claves `undefined`.
 *
 * Los numeros estan escritos a mano. Las filas llevan SOLO lo que la SPA mandaria en cada caso.
 *
 * @group varios_precios_fila_sin_precio
 */
class Fila_de_varios_precios_sin_precio_Test extends RecargosEnPreciosTestCase
{
    const STOCK_INICIAL = 20;

    /**
     * Un uuid de venta offline valido.
     *
     * @return string
     */
    protected function uuid()
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /**
     * Stock del articulo leido de la base.
     *
     * @param  \App\Models\Article  $articulo
     * @return float
     */
    protected function stock($articulo)
    {
        return (float) DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * Cantidad de ventas que tocaron el articulo.
     *
     * @param  \App\Models\Article  $articulo
     * @return int
     */
    protected function ventas_del_articulo($articulo)
    {
        return DB::table('article_sale')->where('article_id', $articulo->id)->distinct()->count('sale_id');
    }

    /**
     * Test 1 — la fila sin `price_vender` pero con `price_vender_con_recargos` se guarda con ese
     * precio (el de la misma fila): 100 × 2 = 200.
     *
     * @test
     */
    public function una_fila_sin_price_vender_con_price_vender_con_recargos_se_guarda_con_ese_precio()
    {
        $articulo = $this->articulo_propio('Fila sin price_vender', ['stock' => self::STOCK_INICIAL]);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 0, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 200.00, 0, [], ['discount_stock' => 1]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(1, $filas);
        $this->assertEqualsWithDelta(100, (float) $filas[0]->price, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) $filas[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 2, $this->stock($articulo), self::DELTA);
    }

    /**
     * Test 2 — una fila vacia (sin precio ni cantidad: lo que deja el Enter en un campo sin precio)
     * hace que el renglon se guarde como uno NORMAL, con el precio y la cantidad del padre. Es lo
     * que cobro la SPA: la fila sin precio deja `calculated_price_vender` en NaN y `getTotalItem()`
     * toma `price_vender × amount` del padre. Las otras filas no se guardan (el total no las incluye)
     * y el stock sale del padre, una sola vez.
     *
     * @test
     */
    public function una_fila_vacia_guarda_el_renglon_como_normal_con_el_precio_del_padre()
    {
        $articulo = $this->articulo_propio('Fila vacia', ['stock' => self::STOCK_INICIAL]);

        $precio_padre = (float) $articulo->final_price;

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'amount' => '', 'price_vender_con_recargos' => null, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 80, 'amount' => 3, 'price_vender_con_recargos' => 80, 'price_vender_sin_recargos' => null],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], $precio_padre, 0, [], ['discount_stock' => 1]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(1, $filas, 'El renglon se guarda como una sola linea normal.');
        $this->assertEqualsWithDelta($precio_padre, (float) $filas[0]->price, self::DELTA, 'Con el precio del padre, que es el del total.');
        $this->assertEqualsWithDelta(1, (float) $filas[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta($precio_padre, (float) Sale::find($sale->id)->total, self::DELTA);
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 1, $this->stock($articulo), self::DELTA, 'El stock sale del padre, una vez.');
    }

    /**
     * Test 3 — si TODAS las filas son vacias pasa lo mismo: el renglon se guarda como normal (no se
     * pierde la linea cobrada) y el resto de la venta sigue igual.
     *
     * @test
     */
    public function si_todas_las_filas_son_vacias_el_renglon_se_guarda_como_normal()
    {
        $vacio = $this->articulo_propio('Todas vacias', ['stock' => self::STOCK_INICIAL]);
        $otro  = $this->articulo_propio('Renglon normal', ['stock' => self::STOCK_INICIAL]);

        $precio_padre = (float) $vacio->final_price;

        $renglon_vacio = $this->renglon_con_varios_precios($vacio, [
            ['id' => 0, 'amount' => ''],
        ]);

        $renglon_normal = $this->renglon_con_varios_precios($otro, [
            ['id' => 0, 'price_vender' => 70, 'amount' => 1, 'price_vender_con_recargos' => 70, 'price_vender_sin_recargos' => null],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon_vacio, $renglon_normal], $precio_padre + 70.00, 0, [], ['discount_stock' => 1]));

        $filas_vacio = $this->filas_de_la_venta($sale->id, $vacio->id);

        $this->assertCount(1, $filas_vacio);
        $this->assertEqualsWithDelta($precio_padre, (float) $filas_vacio[0]->price, self::DELTA);
        $this->assertCount(1, $this->filas_de_la_venta($sale->id, $otro->id));
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 1, $this->stock($vacio), self::DELTA);
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 1, $this->stock($otro), self::DELTA);
    }

    /**
     * Test 4 — una fila con cantidad pero SIN precio no se adivina: 422 con un mensaje que nombra el
     * articulo, y no queda ninguna venta ni se mueve el stock.
     *
     * @test
     */
    public function una_fila_con_cantidad_y_sin_precio_da_422_y_no_deja_nada()
    {
        $articulo = $this->articulo_propio('Fila con cantidad sin precio', ['stock' => self::STOCK_INICIAL]);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 0, 'amount' => 4],
        ]);

        $ventas_antes = Sale::count();

        $response = $this->postJson('api/sale', $this->payload_venta([$renglon], 123.00, 0, [], ['discount_stock' => 1]));

        $response->assertStatus(422);

        $this->assertStringContainsString($articulo->name, (string) $response->json('message'));
        $this->assertStringContainsString('sin precio', (string) $response->json('message'));
        $this->assertEquals($ventas_antes, Sale::count(), 'El rechazo no puede dejar una venta a medias.');
        $this->assertEquals(0, $this->ventas_del_articulo($articulo));
        $this->assertEqualsWithDelta(self::STOCK_INICIAL, $this->stock($articulo), self::DELTA);
    }

    /**
     * Test 5 — las ventas trabadas entran solas y SIN duplicarse. El mismo `offline_uuid` con la fila
     * vacia (lo que tiene el equipo) y reintentado varias veces deja UNA venta, por el total que
     * mando la SPA, y descuenta el stock una vez; el reintento devuelve la misma venta.
     *
     * @test
     */
    public function la_venta_trabada_entra_con_su_uuid_y_los_reintentos_no_la_duplican()
    {
        $articulo = $this->articulo_propio('Venta trabada', ['stock' => self::STOCK_INICIAL]);

        $precio_padre = (float) $articulo->final_price;

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'amount' => ''],
            ['id' => 0, 'price_vender' => 60, 'amount' => 2, 'price_vender_con_recargos' => 60, 'price_vender_sin_recargos' => null],
        ]);

        $payload = $this->payload_venta([$renglon], $precio_padre, 0, [], [
            'discount_stock' => 1,
            'offline_uuid'   => $this->uuid(),
            'venta_offline'  => 1,
        ]);

        $primera = $this->postJson('api/sale', $payload)->assertStatus(201);
        $segunda = $this->postJson('api/sale', $payload)->assertStatus(201);
        $tercera = $this->postJson('api/sale', $payload)->assertStatus(201);

        $id = $primera->json('model.id');

        $this->assertNotNull($id);
        $this->assertEquals($id, $segunda->json('model.id'));
        $this->assertEquals($id, $tercera->json('model.id'));
        $this->assertEquals(1, $this->ventas_del_articulo($articulo), 'Los reintentos con el mismo uuid no pueden dejar mas de una venta.');
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 1, $this->stock($articulo), self::DELTA, 'El stock sale una sola vez.');
        $this->assertEquals($payload['offline_uuid'], Sale::find($id)->offline_uuid);
    }

    /**
     * Test 6 — una venta con filas bien formadas queda IGUAL que antes: dos filas, sus precios y
     * cantidades, y el stock de la suma. (La regresion que este arreglo no puede traer.)
     *
     * @test
     */
    public function una_venta_con_filas_completas_no_cambia()
    {
        $articulo = $this->articulo_propio('Filas completas', ['stock' => self::STOCK_INICIAL]);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 250.00, 0, [], ['discount_stock' => 1]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);
        $this->assertEqualsWithDelta(50, (float) $filas[0]->price, self::DELTA);
        $this->assertEqualsWithDelta(1, (float) $filas[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(100, (float) $filas[1]->price, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) $filas[1]->amount, self::DELTA);
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 3, $this->stock($articulo), self::DELTA);
    }

    /**
     * Test 7 — editar una venta (PUT) con una fila de cantidad y sin precio da el mismo 422 con
     * mensaje que el alta (antes caia al 500 generico), y la venta queda como estaba.
     *
     * @test
     */
    public function editar_una_venta_con_una_fila_con_cantidad_y_sin_precio_da_422_y_no_la_toca()
    {
        $articulo = $this->articulo_propio('Edicion fila sin precio', ['stock' => self::STOCK_INICIAL]);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 0, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 200.00, 0, [], ['discount_stock' => 1]));

        $roto = $this->renglon_con_varios_precios($articulo, [
            ['id' => 0, 'amount' => 5],
        ]);

        $response = $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [$roto], 500.00, 0, [], ['discount_stock' => 1]));

        $response->assertStatus(422);

        $this->assertStringContainsString($articulo->name, (string) $response->json('message'));
        $this->assertEqualsWithDelta(200.00, (float) Sale::find($sale->id)->total, self::DELTA, 'La edicion rechazada no puede cambiar la venta.');
        $this->assertEqualsWithDelta(self::STOCK_INICIAL - 2, $this->stock($articulo), self::DELTA, 'Ni el stock.');
        $this->assertCount(1, $this->filas_de_la_venta($sale->id, $articulo->id));
    }
}
