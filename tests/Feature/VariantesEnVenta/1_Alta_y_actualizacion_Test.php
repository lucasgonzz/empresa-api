<?php

namespace Tests\Feature\VariantesEnVenta;

use Illuminate\Support\Facades\DB;

/**
 * Archivo 1 — alta y actualización de una venta con el mismo artículo dos o más veces, una por
 * variante (misión variantes-mismo-articulo-en-vender, 8/10/2026). Tests 1 y 2 del plan, más B4
 * (el chequeo de "falta el artículo" por variante) y B5 (`fecha_agregado` por variante).
 *
 * Todo por los endpoints reales (`POST /api/sale`, `PUT /api/sale/{id}`) y leyendo la base.
 *
 * @group variantes_en_venta
 */
class Alta_y_actualizacion_Test extends VariantesEnVentaTestCase
{
    /**
     * Test 1 — alta con M×2 y L×3 desde la sucursal 1: baja solo (M, suc1) y (L, suc1), el resto
     * queda igual, y la venta tiene dos renglones, cada uno con su variante.
     *
     * @test
     */
    public function el_alta_descuenta_cada_variante_de_su_deposito_y_guarda_un_renglon_por_variante()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id]);

        $this->assert_variante($e, 'm', 4, 4, 'Alta');
        $this->assert_variante($e, 'l', 2, 5, 'Alta');
        $this->assert_variante($e, 's', 3, 2, 'Alta (S no se vendió)');
        $this->assertEquals(20.0, $this->stock($e['articulo']));
        $this->assertEquals(9.0, $this->stock($e['testigo']), 'El testigo baja 1 de su stock global.');

        $filas = $this->filas($venta, $e['articulo']);

        $this->assertCount(2, $filas, 'Un renglón por variante.');
        $this->assertEquals(2.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->amount);
        $this->assertEquals(3.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->amount);
        $this->assertEquals('Talle L', $this->fila_de($venta, $e['articulo'], $e['l'])->variant_description);

        // El libro: un movimiento "Venta" por variante, desde la sucursal 1.
        $ventas = $this->movimientos($e['articulo'], 'Venta');

        $this->assertCount(2, $ventas);

        foreach ($ventas as $movimiento) {
            $this->assertEquals($e['suc1']->id, (int) $movimiento->from_address_id);
            $this->assertNotNull($movimiento->article_variant_id, 'Ningún movimiento de la venta va al artículo sin variante.');
        }
    }

    /**
     * Test 2 — actualización: guardar sin cambios no mueve nada; después M×2→M×1, se saca la L y se
     * agrega la S×1. Cada variante queda bien, en su depósito.
     *
     * @test
     */
    public function la_actualizacion_mueve_cada_variante_por_diferencia_y_guardar_sin_cambios_no_mueve_nada()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id]);

        $movimientos_del_alta = $this->cantidad_de_movimientos($venta);

        /* Guardar sin cambios. */
        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 3),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id])->assertStatus(200);

        $this->assertEquals($movimientos_del_alta, $this->cantidad_de_movimientos($venta), 'Guardar sin cambios no deja movimientos.');
        $this->assert_variante($e, 'm', 4, 4, 'PUT sin cambios');
        $this->assert_variante($e, 'l', 2, 5, 'PUT sin cambios');
        $this->assertEquals(9.0, $this->stock($e['testigo']));

        /* M×2 → M×1, sin la L, con la S×1. */
        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 1),
            $this->renglon($e, $e['s'], 1),
            $this->renglon_testigo($e),
        ], ['address_id' => $e['suc1']->id])->assertStatus(200);

        $this->assert_variante($e, 'm', 5, 4, 'PUT con cambios');
        $this->assert_variante($e, 'l', 5, 5, 'PUT con cambios (la L sacada vuelve a la sucursal 1)');
        $this->assert_variante($e, 's', 2, 2, 'PUT con cambios (la S nueva sale de la sucursal 1)');
        $this->assertEquals(23.0, $this->stock($e['articulo']));
        $this->assertEquals(9.0, $this->stock($e['testigo']), 'El testigo no se tocó.');

        $this->assertCount(2, $this->filas($venta, $e['articulo']), 'Quedan los renglones de M y S.');
        $this->assertNull($this->fila_de($venta, $e['articulo'], $e['l']), 'La L ya no está en la venta.');
        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], $e['s'])->amount);
    }

    /**
     * B5 — `fecha_agregado` se decide por artículo + variante: una variante NUEVA del mismo artículo
     * agregada en la edición lleva la fecha en que se agregó; la que ya estaba conserva la suya (null
     * desde el alta).
     *
     * @test
     */
    public function una_variante_nueva_agregada_al_editar_lleva_fecha_agregado()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
        ], ['address_id' => $e['suc1']->id]);

        $this->assertNull($this->fila_de($venta, $e['articulo'], $e['m'])->fecha_agregado, 'Un renglón del alta no tiene fecha de agregado.');

        $this->actualizar_venta($venta, [
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 1),
        ], ['address_id' => $e['suc1']->id])->assertStatus(200);

        $this->assertNull($this->fila_de($venta, $e['articulo'], $e['m'])->fecha_agregado, 'La M ya estaba: conserva su fecha (null).');
        $this->assertNotNull($this->fila_de($venta, $e['articulo'], $e['l'])->fecha_agregado, 'La L se agregó en la edición: tiene que llevar la fecha en que se agregó, aunque el artículo ya estuviera con otra variante.');
    }

    /**
     * B4 — el chequeo "que no falte el artículo" (`check_que_este_el_articulos`) busca por artículo Y
     * variante. Un renglón con cantidad 0 no lo adjunta `attachArticles()` y lo adjunta el chequeo,
     * igual que a un artículo sin variantes; con otra variante del mismo artículo en la venta, el
     * chequeo encontraba esa otra fila y el renglón se perdía.
     *
     * @test
     */
    public function un_renglon_de_otra_variante_no_tapa_el_chequeo_de_que_este_el_articulo()
    {
        $e = $this->escenario();

        $venta = $this->crear_venta([
            $this->renglon($e, $e['m'], 2),
            $this->renglon($e, $e['l'], 0),
            $this->renglon_testigo($e, 0),
        ], ['address_id' => $e['suc1']->id]);

        // El criterio de siempre, para un artículo sin variante: el chequeo lo adjunta igual.
        $this->assertNotNull(
            DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $e['testigo']->id)->first(),
            'El testigo con cantidad 0 lo adjunta el chequeo (criterio previo, sin variantes).'
        );

        $this->assertNotNull($this->fila_de($venta, $e['articulo'], $e['l']), 'La L con cantidad 0 tiene que quedar en la venta, igual que un artículo sin variantes: la fila de la M no la reemplaza.');
        $this->assert_variante($e, 'l', 5, 5, 'La L con cantidad 0 no mueve stock');
    }
}
