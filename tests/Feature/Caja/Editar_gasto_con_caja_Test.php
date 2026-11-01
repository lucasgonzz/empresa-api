<?php

namespace Tests\Feature\Caja;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Tests\EmpresaTestCase;

/**
 * Misión php84-errores-fatales (1/11/2026), caso B2: editar un gasto pagado desde una caja fallaba en
 * TODOS los clientes del VPS. `ExpenseCajaHelper::editar_movimiento_caja()` llamaba como estático a
 * `MovimientoCajaHelper::set_apertura_caja_ingresos_egresos()`, que era un método de instancia: en
 * PHP 8 (las APIs del VPS corren 8.4) es un Error fatal y el `DB::transaction` revertía el gasto
 * entero; en el 7.4 del shared solo era un deprecated y por eso nadie lo veía.
 *
 * La segunda firma del mismo grupo: `recalcular_saldos()` asignaba propiedades sobre una apertura
 * `null` cuando la caja no tenía apertura abierta.
 *
 * Los números son la especificación: está prohibido ajustar un esperado para que pase.
 */
class Editar_gasto_con_caja_Test extends EmpresaTestCase
{
    protected $caja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caja = Caja::create(['name' => 'zz Caja editar gasto', 'num' => 990020, 'user_id' => 500, 'saldo' => 1000, 'moneda_id' => 1]);
        (new CajaAperturaHelper($this->caja->id))->abrir_caja();
        $this->caja = $this->caja->fresh();
    }

    /** Crea un gasto de $100 pagado desde la caja, por el endpoint real. */
    protected function gasto_pagado_desde_la_caja($monto = 100)
    {
        $respuesta = $this->postJson('api/expense', ['amount' => $monto, 'moneda_id' => 1,
            'payment_methods' => [['current_acount_payment_method_id' => 3, 'amount' => $monto, 'caja_id' => $this->caja->id,
                'moneda_id' => 1, 'cotizacion' => null, 'amount_cotizado' => null]]]);

        $respuesta->assertStatus(201);

        return Expense::findOrFail($respuesta->json('model.id'));
    }

    protected function editar($gasto, $monto)
    {
        return $this->putJson('api/expense/'.$gasto->id, ['amount' => $monto, 'importe_iva' => 0,
            'created_at' => now()->format('Y-m-d H:i:s')]);
    }

    /** @test */
    public function editar_el_monto_de_un_gasto_pagado_desde_una_caja_con_apertura_recalcula_saldos_y_totales()
    {
        $gasto = $this->gasto_pagado_desde_la_caja(100);
        $apertura_id = $this->caja->current_apertura_caja_id;

        $this->assertEquals(900, $this->caja->fresh()->saldo);

        $this->editar($gasto, 250)->assertStatus(200);

        $movimiento = MovimientoCaja::where('expense_id', $gasto->id)->firstOrFail();

        $this->assertEquals(250, $movimiento->egreso);
        $this->assertEquals(750, $this->caja->fresh()->saldo);

        $apertura = AperturaCaja::findOrFail($apertura_id);
        $this->assertEquals(250, $apertura->total_egresos);
        $this->assertEquals(0, $apertura->total_ingresos);
        $this->assertEquals(250, $gasto->fresh()->amount);
    }

    /** @test */
    public function editar_un_gasto_cuya_caja_ya_no_tiene_apertura_no_tira_error_ni_deja_el_saldo_en_null()
    {
        $gasto = $this->gasto_pagado_desde_la_caja(100);

        $this->assertEquals(900, $this->caja->fresh()->saldo);

        // La caja queda sin apertura abierta (p. ej. otro turno la cerró y la reabrió después de cargado el gasto).
        Caja::where('id', $this->caja->id)->update(['current_apertura_caja_id' => null]);

        $this->editar($gasto, 250)->assertStatus(200);

        $this->assertEquals(250, $gasto->fresh()->amount);
        $this->assertNotNull($this->caja->fresh()->saldo, 'sin apertura no hay nada que recalcular: el saldo no se pisa con null');
        $this->assertEquals(900, $this->caja->fresh()->saldo);
    }

    /** @test */
    public function recalcular_saldos_de_una_caja_sin_apertura_o_inexistente_no_hace_nada()
    {
        $this->caja->current_apertura_caja_id = null;
        $this->caja->save();

        \App\Http\Controllers\Helpers\caja\MovimientoCajaHelper::recalcular_saldos(null, $this->caja->id);
        \App\Http\Controllers\Helpers\caja\MovimientoCajaHelper::recalcular_saldos(null, 99999999);

        $this->assertEquals(1000, $this->caja->fresh()->saldo);
    }

    /** @test */
    public function set_apertura_caja_ingresos_egresos_con_una_apertura_inexistente_no_hace_nada()
    {
        \App\Http\Controllers\Helpers\caja\MovimientoCajaHelper::set_apertura_caja_ingresos_egresos(99999999);

        $this->assertTrue(true);
    }
}
