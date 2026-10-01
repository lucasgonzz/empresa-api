<?php

namespace Tests\Feature\IvaEnArticulosSinIva;

use App\Models\Budget;
use App\Models\Sale;

/**
 * Archivo 2 — EL PRESUPUESTO guarda, preserva, devuelve y PROPAGA `iva_en_articulos_sin_iva`.
 *
 * Mismo contrato que la venta (archivo 1), mas los dos caminos que copian un presupuesto con sus
 * precios tal cual: confirmarlo (nace la venta, `BudgetHelper::saveSale()`) y duplicarlo
 * (`BudgetDuplicarHelper::duplicate()`). En los dos, los renglones viajan con el mismo precio, asi
 * que el comprobante nuevo tiene que llevarse el estado del check con el que se armaron.
 *
 * @group presupuestos
 * @group iva_en_articulos_sin_iva
 */
class Presupuesto_guarda_iva_en_articulos_sin_iva_Test extends IvaEnArticulosSinIvaTestCase
{
    /**
     * Alta con el check prendido: queda en 1 y viaja de vuelta en la respuesta.
     *
     * @test
     */
    public function el_alta_con_el_flag_prendido_lo_guarda_y_lo_devuelve()
    {
        $modelo = $this->crear_presupuesto_por_endpoint($this->payload_presupuesto([
            'iva_en_articulos_sin_iva' => 1,
        ]));

        $this->assertSame(
            1,
            (int) Budget::find($modelo['id'])->iva_en_articulos_sin_iva,
            'Con el check prendido en Vender, el presupuesto tiene que guardarlo en 1.'
        );

        $this->assertArrayHasKey('iva_en_articulos_sin_iva', $modelo, 'El presupuesto que vuelve a la SPA tiene que traer el flag.');
        $this->assertSame(1, (int) $modelo['iva_en_articulos_sin_iva']);
    }

    /**
     * 🔴 SPA vieja: el alta no manda la clave y el presupuesto queda en 0.
     *
     * @test
     */
    public function el_alta_sin_el_flag_lo_deja_apagado()
    {
        $payload = $this->payload_presupuesto();

        $this->assertArrayNotHasKey('iva_en_articulos_sin_iva', $payload, 'El escenario es el POST de una SPA vieja.');

        $modelo = $this->crear_presupuesto_por_endpoint($payload);

        $this->assertSame(0, (int) Budget::find($modelo['id'])->iva_en_articulos_sin_iva, 'Un alta sin la clave tiene que quedar en 0.');
    }

    /**
     * El update que manda la clave la cambia, en los dos sentidos.
     *
     * @test
     */
    public function el_update_con_el_flag_lo_cambia_en_los_dos_sentidos()
    {
        $budget = $this->presupuesto_en_base(['iva_en_articulos_sin_iva' => 0]);

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar_presupuesto($budget, [
            'iva_en_articulos_sin_iva' => 1,
        ]))->assertStatus(200);

        $this->assertSame(1, (int) Budget::find($budget->id)->iva_en_articulos_sin_iva, 'El PUT con 1 tiene que prenderlo.');

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar_presupuesto($budget, [
            'iva_en_articulos_sin_iva' => 0,
        ]))->assertStatus(200);

        $this->assertSame(0, (int) Budget::find($budget->id)->iva_en_articulos_sin_iva, 'El PUT con 0 tiene que apagarlo.');
    }

    /**
     * 🔴 SPA vieja editando un presupuesto que tenia el check prendido: se preserva lo guardado.
     *
     * @test
     */
    public function el_update_sin_el_flag_preserva_lo_guardado()
    {
        $budget = $this->presupuesto_en_base(['iva_en_articulos_sin_iva' => 1]);

        $payload = $this->payload_actualizar_presupuesto($budget);

        $this->assertArrayNotHasKey('iva_en_articulos_sin_iva', $payload, 'El escenario es el PUT de una SPA vieja.');

        $this->putJson('api/budget/'.$budget->id, $payload)->assertStatus(200);

        $this->assertSame(
            1,
            (int) Budget::find($budget->id)->iva_en_articulos_sin_iva,
            'Un PUT sin la clave le cambio el flag al presupuesto.'
        );
    }

    /**
     * 🔴 Confirmar un presupuesto con el check prendido: la venta que nace se lo lleva. Los
     * renglones pasan con el mismo precio (con el IVA ya sumado a los articulos sin IVA), y si la
     * venta quedara en 0, al editarla la SPA le volveria a sumar el IVA encima.
     *
     * @test
     */
    public function confirmar_el_presupuesto_le_pasa_el_flag_a_la_venta()
    {
        $modelo = $this->crear_presupuesto_por_endpoint($this->payload_presupuesto([
            'iva_en_articulos_sin_iva' => 1,
        ]));

        $this->postJson('api/budget/'.$modelo['id'].'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $modelo['id'])->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertSame(
            1,
            (int) $sale->iva_en_articulos_sin_iva,
            'La venta que nace del presupuesto tiene que llevarse el flag.'
        );
    }

    /**
     * Y con el check apagado la venta nace apagada (no hereda un 1 de ningun lado).
     *
     * @test
     */
    public function confirmar_un_presupuesto_sin_el_flag_deja_la_venta_apagada()
    {
        $modelo = $this->crear_presupuesto_por_endpoint($this->payload_presupuesto([
            'iva_en_articulos_sin_iva' => 0,
        ]));

        $this->postJson('api/budget/'.$modelo['id'].'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $modelo['id'])->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertSame(0, (int) $sale->iva_en_articulos_sin_iva);
    }

    /**
     * Duplicar un presupuesto con el check prendido: el duplicado se lo lleva.
     *
     * @test
     */
    public function duplicar_el_presupuesto_copia_el_flag()
    {
        $this->dar_extencion_duplicar();

        $origen = $this->presupuesto_en_base(['iva_en_articulos_sin_iva' => 1]);

        $nuevo_id = $this->postJson('api/budget/'.$origen->id.'/duplicate')
                            ->assertStatus(201)
                            ->json('model.id');

        $duplicado = Budget::find($nuevo_id);

        $this->assertNotNull($duplicado, 'El duplicado tiene que existir.');
        $this->assertNotEquals($origen->id, $duplicado->id, 'Tiene que ser otro presupuesto.');

        $this->assertSame(
            1,
            (int) $duplicado->iva_en_articulos_sin_iva,
            'El duplicado tiene que llevarse el flag del presupuesto original.'
        );
    }
}
