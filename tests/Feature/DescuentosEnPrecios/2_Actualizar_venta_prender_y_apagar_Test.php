<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 2 — EDITAR una venta ya creada prendiendo o apagando la opcion de descuentos.
 *
 * La SPA recalcula los precios (original <-> original × factor de descuentos) y manda el PUT; la API
 * tiene que dejar cada renglon con el precio y la base nuevos, y el flag nuevo. Prender o apagar
 * nunca decide SI el descuento se cobra: solo lo mueve del pie al precio o del precio al pie.
 *
 * ⚠️ El total no queda identico al centavo en cualquier venta: con el descuento adentro la SPA
 * redondea el precio UNITARIO a centavos y al pie no, asi que puede moverse hasta medio centavo por
 * unidad. Los numeros de estos tests dan exacto a proposito (100 con 10 % = 90), para medir que la
 * API guarda lo que le mandan sin mezclarlo con ese redondeo.
 *
 * El PUT es el camino real (`SaleController::update()` -> `detachItems()` -> `attachProperies()`),
 * no un `updateExistingPivot` armado a mano: es el que re-adjunta los renglones y el que tendria que
 * perder la base si algo se olvidara.
 *
 * @group descuentos_en_precios
 */
class Actualizar_venta_prender_y_apagar_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Test 1 — prendida -> apagada: los precios vuelven al original, la base queda en NULL, el flag
     * en 0 y el total no se mueve (el 10 % pasa al pie).
     *
     * @test
     */
    public function apagar_la_opcion_devuelve_los_precios_al_original_y_el_total_no_cambia()
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $descuento = $this->descuento();

        /* Prendida: 90 × 2 + 45 = 225. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100, 2),
            $this->item_vender('service', $servicio->id, 45, 50),
        ], 225.00, 1, [$this->descuento_del_payload($descuento)]));

        /* Apagada: (100 × 2 + 50) = 250 de sub total, menos el 10 % al pie = 225. Mismo total. */
        $response = $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 100, null, 2),
            $this->item_vender('service', $servicio->id, 50, null),
        ], 225.00, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 250]));

        $response->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(0, (int) $sale->aplicar_descuentos_directo_a_items, 'El PUT con el flag en 0 lo tiene que apagar.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo apagado');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 50, null, 'servicio apagado');

        $this->assertEqualsWithDelta(225.00, (float) $sale->total, self::DELTA, 'El total guardado no cambia al apagar.');

        $this->assertEqualsWithDelta(
            225.00,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'getTotalSale() con la opcion apagada resta el descuento al pie y da el mismo 225.'
        );
    }

    /**
     * Test 2 — apagada -> prendida: el precio pasa a traer el descuento, la base guarda el original,
     * el flag en 1 y el total no se mueve.
     *
     * @test
     */
    public function prender_la_opcion_mete_el_descuento_en_el_precio_y_guarda_la_base()
    {
        $articulo  = $this->articulo_centinela();
        $combo     = $this->combo(200);
        $descuento = $this->descuento();

        /* Apagada: (100 + 200) menos el 10 % al pie = 270. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 100, null),
            $this->item_vender('combo', $combo->id, 200, null),
        ], 270.00, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 300]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo antes de prender');

        /* Prendida: 90 + 180 = 270. */
        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 90, 100),
            $this->item_vender('combo', $combo->id, 180, 200),
        ], 270.00, 1, [$this->descuento_del_payload($descuento)]))->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items, 'El PUT con el flag en 1 lo tiene que prender.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo prendido');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 180, 200, 'combo prendido');

        $this->assertEqualsWithDelta(
            270.00,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'getTotalSale() con la opcion prendida no vuelve a restar el descuento: da el mismo 270.'
        );
    }

    /**
     * Test 3 — un PUT de la SPA VIEJA (sin la clave del flag ni la de la base) sobre una venta
     * prendida se RECHAZA con 409 y la venta no cambia en nada.
     *
     * Antes de este candado (`SaleController::update()` ->
     * `DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva()`) la API preservaba el flag y dejaba la
     * base en NULL, pero guardaba el `total` que mandaba la SPA vieja, que ya trae el descuento restado
     * dos veces: la venta quedaba con el total mal para siempre. Todos los casos del candado, incluidos
     * los que si pasan, estan en el archivo 15.
     *
     * @test
     */
    public function un_put_sin_las_claves_se_rechaza_y_la_venta_no_cambia()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100),
        ], 90.00, 1, [$this->descuento_del_payload($descuento)]));

        $payload = $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 90, false),
        ], 90.00, 1, [$this->descuento_del_payload($descuento)]);

        /* La SPA anterior a esta mision no conoce el flag: la clave NO viaja. */
        unset($payload['aplicar_descuentos_directo_a_items']);

        $this->putJson('api/sale/'.$sale->id, $payload)->assertStatus(409);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items, 'El flag guardado no se toca.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo: el rechazo no toca los renglones');

        $this->assertEqualsWithDelta(
            90.00,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'La venta sigue cerrando: el descuento no se resta dos veces.'
        );
    }
}
