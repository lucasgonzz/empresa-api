<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Misión cheques-endoso-y-bancos — endosar un cheque recibido desde el modal de métodos de pago
 * de un GASTO. No hay proveedor: el recibido queda marcado con el gasto
 * (`endosado_en_expense_id`) y la copia emitida cuelga del gasto (`expense_id`), sin cuenta
 * corriente.
 *
 * También fija el defecto que se corrige de paso: un cheque cargado en un gasto guarda el gasto en
 * `expense_id` y deja `current_acount_id` en null (antes quedaba el id del gasto ahí, apuntando a
 * una cuenta corriente cualquiera).
 *
 * @group cheques
 */
class Endoso_en_gasto_Test extends ChequesTestCase
{
    /**
     * @test
     */
    public function el_gasto_con_un_cheque_elegido_endosa_ese_cheque_en_el_gasto()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del cheque gasto ' . uniqid());

        // Con fecha de pago HOY el recibido está "disponible para cobrar" (le quedan 30 días).
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, [
            'numero'     => '3003',
            'banco'      => 'Banco Galicia',
            'fecha_pago' => Carbon::today()->format('Y-m-d'),
        ]);

        $this->assertEquals('recibido.disponibles_para_cobrar', $this->solapa_de($recibido->id));
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();
        $movimientos_antes = $this->max_id_movimiento_caja();

        $fila = $this->fila_de_gasto($this->claves_de_endoso($recibido));

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $response->assertStatus(201);

        $gasto = Expense::find((int) $response->json('model.id'));
        $this->gastos_creados_por_escenarios[] = $gasto->id;
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        // --- El recibido salió de cartera, marcado con el gasto ---------------------------------
        $recibido = $recibido->fresh();

        $this->assertEquals($gasto->id, $recibido->endosado_en_expense_id);
        $this->assertNull($recibido->endosado_a_provider_id);
        $this->assertNotNull($recibido->fecha_endoso);
        $this->assertNull($recibido->estado_manual);
        $this->assertEquals('recibido.endosados', $this->solapa_de($recibido->id));
        $this->assertNotContains($recibido->id, $this->ids_disponibles_para_endosar());

        // En el listado viaja el gasto con su concepto, que es lo que la solapa Endosados muestra
        // en vez del proveedor.
        $del_listado = $this->cheque_del_listado($recibido->id);

        $this->assertNull($del_listado['endosado_a_provider']);
        $this->assertEquals($gasto->id, $del_listado['endosado_en_expense']['id']);
        $this->assertEquals(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, $del_listado['endosado_en_expense']['expense_concept']['name']);
        $this->assertArrayHasKey('cheque_banco', $del_listado);

        // --- UNA copia emitida, colgada del gasto y sin cuenta corriente ------------------------
        $this->assertEquals($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count());

        $copias = $this->copias_de($recibido);

        $this->assertCount(1, $copias);

        $copia = $copias->first();

        $this->assertEquals('emitido', $copia->tipo);
        $this->assertEquals($gasto->id, $copia->expense_id);
        $this->assertNull($copia->current_acount_id);
        $this->assertNull($copia->provider_id);
        $this->assertNull($copia->client_id);
        $this->assertEquals($cliente->id, $copia->endosado_desde_client_id);
        $this->assertEquals($recibido->id, $copia->endosado_desde_cheque_id);
        $this->assertEquals('3003', $copia->numero);
        $this->assertEquals('Banco Galicia', $copia->banco);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $copia->amount, self::DELTA);
        $this->assertEquals('emitido.disponibles_para_cobrar', $this->solapa_de($copia->id));

        // --- El gasto: su desglose, sin caja -----------------------------------------------------
        $gasto->load('current_acount_payment_methods');

        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $gasto->amount, self::DELTA);
        $this->assertCount(1, $gasto->current_acount_payment_methods);
        $this->assertEquals($this->metodo_cheque->id, $gasto->current_acount_payment_methods[0]->id);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $gasto->current_acount_payment_methods[0]->pivot->amount, self::DELTA);

        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Un endoso no mueve plata de ninguna caja.');
    }

    /**
     * Un cheque NUEVO cargado en un gasto: emitido, colgado del gasto por `expense_id` y con
     * `current_acount_id` en null.
     *
     * @test
     */
    public function un_cheque_nuevo_en_un_gasto_queda_colgado_del_gasto_y_no_de_una_cuenta_corriente()
    {
        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();

        $fila = $this->fila_de_gasto([
            'numero'        => '4004',
            'banco'         => 'Banco Macro',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(20)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $response->assertStatus(201);

        $gasto_id = (int) $response->json('model.id');
        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $this->assertEquals($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count());

        $emitido = Cheque::where('expense_id', $gasto_id)->first();

        $this->assertNotNull($emitido, 'El cheque del gasto tenía que quedar con expense_id.');
        $this->assertEquals('emitido', $emitido->tipo);
        $this->assertNull($emitido->current_acount_id);
        $this->assertNull($emitido->provider_id);
        $this->assertNull($emitido->client_id);
        $this->assertNull($emitido->endosado_desde_cheque_id);
        $this->assertEquals('4004', $emitido->numero);
        $this->assertEquals('emitido.pendientes', $this->solapa_de($emitido->id));
    }
}
