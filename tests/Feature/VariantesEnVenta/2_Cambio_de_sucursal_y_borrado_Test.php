<?php

namespace Tests\Feature\VariantesEnVenta;

/**
 * Archivo 2 — cambiar la sucursal de una venta al editarla y borrar la venta, con el mismo artículo
 * en varias variantes (misión variantes-mismo-articulo-en-vender, 8/10/2026). Tests 3 y 4 del plan.
 *
 * @group variantes_en_venta
 */
class Cambio_de_sucursal_y_borrado_Test extends VariantesEnVentaTestCase
{
    /**
     * Test 3 — la venta sale de la sucursal 1 y al editarla pasa a la 2: lo vendido vuelve a la 1 y
     * sale de la 2, cada variante en la suya. Borrarla después repone en la 2.
     *
     * @test
     */
    public function cambiar_la_sucursal_al_editar_devuelve_a_la_vieja_y_descuenta_de_la_nueva_por_variante()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id]);

        $this->assert_variante($e, 'm', 4, 4, 'Alta en la sucursal 1');
        $this->assert_variante($e, 'l', 2, 5, 'Alta en la sucursal 1');

        /* Mismos renglones, sucursal 2. */
        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 2, 'PUT a la sucursal 2');
        $this->assert_variante($e, 'l', 5, 2, 'PUT a la sucursal 2');
        $this->assert_variante($e, 's', 3, 2, 'PUT a la sucursal 2 (la S no está en la venta)');
        $this->assertEquals(20.0, $this->stock($e['articulo']), 'El total no cambia: solo cambia de dónde salió.');
        $this->assertEquals(9.0, $this->stock($e['testigo']), 'El testigo lleva stock global: no se mueve por cambiar la sucursal.');

        /* Guardar de nuevo en la 2 sin cambios no mueve nada. */
        $movimientos = $this->cantidad_de_movimientos($venta);

        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assertEquals($movimientos, $this->cantidad_de_movimientos($venta), 'Volver a guardar en la misma sucursal no deja movimientos.');

        /* Borrar la venta repone en la sucursal 2, que es de donde la venta tiene descontado. */
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE');
        $this->assertEquals(25.0, $this->stock($e['articulo']));
        $this->assertEquals(10.0, $this->stock($e['testigo']));
    }

    /**
     * Test 3 bis — cambiar la sucursal Y las cantidades en el mismo guardado: M×2→M×1 y se agrega
     * la S×1, de la 1 a la 2.
     *
     * @test
     */
    public function cambiar_la_sucursal_y_las_cantidades_a_la_vez()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
        ], ['address_id' => $e['suc1']->id]);

        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 1),
            $this->renglon($e, $e['l'], 3),
            $this->renglon($e, $e['s'], 1),
        ], ['address_id' => $e['suc2']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 3, 'PUT a la 2 con M×1');
        $this->assert_variante($e, 'l', 5, 2, 'PUT a la 2 con L×3');
        $this->assert_variante($e, 's', 3, 1, 'PUT a la 2 con la S nueva');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE');
        $this->assert_variante($e, 's', 3, 2, 'DELETE');
    }

    /**
     * Test 4 — borrar la venta devuelve cada variante a su depósito y el testigo a su stock global.
     *
     * @test
     */
    public function borrar_la_venta_devuelve_cada_variante_a_su_deposito()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon($e, $e['s'], 1),
            $this->renglon_testigo($e, 2),
        ], ['address_id' => $e['suc2']->id]);

        $this->assert_variante($e, 'm', 6, 2, 'Alta en la 2');
        $this->assert_variante($e, 'l', 5, 2, 'Alta en la 2');
        $this->assert_variante($e, 's', 3, 1, 'Alta en la 2');
        $this->assertEquals(8.0, $this->stock($e['testigo']));

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'DELETE');
        $this->assert_variante($e, 'l', 5, 5, 'DELETE');
        $this->assert_variante($e, 's', 3, 2, 'DELETE');
        $this->assertEquals(25.0, $this->stock($e['articulo']));
        $this->assertEquals(10.0, $this->stock($e['testigo']));
    }
}
