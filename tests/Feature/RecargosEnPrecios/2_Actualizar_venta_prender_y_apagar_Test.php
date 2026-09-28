<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 2 — EDITAR una venta ya creada prendiendo o apagando la opcion.
 *
 * Es el pedido de Lucas, visto desde la API: la SPA recalcula los precios (base <-> base × factor)
 * y manda el PUT; la API tiene que dejar cada renglon con el precio y la base nuevos, y el flag
 * nuevo. Prender o apagar nunca decide SI el recargo se cobra (decision 2): solo lo mueve del precio
 * al pie o del pie al precio.
 *
 * ⚠️ El total no queda identico al centavo en cualquier venta: con el recargo adentro la SPA
 * redondea el precio UNITARIO a centavos y al pie no, asi que puede moverse hasta medio centavo por
 * unidad (ver la migracion `2026_09_28_100000`). Los numeros de estos tests dan exacto a proposito
 * (100 con 10 % = 110), para medir que la API guarda lo que le mandan sin mezclarlo con ese redondeo.
 *
 * El PUT es el camino real (`SaleController::update()` -> `detachItems()` -> `attachProperies()`),
 * no un `updateExistingPivot` armado a mano: es el que re-adjunta los renglones y el que tendria que
 * perder la base si algo se olvidara.
 *
 * @group recargos_en_precios
 */
class Actualizar_venta_prender_y_apagar_Test extends RecargosEnPreciosTestCase
{
    /**
     * Test 1 — prendida -> apagada: los precios vuelven a la base, la base queda en NULL, el flag
     * en 0 y el total no se mueve (el 10 % pasa al pie).
     *
     * @test
     */
    public function apagar_la_opcion_devuelve_los_precios_a_la_base_y_el_total_no_cambia()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $recargo  = $this->recargo();

        /* Prendida: 110 × 2 + 55 = 275. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100, 2),
            $this->item_vender('service', $servicio->id, 55, 50),
        ], 275.00, 1, [$this->recargo_del_payload($recargo)]));

        /* Apagada: (100 × 2 + 50) = 250 de sub total, mas el 10 % al pie = 275. Mismo total. */
        $response = $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 100, null, 2),
            $this->item_vender('service', $servicio->id, 50, null),
        ], 275.00, 0, [$this->recargo_del_payload($recargo)], ['sub_total' => 250]));

        $response->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(0, (int) $sale->aplicar_recargos_directo_a_items, 'El PUT con el flag en 0 lo tiene que apagar.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo apagado');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 50, null, 'servicio apagado');

        $this->assertEqualsWithDelta(275.00, (float) $sale->total, self::DELTA, 'El total guardado no cambia al apagar.');

        $this->assertEqualsWithDelta(
            275.00,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'getTotalSale() con la opcion apagada suma el recargo al pie y da el mismo 275.'
        );
    }

    /**
     * Test 2 — apagada -> prendida: el precio pasa a traer el recargo, la base guarda el de antes,
     * el flag en 1 y el total no se mueve.
     *
     * @test
     */
    public function prender_la_opcion_mete_el_recargo_en_el_precio_y_guarda_la_base()
    {
        $articulo = $this->articulo_centinela();
        $combo    = $this->combo(200);
        $recargo  = $this->recargo();

        /* Apagada: (100 + 200) mas el 10 % al pie = 330. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 100, null),
            $this->item_vender('combo', $combo->id, 200, null),
        ], 330.00, 0, [$this->recargo_del_payload($recargo)], ['sub_total' => 300]));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 100, null, 'articulo antes de prender');

        /* Prendida: 110 + 220 = 330. */
        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 110, 100),
            $this->item_vender('combo', $combo->id, 220, 200),
        ], 330.00, 1, [$this->recargo_del_payload($recargo)]))->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items, 'El PUT con el flag en 1 lo tiene que prender.');

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, 100, 'articulo prendido');
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 220, 200, 'combo prendido');

        $this->assertEqualsWithDelta(
            330.00,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'getTotalSale() con la opcion prendida no vuelve a sumar el recargo: da el mismo 330.'
        );
    }

    /**
     * Test 3 — un PUT de la SPA VIEJA (sin la clave) sobre una venta prendida deja la base en NULL.
     *
     * No puede preservar la base guardada: en la venta, a diferencia del presupuesto, el PUT lo
     * manda solo VENDER, y una SPA que no conoce la clave tampoco sabe si el precio que manda trae
     * el recargo. NULL deja el comprobante bloqueado en la SPA nueva, que es lo seguro. Y el precio
     * que mando se guarda tal cual: la API nunca calcula.
     *
     * @test
     */
    public function un_put_sin_la_clave_deja_la_base_en_null()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100),
        ], 110.00, 1, [$this->recargo_del_payload($recargo)]));

        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 110, false),
        ], 110.00, 1, [$this->recargo_del_payload($recargo)]))->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, null, 'articulo editado por la SPA vieja');
    }
}
