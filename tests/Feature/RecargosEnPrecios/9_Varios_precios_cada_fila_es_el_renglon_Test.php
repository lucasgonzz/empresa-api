<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\ArticleVariant;
use App\Models\Discount;
use App\Models\PriceType;
use App\Models\Sale;

/**
 * Archivo 9 — `varios_precios`: cada fila ES el renglon, con otro precio y otra cantidad (mision
 * varios-precios-descuento-renglon, 3/10/2026).
 *
 * Hasta esta mision `SaleHelper::attachArticles()` armaba cada fila con una LISTA BLANCA de tres
 * claves del renglon (`id`, `name`, `name_vender_personalizado`) mas el precio y la cantidad de la
 * fila. Todo lo demas se perdia en silencio: el descuento del renglon (VENDER mostraba 225 y la suma
 * de las filas daba 250: la factura y los PDF cobraban de mas), el costo (la ganancia era el 100 %
 * del precio), la variante y la lista personalizada.
 *
 * Ahora la fila parte del renglon entero y le saca SOLO lo que es de la cantidad o del precio del
 * padre. Los numeros estan escritos A MANO: el total es lo que calcularia VENDER
 * (`getTotalItem()` aplica `item.discount` sobre `calculated_price_vender`), no un helper del sistema.
 *
 * ⚠️ Los tests que miden lo que NO baja a las filas (7 y 7 bis) llevan igual un descuento de
 * renglon: la lista blanca vieja tampoco copiaba las cantidades ni las bases, y sin el descuento esos
 * dos tests darian verde contra el codigo que este archivo existe para cubrir.
 *
 * @group recargos_en_precios
 */
class Varios_precios_cada_fila_es_el_renglon_Test extends RecargosEnPreciosTestCase
{
    /**
     * Afirma una fila de varios precios: precio, cantidad, descuento y base.
     *
     * @param  object      $fila
     * @param  float       $price
     * @param  float       $amount
     * @param  float|null  $discount
     * @param  float|null  $base
     * @param  string      $que
     * @return void
     */
    protected function assert_fila($fila, $price, $amount, $discount, $base, $que)
    {
        $this->assert_precio_y_base($fila, $price, $base, $que);

        $this->assertEqualsWithDelta($amount, (float) $fila->amount, self::DELTA, $que.': la cantidad no es la de la fila.');

        if (is_null($discount)) {
            $this->assertNull($fila->discount, $que.': la fila no tenia que llevar descuento.');
            return;
        }

        $this->assertNotNull($fila->discount, $que.': la fila se guardo SIN el descuento del renglon.');

        $this->assertEqualsWithDelta($discount, (float) $fila->discount, self::DELTA, $que.': el descuento no es el del renglon.');
    }

    /**
     * Afirma que el total guardado y el que recalcula la API sumando las filas son el de VENDER.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float  $total_de_vender
     * @return void
     */
    protected function assert_total($sale, $total_de_vender)
    {
        $sale = Sale::find($sale->id);

        $this->assertEqualsWithDelta($total_de_vender, (float) $sale->total, self::DELTA, 'sales.total no es el que mando VENDER.');

        $this->assertEqualsWithDelta(
            $total_de_vender,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'La suma de las filas (getTotalSale) no da el total de VENDER: las filas no llevan lo que llevaba el renglon.'
        );
    }

    /**
     * Test 1 — opcion apagada, sin recargos: 100 × 2 + 50 × 1 con 10 % de descuento de renglon.
     * VENDER muestra (200 + 50) × 0,9 = 225; cada fila lleva el 10 % y la suma de las filas da 225.
     *
     * @test
     */
    public function opcion_apagada_cada_fila_guarda_el_descuento_y_el_total_es_el_de_vender()
    {
        $articulo = $this->articulo_centinela();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], ['discount' => 10]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 225.00, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas, 'Cada precio de varios_precios es su propia fila.');

        $this->assert_fila($filas[0], 50, 1, 10, null, 'fila de 50');
        $this->assert_fila($filas[1], 100, 2, 10, null, 'fila de 100');

        $this->assert_total($sale, 225.00);
    }

    /**
     * Test 2 — opcion prendida (recargo de 10 % adentro): filas tipeadas 100 × 2 y 50 × 1, que la SPA
     * manda recargadas a 110 y 55 con su base. Cada fila guarda su precio recargado, SU base y el
     * descuento del renglon: (110 × 2 + 55) × 0,9 = 247,50. El padre trae su propio precio de lista y
     * su propia base (132 / 120), que no son los de ninguna fila.
     *
     * @test
     */
    public function opcion_prendida_cada_fila_guarda_el_precio_recargado_su_base_y_el_descuento()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 110, 'price_vender_sin_recargos' => 100],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 55, 'price_vender_sin_recargos' => 50],
        ], [
            'discount'                  => 10,
            'price_vender'              => 132,
            'price_vender_sin_recargos' => 120,
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 247.50, 1, [$this->recargo_del_payload($recargo)]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_fila($filas[0], 55, 1, 10, 50, 'fila de 50 recargada');
        $this->assert_fila($filas[1], 110, 2, 10, 100, 'fila de 100 recargada');

        /* Con la opcion prendida getTotalSale() no suma el recargo al pie: ya esta en los precios. */
        $this->assert_total($sale, 247.50);
    }

    /**
     * Test 3 — como interactua el descuento de renglon con los descuentos y recargos GENERALES de la
     * venta (opcion apagada). VENDER aplica, en orden: el descuento de cada renglon, el descuento
     * general sobre los articulos y el recargo al pie.
     *
     *   varios precios: (100 × 2 + 50 × 1) × 0,9 = 225
     *   renglon comun de otro articulo:  80 × 1 =  80
     *   articulos                               = 305
     *   descuento general 20 %:      305 × 0,8 = 244
     *   recargo al pie 10 %:         244 × 1,1 = 268,40
     *
     * @test
     */
    public function descuento_de_renglon_descuento_general_y_recargo_al_pie_dan_el_total_de_vender()
    {
        $articulo = $this->articulo_centinela();
        $otro     = $this->articulo_propio('Otro articulo de la venta');
        $recargo  = $this->recargo();

        $descuento = Discount::create([
            'name'       => 'zz Descuento suite varios precios '.uniqid(),
            'percentage' => 20,
            'user_id'    => $this->comercio()->id,
        ]);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], ['discount' => 10]);

        $renglon_comun = $this->item_vender('article', $otro->id, 80, null, 1);

        $sale = $this->crear_venta($this->payload_venta(
            [$renglon, $renglon_comun],
            268.40,
            0,
            [$this->recargo_del_payload($recargo)],
            [
                'sub_total' => 305,
                'discounts' => [['id' => $descuento->id, 'percentage' => 20]],
            ]
        ));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_fila($filas[0], 50, 1, 10, null, 'fila de 50');
        $this->assert_fila($filas[1], 100, 2, 10, null, 'fila de 100');

        $this->assert_fila($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $otro->id), 80, 1, null, null, 'renglon comun');

        $this->assert_total($sale, 268.40);
    }

    /**
     * Test 4 — la SPA VIEJA (anterior al 28/9) manda las filas sin `price_vender_con_recargos` ni
     * base. Igual heredan el descuento del renglon: esta parte del arreglo no espera a la SPA.
     * (100 × 2 + 50 × 1) × 0,85 = 212,50.
     *
     * @test
     */
    public function spa_vieja_sin_precio_recargado_ni_base_las_filas_igual_guardan_el_descuento()
    {
        $articulo = $this->articulo_centinela();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1],
        ], ['discount' => 15]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 212.50, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_fila($filas[0], 50, 1, 15, null, 'fila de 50 de la SPA vieja');
        $this->assert_fila($filas[1], 100, 2, 15, null, 'fila de 100 de la SPA vieja');

        $this->assert_total($sale, 212.50);
    }

    /**
     * Test 5 — costo: el renglon trae el costo del articulo, como lo manda VENDER, y cada fila lo
     * guarda con su ganancia `(price − cost) × amount`, igual que un renglon comun del mismo precio.
     * Antes las filas quedaban con el costo en NULL y la ganancia era el precio entero.
     *
     *   fila 100 × 2: costo 60, ganancia (100 − 60) × 2 = 80
     *   fila  50 × 1: costo 60, ganancia ( 50 − 60) × 1 = −10
     *   total_cost de la venta: 60 × 3 = 180
     *
     * Sin descuento de renglon a proposito: la ganancia de una fila (y de cualquier renglon) hoy no
     * lo descuenta, y este test no tiene por que dejar eso escrito.
     *
     * @test
     */
    public function cada_fila_guarda_el_costo_del_renglon_y_su_ganancia()
    {
        $articulo = $this->articulo_propio('Costo de varios precios', ['cost' => 60]);

        /* El costo viaja en el renglon con las claves que trae el articulo en VENDER. */
        $costo_del_renglon = [
            'cost'                  => (float) $articulo->cost,
            'costo_real'            => $articulo->costo_real,
            'cost_in_dollars'       => 0,
            'unidades_individuales' => null,
            'presentacion'          => null,
        ];

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], $costo_del_renglon);

        /* 100 × 2 + 50 × 1 = 250. */
        $sale = $this->crear_venta($this->payload_venta([$renglon], 250.00, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assertNotNull($filas[0]->cost, 'La fila de 50 se guardo SIN costo.');
        $this->assertNotNull($filas[1]->cost, 'La fila de 100 se guardo SIN costo.');

        $this->assertEqualsWithDelta(60, (float) $filas[0]->cost, self::DELTA, 'La fila de 50 no guardo el costo del renglon.');
        $this->assertEqualsWithDelta(60, (float) $filas[1]->cost, self::DELTA, 'La fila de 100 no guardo el costo del renglon.');

        $this->assertEqualsWithDelta(-10, (float) $filas[0]->ganancia, self::DELTA, 'Ganancia de la fila de 50: (50 − 60) × 1.');
        $this->assertEqualsWithDelta(80, (float) $filas[1]->ganancia, self::DELTA, 'Ganancia de la fila de 100: (100 − 60) × 2.');

        $this->assertEqualsWithDelta(180, (float) Sale::find($sale->id)->total_cost, self::DELTA, 'El costo total de la venta es 60 × 3.');

        /* Un renglon COMUN del mismo articulo y precio guarda lo mismo que la fila de 100 (otro total: la guarda de 5 s). */
        $comun = $this->crear_venta($this->payload_venta([
            array_merge($this->item_vender('article', $articulo->id, 100, null, 2), $costo_del_renglon),
        ], 200.00, 0, []));

        $fila_comun = $this->fila('article_sale', 'sale_id', $comun->id, 'article_id', $articulo->id);

        $this->assertEqualsWithDelta((float) $fila_comun->cost, (float) $filas[1]->cost, self::DELTA, 'La fila no guarda el mismo costo que un renglon comun.');
        $this->assertEqualsWithDelta((float) $fila_comun->ganancia, (float) $filas[1]->ganancia, self::DELTA, 'La fila no guarda la misma ganancia que un renglon comun.');
    }

    /**
     * Test 6 — variante y lista personalizada: un renglon de la variante "Talle M" vendido con una
     * lista personalizada. Cada fila guarda la variante (y su descripcion) y la lista. Antes las
     * filas quedaban sin variante, y al borrar o editar la venta el stock volvia al articulo padre
     * aunque se habia descontado de la variante.
     *
     * @test
     */
    public function cada_fila_guarda_la_variante_y_la_lista_personalizada_del_renglon()
    {
        $articulo = $this->articulo_propio('Remera de varios precios');

        $variante = ArticleVariant::create([
            'article_id'          => $articulo->id,
            'variant_description' => 'Talle M',
        ]);

        $lista = PriceType::where('user_id', $this->comercio()->id)->orderBy('id')->first();

        $this->assertNotNull($lista, 'El fixture no tiene listas de precios.');

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], [
            'discount'                    => 10,
            'article_variant_id'          => $variante->id,
            'price_type_personalizado_id' => $lista->id,
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 225.00, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {

            $que = 'fila de '.(float) $fila->price;

            $this->assertEquals($variante->id, (int) $fila->article_variant_id, $que.': no guardo la variante del renglon.');
            $this->assertEquals('Talle M', $fila->variant_description, $que.': no guardo la descripcion de la variante.');
            $this->assertEquals($lista->id, (int) $fila->price_type_personalizado_id, $que.': no guardo la lista personalizada del renglon.');
        }

        $this->assert_fila($filas[0], 50, 1, 10, null, 'fila de 50 de la variante');
        $this->assert_fila($filas[1], 100, 2, 10, null, 'fila de 100 de la variante');
    }

    /**
     * Test 7 — las CANTIDADES del padre no bajan a las filas (decision de Lucas). El renglon trae una
     * cantidad chequeada, una devuelta y una entregada: ninguna llega a las filas, cada fila conserva
     * SU cantidad (la chequeada del padre no le gana en `getAmount()`, y la venta esta confirmada) y la
     * entregada no prende `en_acopio`. El descuento, en cambio, si baja.
     *
     * @test
     */
    public function las_cantidades_del_padre_no_bajan_a_las_filas()
    {
        $articulo = $this->articulo_centinela();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], [
            'discount'         => 10,
            'amount'           => 9,
            'checked_amount'   => 7,
            'returned_amount'  => 4,
            'delivered_amount' => 5,
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 225.00, 0, []));

        $this->assertEquals(1, (int) Sale::find($sale->id)->confirmed, 'La venta tenia que quedar confirmada: es donde la cantidad chequeada le ganaria a la de la fila.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_fila($filas[0], 50, 1, 10, null, 'fila de 50');
        $this->assert_fila($filas[1], 100, 2, 10, null, 'fila de 100');

        foreach ($filas as $fila) {

            $que = 'fila de '.(float) $fila->price;

            $this->assertNull($fila->checked_amount, $que.': heredo la cantidad chequeada del padre.');
            $this->assertNull($fila->returned_amount, $que.': heredo la cantidad devuelta del padre.');
            $this->assertNull($fila->delivered_amount, $que.': heredo la cantidad entregada del padre.');
        }

        $this->assertEquals(0, (int) Sale::find($sale->id)->en_acopio, 'La cantidad entregada del padre no puede prender en_acopio.');

        $this->assert_total($sale, 225.00);
    }

    /**
     * Test 7 bis — el PRECIO y la BASE del padre tampoco bajan a las filas. El padre trae su
     * `price_vender_con_recargos` (999) y su base (120). La fila que trae su precio recargado (110) y
     * no trae base queda con la base en NULL —nunca la del padre, que `base_del_item()` leeria con
     * `array_key_exists`—, y la fila sin precio recargado guarda el tipeado (50), nunca el 999 del
     * padre. (110 × 2 + 50 × 1) × 0,9 = 243.
     *
     * @test
     */
    public function el_precio_y_la_base_del_padre_no_bajan_a_las_filas()
    {
        $articulo = $this->articulo_centinela();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 110],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1],
        ], [
            'discount'                   => 10,
            'price_vender'               => 132,
            'price_vender_con_recargos'  => 999,
            'price_vender_sin_recargos'  => 120,
            'calculated_price_vender'    => 270,
            'price_vender_personalizado' => 77,
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 243.00, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_fila($filas[0], 50, 1, 10, null, 'fila sin precio recargado');
        $this->assert_fila($filas[1], 110, 2, 10, null, 'fila recargada sin base');

        $this->assert_total($sale, 243.00);
    }
}
