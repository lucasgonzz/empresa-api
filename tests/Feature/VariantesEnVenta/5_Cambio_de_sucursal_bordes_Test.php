<?php

namespace Tests\Feature\VariantesEnVenta;

/**
 * Archivo 5 — bordes del cambio de sucursal al editar (B1), pedidos por la revisión independiente
 * de la misión variantes-mismo-articulo-en-vender (8/10/2026): ida y vuelta, cambio de sucursal con
 * un renglón sacado en el mismo guardado, y cambio de sucursal con una NC en el medio.
 *
 * @group variantes_en_venta
 */
class Cambio_de_sucursal_bordes_Test extends VariantesEnVentaTestCase
{
    /**
     * (a) Sucursal 1 → 2 → 1 en dos ediciones: el stock vuelve a quedar como después del alta, y
     * borrar la venta deja todo como al principio.
     *
     * @test
     */
    public function ida_y_vuelta_de_sucursal_en_dos_ediciones()
    {
        $e = $this->escenario();

        $items = [
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ];

        $venta = $this->crear_venta($items, ['address_id' => $e['suc1']->id]);

        $this->actualizar_venta($venta, $items, ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 2, 'A la sucursal 2');
        $this->assert_variante($e, 'l', 5, 2, 'A la sucursal 2');

        $this->actualizar_venta($venta, $items, ['address_id' => $e['suc1']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 4, 4, 'De vuelta a la sucursal 1');
        $this->assert_variante($e, 'l', 2, 5, 'De vuelta a la sucursal 1');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE');
    }

    /**
     * (b) Cambiar la sucursal Y sacar un renglón en el mismo guardado: la L sacada termina entera
     * en la sucursal 1 (de donde salió) y la M pasa a salir de la 2.
     *
     * @test
     */
    public function cambiar_la_sucursal_y_sacar_un_renglon_a_la_vez()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
        ], ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 2, 'M pasa a salir de la 2');
        $this->assert_variante($e, 'l', 5, 5, 'La L sacada vuelve entera, y a sus depósitos de origen');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE');
    }

    /**
     * (c) Venta desde la 1, devolución de L×1 a la 2, cambio de la venta a la 2, borrar la NC y
     * borrar la venta: cada paso deja cada variante en su depósito, y al final todo como al
     * principio.
     *
     * @test
     */
    public function cambio_de_sucursal_con_una_nc_en_el_medio_y_despues_borrar_nc_y_venta()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $nota_credito = $this->devolver($venta, [$this->item_devolucion($this->fila_de($venta, $e['articulo'], $e['l']), 1)], $e['suc2']->id);

        $this->assert_variante($e, 'l', 2, 6, 'Devolución de L×1 a la 2');

        // La SPA reabre la venta con lo devuelto de cada renglón.
        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
            array_merge($this->renglon($e, $e['l'], 3), ['returned_amount' => 1]),
        ], ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 2, 'Venta a la 2');
        $this->assert_variante($e, 'l', 5, 3, 'Venta a la 2: las 3 L vuelven a la 1 y salen de la 2, la devuelta sigue en la 2');

        $this->deleteJson('api/current-acount/client/'.$nota_credito->id)->assertStatus(200);

        $this->assert_variante($e, 'l', 5, 2, 'Borrar la NC: la unidad sale de la 2, adonde había entrado');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE de la venta');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE de la venta');
        $this->assert_variante($e, 's', 3, 2, 'La S nunca se tocó');
    }
}
