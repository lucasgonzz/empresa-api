<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 1 — el ALTA de una venta guarda el precio ORIGINAL de cada renglon elegible.
 *
 * Con la opcion prendida, la SPA le resta el descuento a cada precio y manda, al lado, el precio
 * sin el (`price_vender_sin_descuentos`). Lo que este archivo fija:
 *
 *  - la base se guarda en los CUATRO tipos de renglon: articulo, servicio, combo y promocion. Con la
 *    opcion prendida los combos y las promociones tambien llevan el descuento adentro (la opcion
 *    decide DONDE se ve el descuento, nunca SI se cobra), asi que un back que solo guardara la base
 *    del articulo dejaria sin precio original a todo comprobante con un combo;
 *  - un servicio sin "descuentos en servicios" no lleva el descuento adentro y su base queda en NULL;
 *  - con la opcion apagada, todo NULL;
 *  - la SPA vieja (clave ausente) deja NULL: es el modo de falla seguro, nunca un precio adivinado;
 *  - `getTotalSale()` reproduce el total que mando la SPA: con la opcion prendida NO vuelve a
 *    restar el descuento al pie.
 *
 * Los numeros estan escritos a mano: original 100 con el 10 % adentro = 90, etc.
 *
 * @group descuentos_en_precios
 */
class Alta_de_venta_guarda_la_base_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Test 1 — opcion prendida, descuentos en servicios: base en los cuatro tipos de renglon.
     *
     * @test
     */
    public function con_la_opcion_prendida_guarda_la_base_de_los_cuatro_tipos_de_renglon()
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $combo     = $this->combo(200);
        $promo     = $this->promocion(300);
        $descuento = $this->descuento();

        /* 90 + 45 + 180 + 270 = 585: cada precio ya trae el 10 % de descuento adentro. */
        $total = 585.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100),
            $this->item_vender('service', $servicio->id, 45, 50),
            $this->item_vender('combo', $combo->id, 180, 200),
            $this->item_vender('promocion_vinoteca', $promo->id, 270, 300),
        ], $total, 1, [$this->descuento_del_payload($descuento)], ['sub_total' => $total]));

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items, 'La venta tiene que quedar con la opcion prendida.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 45, 50, 'servicio');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 180, 200, 'combo');
        $this->assert_precio_y_base($this->fila('promocion_vinoteca_sale', 'sale_id', $sale->id, 'promocion_vinoteca_id', $promo->id), 270, 300, 'promocion');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar el total que mando la SPA: con la opcion prendida no vuelve a restar el descuento en ningun tipo de renglon.'
        );
    }

    /**
     * Test 2 — la base se guarda con los SEIS decimales que manda la SPA.
     *
     * Es lo que hace que una venta GUARDADA con la opcion prendida, al reabrirla en otra edicion,
     * recalcule desde el precio original exacto: apagar la opcion devuelve `round2(base)`. Original
     * 105,555555 con 10 % da 95,00 (94,9999995); si la base se guardara redondeada a dos decimales
     * (105,56) el precio de antes ya no se podria recuperar exacto y recalcular daria 95,00 en este
     * caso y otro centavo en otros. (Si se guarda con la opcion APAGADA la base pasa a NULL y esa
     * garantia ya no aplica: ver la migracion.)
     *
     * @test
     */
    public function la_base_se_guarda_con_seis_decimales()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 95.00, 105.555555),
        ], 95.00, 1, [$this->descuento_del_payload($descuento)]));

        $fila = $this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id);

        $this->assertSame('105.555555', (string) $fila->price_sin_descuentos_de_venta, 'La base no se puede redondear a dos decimales.');
    }

    /**
     * Test 3 — un servicio SIN "descuentos en servicios" no lleva el descuento adentro: base NULL,
     * aunque la opcion este prendida y los demas renglones si la tengan. Y tampoco se le resta al
     * pie: el total no lo toca.
     *
     * @test
     */
    public function un_servicio_sin_descuentos_en_servicios_queda_sin_base()
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $descuento = $this->descuento();

        /* 90 del articulo descontado + 50 del servicio, que no lleva descuento. */
        $total = 140.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100),
            $this->item_vender('service', $servicio->id, 50, null),
        ], $total, 1, [$this->descuento_del_payload($descuento)], ['discounts_in_services' => 0]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 50, null, 'servicio sin descuentos en servicios');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar 140: el servicio no lleva descuento ni adentro ni al pie.'
        );
    }

    /**
     * Test 4 — con la opcion APAGADA todo queda en NULL, y el descuento se sigue restando al pie.
     *
     * @test
     */
    public function con_la_opcion_apagada_la_base_queda_en_null()
    {
        $articulo  = $this->articulo_centinela();
        $combo     = $this->combo(200);
        $descuento = $this->descuento();

        /* (100 + 200) menos el 10 % al pie = 270. */
        $total = 270.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 100, null),
            $this->item_vender('combo', $combo->id, 200, null),
        ], $total, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 300]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo con la opcion apagada');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 200, null, 'combo con la opcion apagada');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'Con la opcion apagada getTotalSale() resta el descuento al pie, como siempre.'
        );
    }

    /**
     * Test 5 — la SPA VIEJA no manda la clave: la base queda en NULL aunque la opcion este
     * prendida. Ningun precio cambia.
     *
     * @test
     */
    public function sin_la_clave_la_base_queda_en_null()
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $descuento = $this->descuento();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, false),
            $this->item_vender('service', $servicio->id, 45, false),
        ], 135.00, 1, [$this->descuento_del_payload($descuento)]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, null, 'articulo sin la clave');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 45, null, 'servicio sin la clave');
    }

    /**
     * Test 6 — la respuesta del alta expone la base en el pivot, que es de donde la lee la SPA al
     * reabrir la venta (contrato: `pivot.price_sin_descuentos_de_venta`).
     *
     * @test
     */
    public function la_respuesta_expone_la_base_en_el_pivot()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $response = $this->postJson('api/sale', $this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100),
        ], 90.00, 1, [$this->descuento_del_payload($descuento)]));

        $response->assertStatus(201);

        $articulos = $response->json('model.articles');

        $this->assertCount(1, $articulos);

        $this->assertArrayHasKey('price_sin_descuentos_de_venta', $articulos[0]['pivot'], 'El pivot de la respuesta tiene que traer la base.');

        $this->assertEqualsWithDelta(100, (float) $articulos[0]['pivot']['price_sin_descuentos_de_venta'], 0.000001);

        /* Y al reabrir la venta por su id, lo mismo, junto con el flag. */
        $show = $this->getJson('api/sale/'.$response->json('model.id'));

        $show->assertStatus(200);

        $this->assertEqualsWithDelta(100, (float) $show->json('model.articles.0.pivot.price_sin_descuentos_de_venta'), 0.000001);

        $this->assertEquals(1, (int) $show->json('model.aplicar_descuentos_directo_a_items'), 'La respuesta expone el flag, que es de donde lo lee la SPA.');
    }
}
