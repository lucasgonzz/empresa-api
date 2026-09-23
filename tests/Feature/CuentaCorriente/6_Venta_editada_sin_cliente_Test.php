<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — una venta editada para dejarla SIN
 * cliente sale de la cuenta corriente del cliente que tenía.
 *
 * Hasta hoy (ya pasaba en develop) `SaleController::update()` solo tocaba la cuenta corriente si la
 * venta quedaba con cliente, así que al sacarle el cliente el movimiento se quedaba en la cuenta del
 * cliente viejo: le seguía cobrando una venta que ya no era suya. Se prueba por el endpoint real
 * (`PUT api/sale/{id}`), con el payload mínimo que usan los otros tests de edición de ventas.
 *
 * @group cuenta-corriente
 */
class Venta_editada_sin_cliente_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /**
     * @test
     */
    public function sacarle_el_cliente_a_una_venta_la_saca_de_la_cuenta_y_la_recalcula()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Venta sin cliente');

        $dia = Carbon::parse('2026-09-14');

        $ventas = [];

        foreach ([100, 200, 300] as $i => $total) {
            $ventas[$i] = $this->venta($cliente, $total, $dia->copy()->setTime(9 + $i, 0));
            SaleHelper::create_current_acount($ventas[$i]);
        }

        $del_medio = $ventas[1];

        $this->assertEquals(1, CurrentAcount::where('sale_id', $del_medio->id)->count());

        $respuesta = $this->putJson('api/sale/'.$del_medio->id, [
            'client_id'              => null,
            'save_current_acount'    => 1,
            'items'                  => [],
            'discounts'              => [],
            'surchages'              => [],
            'returned_items'         => [],
            'sub_total'              => $del_medio->sub_total,
            'total'                  => $del_medio->total,
            'discounts_in_services'  => 0,
            'surchages_in_services'  => 0,
            'to_check'               => 0,
            'checked'                => 0,
            'confirmed'              => 0,
        ]);

        $respuesta->assertStatus(200);

        $this->assertNull($del_medio->fresh()->client_id);

        $this->assertEquals(0, CurrentAcount::where('sale_id', $del_medio->id)->count(), 'El movimiento de la venta no puede quedar en la cuenta del cliente viejo.');

        $saldo = $this->assert_cadena_cierra($cuenta->id, 'Cuenta del cliente que tenía la venta');

        $this->assertEqualsWithDelta(400, $saldo, 0.01);
        $this->assertEqualsWithDelta(400, (float) $cliente->fresh()->saldo_pesos, 0.01);
    }
}
