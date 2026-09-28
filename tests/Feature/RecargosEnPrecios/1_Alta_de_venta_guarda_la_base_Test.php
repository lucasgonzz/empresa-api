<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 1 — el ALTA de una venta guarda la base de cada renglon elegible.
 *
 * Con la opcion prendida, la SPA recarga cada precio y manda, al lado, el precio sin el recargo
 * (`price_vender_sin_recargos`). Lo que este archivo fija:
 *
 *  - la base se guarda en los CUATRO tipos de renglon: articulo, servicio, combo y promocion. Hasta
 *    esta mision el recargo no se metia en combos ni promociones (decision 2 de Lucas: la opcion
 *    decide DONDE se ve el recargo, nunca SI se cobra), asi que un back que solo guardara la base
 *    del articulo dejaria bloqueado para editar todo comprobante con un combo;
 *  - un servicio sin "recargos en servicios" no lleva el recargo adentro y su base queda en NULL;
 *  - con la opcion apagada, todo NULL;
 *  - la SPA vieja (clave ausente) deja NULL: el comprobante queda bloqueado en la SPA nueva, que es
 *    el modo de falla seguro — nunca un precio adivinado;
 *  - `getTotalSale()` reproduce el total que mando la SPA: la base es un dato de mas, no cambia
 *    ninguna cuenta del back.
 *
 * Los numeros estan escritos a mano: base 100 con el 10 % adentro = 110, etc.
 *
 * @group recargos_en_precios
 */
class Alta_de_venta_guarda_la_base_Test extends RecargosEnPreciosTestCase
{
    /**
     * Test 1 — opcion prendida, recargos en servicios: base en los cuatro tipos de renglon.
     *
     * @test
     */
    public function con_la_opcion_prendida_guarda_la_base_de_los_cuatro_tipos_de_renglon()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $combo    = $this->combo(200);
        $promo    = $this->promocion(300);
        $recargo  = $this->recargo();

        /* 110 + 55 + 220 + 330 = 715: cada precio ya trae el 10 % adentro. */
        $total = 715.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100),
            $this->item_vender('service', $servicio->id, 55, 50),
            $this->item_vender('combo', $combo->id, 220, 200),
            $this->item_vender('promocion_vinoteca', $promo->id, 330, 300),
        ], $total, 1, [$this->recargo_del_payload($recargo)]));

        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items, 'La venta tiene que quedar con la opcion prendida.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, 100, 'articulo');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 55, 50, 'servicio');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 220, 200, 'combo');
        $this->assert_precio_y_base($this->fila('promocion_vinoteca_sale', 'sale_id', $sale->id, 'promocion_vinoteca_id', $promo->id), 330, 300, 'promocion');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar el total que mando la SPA: con la opcion prendida no vuelve a sumar el recargo en ningun tipo de renglon.'
        );
    }

    /**
     * Test 2 — la base se guarda con los SEIS decimales que manda la SPA.
     *
     * Es lo que hace que apagar y volver a prender devuelva el mismo centavo (ver la migracion):
     * base 93,457 con 10 % da 102,80; si se guardara redondeada (93,46), volver a prender daria
     * 102,81.
     *
     * @test
     */
    public function la_base_se_guarda_con_seis_decimales()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 102.80, 93.457123),
        ], 102.80, 1, [$this->recargo_del_payload($recargo)]));

        $fila = $this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id);

        $this->assertSame('93.457123', (string) $fila->price_sin_recargos_de_venta, 'La base no se puede redondear a dos decimales.');
    }

    /**
     * Test 3 — un servicio SIN "recargos en servicios" no lleva el recargo adentro: base NULL,
     * aunque la opcion este prendida y los demas renglones si la tengan.
     *
     * @test
     */
    public function un_servicio_sin_recargos_en_servicios_queda_sin_base()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $recargo  = $this->recargo();

        /* 110 del articulo recargado + 50 del servicio, que no lleva recargo. */
        $total = 160.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100),
            $this->item_vender('service', $servicio->id, 50, null),
        ], $total, 1, [$this->recargo_del_payload($recargo)], ['surchages_in_services' => 0]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, 100, 'articulo');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 50, null, 'servicio sin recargos en servicios');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'getTotalSale() tiene que dar 160: el servicio no lleva recargo ni adentro ni al pie.'
        );
    }

    /**
     * Test 4 — con la opcion APAGADA todo queda en NULL, y el recargo se sigue sumando al pie.
     *
     * @test
     */
    public function con_la_opcion_apagada_la_base_queda_en_null()
    {
        $articulo = $this->articulo_centinela();
        $combo    = $this->combo(200);
        $recargo  = $this->recargo();

        /* (100 + 200) mas el 10 % al pie = 330. */
        $total = 330.00;

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 100, null),
            $this->item_vender('combo', $combo->id, 200, null),
        ], $total, 0, [$this->recargo_del_payload($recargo)], ['sub_total' => 300]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo con la opcion apagada');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 200, null, 'combo con la opcion apagada');

        $this->assertEqualsWithDelta(
            $total,
            SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true),
            self::DELTA,
            'Con la opcion apagada getTotalSale() suma el recargo al pie, como siempre.'
        );
    }

    /**
     * Test 5 — la SPA VIEJA no manda la clave: la base queda en NULL aunque la opcion este
     * prendida. En la SPA nueva ese comprobante queda bloqueado (legado), que es lo seguro: ningun
     * precio cambia.
     *
     * @test
     */
    public function sin_la_clave_la_base_queda_en_null()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $recargo  = $this->recargo();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, false),
            $this->item_vender('service', $servicio->id, 55, false),
        ], 165.00, 1, [$this->recargo_del_payload($recargo)]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, null, 'articulo sin la clave');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 55, null, 'servicio sin la clave');
    }

    /**
     * Test 6 — la respuesta del alta expone la base en el pivot, que es de donde la lee la SPA al
     * reabrir la venta (contrato: `pivot.price_sin_recargos_de_venta`).
     *
     * @test
     */
    public function la_respuesta_expone_la_base_en_el_pivot()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $response = $this->postJson('api/sale', $this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100),
        ], 110.00, 1, [$this->recargo_del_payload($recargo)]));

        $response->assertStatus(201);

        $articulos = $response->json('model.articles');

        $this->assertCount(1, $articulos);

        $this->assertArrayHasKey('price_sin_recargos_de_venta', $articulos[0]['pivot'], 'El pivot de la respuesta tiene que traer la base.');

        $this->assertEqualsWithDelta(100, (float) $articulos[0]['pivot']['price_sin_recargos_de_venta'], 0.000001);

        /* Y al reabrir la venta por su id, lo mismo. */
        $show = $this->getJson('api/sale/'.$response->json('model.id'));

        $show->assertStatus(200);

        $this->assertEqualsWithDelta(100, (float) $show->json('model.articles.0.pivot.price_sin_recargos_de_venta'), 0.000001);
    }
}
