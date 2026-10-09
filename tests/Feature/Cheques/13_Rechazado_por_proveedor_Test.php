<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\ChequeController;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\Provider;
use Carbon\Carbon;

/**
 * Misión cheques-emitidos-rechazo-proveedor (9/10/2026) — `PUT cheque/rechazar-por-proveedor`.
 *
 * El defecto: en Cheques → Emitido el botón "Rechazado por proveedor" no hacía nada (no tenía
 * `@click`) y no había endpoint detrás. Decisiones de Lucas (9/10/2026):
 *
 * 1. Al marcar un emitido como rechazado por el proveedor se le carga sola al proveedor una nota de
 *    débito por el monto del cheque, en la cuenta corriente del pago: la deuda vuelve. Si el cheque
 *    salió de un gasto, solo se marca (un gasto no tiene cuenta corriente).
 * 2. La copia emitida de un endoso se trata igual que un cheque propio: nota al proveedor; el
 *    recibido de origen sigue endosado y la cuenta del cliente no se toca.
 *
 * Por qué la nota es el ÚNICO camino: desde la 4.3.9 un pago cuyo cheque figura rechazado no se
 * puede eliminar (lo fija el último test de este archivo).
 *
 * Todo pasa por los endpoints reales (`POST current-acount/pago`, `POST expense`,
 * `PUT cheque/pagar`, `DELETE current-acount/provider/{id}`); lo único que se arma a mano es lo que
 * ningún endpoint deja (un cheque de otro dueño, un pago borrado sin sus cheques) y la carrera del
 * doble clic, que se reproduce llamando al helper con el cheque leído antes de que otro lo marque.
 *
 * @group cheques
 */
class Rechazado_por_proveedor_Test extends ChequesTestCase
{
    /**
     * El caso de la pantalla: un pago a proveedor con un cheque nuevo, y el proveedor no lo pudo
     * cobrar. El cheque queda rechazado, la nota de débito devuelve la deuda y el listado lo pone en
     * Emitido → Rechazados.
     *
     * @test
     */
    public function el_cheque_nuevo_de_un_pago_rechazado_le_devuelve_la_deuda_al_proveedor()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor que no cobró ' . uniqid(), self::DEUDA_PROVEEDOR);

        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8001');

        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);

        $movimientos_antes = $this->movimientos_de($cuenta);
        $auditoria_antes = (int) AuditLog::max('id');

        $response = $this->rechazar_por_proveedor($emitido->id);

        $response->assertStatus(200);

        $nota_id = (int) $response->json('nota_debito.id');
        $this->cobros_cc_creados_por_escenarios[] = $nota_id;

        // --- La marca queda en audit_logs (se escribe por modelo, no por builder) -------------------
        $this->assertTrue(
            AuditLog::where('id', '>', $auditoria_antes)
                    ->where('auditable_type', Cheque::class)
                    ->where('auditable_id', $emitido->id)
                    ->where('event', 'updated')
                    ->where('new_values', 'like', '%rechazado%')
                    ->exists(),
            'Marcar el cheque como rechazado tenía que dejar su fila en audit_logs.'
        );

        // --- La respuesta: el cheque recargado con sus relaciones, la nota y el mensaje --------------
        $this->assertEquals($emitido->id, (int) $response->json('model.id'));
        $this->assertEquals('rechazado', $response->json('model.estado_manual'));
        $this->assertEquals($proveedor->id, (int) $response->json('model.provider.id'), 'El modelo vuelve con withAll.');
        $this->assertEquals($this->dueno->id, (int) $response->json('model.rechazado_por.id'));
        $this->assertEquals($proveedor->id, (int) $response->json('nota_debito.provider_id'));
        $this->assertEquals(
            'Cheque N° 8001 marcado como rechazado. Se le cargó a ' . $proveedor->name . ' una nota de débito por $45.000.',
            $response->json('mensaje')
        );

        // --- El cheque ------------------------------------------------------------------------------
        $emitido = $emitido->fresh();

        $this->assertEquals('rechazado', $emitido->estado_manual);
        $this->assertNotNull($emitido->rechazado_en);
        $this->assertEquals($this->dueno->id, (int) $emitido->rechazado_por_id);

        // --- Una sola fila nueva en la cuenta: la nota de débito, con los campos de notaDebito() ----
        $nuevos = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotIn('id', $movimientos_antes)->get();

        $this->assertCount(1, $nuevos);

        $nota = $nuevos->first();

        $this->assertEquals($nota_id, $nota->id);
        $this->assertEquals('Nota de debito', $nota->detalle);
        $this->assertEquals('Cheque N° 8001 rechazado por el proveedor', $nota->description);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $nota->debe, self::DELTA);
        $this->assertEquals('sin_pagar', $nota->status);
        $this->assertEquals($proveedor->id, (int) $nota->provider_id);
        $this->assertNull($nota->client_id);
        $this->assertEquals($this->dueno->id, (int) $nota->user_id);
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) $nota->saldo, self::DELTA);

        // --- La deuda volvió a la de antes del pago, en la cuenta y en el proveedor ----------------
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) Provider::find($proveedor->id)->saldo_pesos, self::DELTA);

        // --- Y el listado lo pone en Emitido → Rechazados -------------------------------------------
        $this->assertEquals('emitido.rechazados', $this->solapa_de($emitido->id));
    }

    /**
     * Dos clics seguidos: el segundo es un 422 con el estado en que quedó el cheque, y la nota se
     * carga una sola vez.
     *
     * @test
     */
    public function dos_clics_seguidos_cargan_una_sola_nota()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor doble clic ' . uniqid(), self::DEUDA_PROVEEDOR);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8002');

        $movimientos_antes = $this->movimientos_de($cuenta);

        $primero = $this->rechazar_por_proveedor($emitido->id);

        $primero->assertStatus(200);
        $this->cobros_cc_creados_por_escenarios[] = (int) $primero->json('nota_debito.id');

        $segundo = $this->rechazar_por_proveedor($emitido->id);

        $segundo->assertStatus(422);
        $this->assertEquals('El cheque N° 8002 ya figura como rechazado.', $segundo->json('message'));

        $this->assertCount(1, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotIn('id', $movimientos_antes)->get(), 'Dos clics, una sola nota.');
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
    }

    /**
     * La carrera de verdad: el cheque se leyó sin marca y, antes de marcarlo, otro request lo marcó
     * pagado. El UPDATE condicional no marca nada, no se carga ninguna nota y el motivo dice el
     * estado que quedó. Se llama al helper con el cheque leído ANTES (lo que pasa en el request que
     * pierde).
     *
     * @test
     */
    public function si_otro_request_lo_marco_en_el_medio_no_se_carga_ninguna_nota()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor de la carrera ' . uniqid(), self::DEUDA_PROVEEDOR);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8003');

        $leido_antes = Cheque::where('user_id', $this->dueno->id)->where('id', $emitido->id)->first();

        $this->assertNull($leido_antes->estado_manual);

        $this->putJson('api/cheque/pagar', ['cheque_id' => $emitido->id, 'caja_id' => 0])->assertStatus(200);

        $saldo = (float) CreditAccount::find($cuenta->id)->saldo;
        $movimientos_antes = $this->movimientos_de($cuenta);

        $resultado = ChequeHelper::rechazar_por_proveedor($leido_antes, $this->dueno->id, $this->dueno->id);

        $this->assertEquals(['El cheque N° 8003 ya figura como pagado.'], $resultado['problemas']);
        $this->assertNull($resultado['nota_debito']);

        $this->assertCount(0, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotIn('id', $movimientos_antes)->get());
        $this->assertEqualsWithDelta($saldo, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
        $this->assertEquals('cobrado', $emitido->fresh()->estado_manual);
        $this->assertNull($emitido->fresh()->rechazado_en);
    }

    /**
     * Un cheque de un gasto: solo se marca. Ninguna cuenta corriente se mueve.
     *
     * @test
     */
    public function el_cheque_de_un_gasto_solo_se_marca()
    {
        $fila = $this->fila_de_gasto([
            'numero'        => '8004',
            'banco'         => 'Banco Ciudad',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(20)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $response->assertStatus(201);

        $gasto_id = (int) $response->json('model.id');
        $this->gastos_creados_por_escenarios[] = $gasto_id;

        $emitido = Cheque::where('expense_id', $gasto_id)->where('user_id', $this->dueno->id)->first();

        $this->assertNotNull($emitido);
        $this->assertEquals('emitido', $emitido->tipo);

        $max_movimiento = (int) CurrentAcount::max('id');

        $response = $this->rechazar_por_proveedor($emitido->id);

        $response->assertStatus(200);
        $this->assertNull($response->json('nota_debito'));
        $this->assertEquals(
            'Cheque N° 8004 marcado como rechazado. Salió de un gasto: no se movió ninguna cuenta corriente.',
            $response->json('mensaje')
        );

        $this->assertEquals($max_movimiento, (int) CurrentAcount::max('id'), 'Un cheque de un gasto no carga ninguna fila en current_acounts.');
        $this->assertEquals('rechazado', $emitido->fresh()->estado_manual);
        $this->assertNotNull(Expense::find($gasto_id), 'El gasto no se toca.');
        $this->assertEquals('emitido.rechazados', $this->solapa_de($emitido->id));
    }

    /**
     * La copia emitida de un endoso a un proveedor se trata igual que un cheque propio: la nota va al
     * proveedor. El recibido de origen sigue endosado (sin marca manual) y la cuenta del cliente que
     * lo entregó no se toca.
     *
     * @test
     */
    public function la_copia_de_un_endoso_le_carga_la_nota_al_proveedor_y_no_toca_al_cliente()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del cheque endosado ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor del endoso rechazado ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '8005']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);

        $saldo_cliente = (float) CreditAccount::find($cuenta_cliente->id)->saldo;
        $movimientos_cliente = $this->movimientos_de($cuenta_cliente);
        $movimientos_proveedor = $this->movimientos_de($cuenta_proveedor);

        $response = $this->rechazar_por_proveedor($copia->id);

        $response->assertStatus(200);
        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('nota_debito.id');

        $this->assertEquals(
            'Cheque N° 8005 marcado como rechazado. Se le cargó a ' . $proveedor->name . ' una nota de débito por $45.000.',
            $response->json('mensaje')
        );

        // --- La nota, al proveedor, y su deuda vuelve -----------------------------------------------
        $nuevos = CurrentAcount::where('credit_account_id', $cuenta_proveedor->id)->whereNotIn('id', $movimientos_proveedor)->get();

        $this->assertCount(1, $nuevos);
        $this->assertEquals('Nota de debito', $nuevos->first()->detalle);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $nuevos->first()->debe, self::DELTA);
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);

        $this->assertEquals('rechazado', $copia->fresh()->estado_manual);
        $this->assertEquals('emitido.rechazados', $this->solapa_de($copia->id));

        // --- El recibido de origen, intacto: sigue endosado y sin marca -----------------------------
        $recibido = $recibido->fresh();

        $this->assertEquals($proveedor->id, (int) $recibido->endosado_a_provider_id);
        $this->assertNotNull($recibido->fecha_endoso);
        $this->assertNull($recibido->estado_manual);
        $this->assertEquals('recibido.endosados', $this->solapa_de($recibido->id));

        // --- La cuenta del cliente, sin filas nuevas ni cambio de saldo -----------------------------
        $this->assertCount(0, CurrentAcount::where('credit_account_id', $cuenta_cliente->id)->whereNotIn('id', $movimientos_cliente)->get());
        $this->assertEqualsWithDelta($saldo_cliente, (float) CreditAccount::find($cuenta_cliente->id)->saldo, self::DELTA);
    }

    /**
     * Un cheque RECIBIDO no se rechaza por esta puerta: 422 y no se escribe nada.
     *
     * @test
     */
    public function un_recibido_da_422_sin_escribir_nada()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente de un recibido ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '8006']);

        $max_movimiento = (int) CurrentAcount::max('id');

        $response = $this->rechazar_por_proveedor($recibido->id);

        $response->assertStatus(422);
        $this->assertEquals('Solo un cheque emitido se marca como rechazado por el proveedor.', $response->json('message'));

        $this->assertNull($recibido->fresh()->estado_manual);
        $this->assertNull($recibido->fresh()->rechazado_en);
        $this->assertEquals($max_movimiento, (int) CurrentAcount::max('id'));
    }

    /**
     * Un emitido que ya figura como pagado: 422 con el estado, sin nota y sin tocar el cheque.
     *
     * @test
     */
    public function un_emitido_ya_pagado_da_422_sin_escribir_nada()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor que ya cobró ' . uniqid(), self::DEUDA_PROVEEDOR);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8007');

        $this->putJson('api/cheque/pagar', ['cheque_id' => $emitido->id, 'caja_id' => 0])->assertStatus(200);

        $saldo = (float) CreditAccount::find($cuenta->id)->saldo;
        $max_movimiento = (int) CurrentAcount::max('id');

        $response = $this->rechazar_por_proveedor($emitido->id);

        $response->assertStatus(422);
        $this->assertEquals('El cheque N° 8007 ya figura como pagado.', $response->json('message'));

        $this->assertEquals('cobrado', $emitido->fresh()->estado_manual);
        $this->assertNull($emitido->fresh()->rechazado_en);
        $this->assertEquals($max_movimiento, (int) CurrentAcount::max('id'));
        $this->assertEqualsWithDelta($saldo, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
    }

    /**
     * Un cheque de otro dueño, uno que no existe y algo que no es un id: el mismo 422 que el resto
     * del módulo (MENSAJE_CHEQUE_AJENO), y el cheque ajeno queda como estaba.
     *
     * @test
     */
    public function un_cheque_ajeno_inexistente_o_que_no_es_un_id_da_422()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor del ajeno ' . uniqid());

        $ajeno = $this->cheque_a_mano([
            'tipo'        => 'emitido',
            'user_id'     => $this->dueno->id + 100000,
            'provider_id' => $proveedor->id,
        ]);

        $max_movimiento = (int) CurrentAcount::max('id');

        foreach ([$ajeno->id, (int) Cheque::max('id') + 1000, $ajeno->id . 'abc', null] as $cheque_id) {

            $response = $this->rechazar_por_proveedor($cheque_id);

            $response->assertStatus(422);
            $this->assertEquals(ChequeController::MENSAJE_CHEQUE_AJENO, $response->json('message'), 'cheque_id: ' . var_export($cheque_id, true));
        }

        $this->assertNull($ajeno->fresh()->estado_manual);
        $this->assertNull($ajeno->fresh()->rechazado_en);
        $this->assertEquals($max_movimiento, (int) CurrentAcount::max('id'));
    }

    /**
     * Un cheque cuyo pago ya no existe (lo borró el borrado de antes de la 4.3.9, que no tocaba los
     * cheques): se marca, sin nota, y el mensaje lo dice.
     *
     * @test
     */
    public function si_el_pago_ya_no_existe_solo_se_marca()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor del pago borrado ' . uniqid(), self::DEUDA_PROVEEDOR);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8009');

        // Lo que dejaba el borrado de antes, a mano: el pago se va y su cheque queda huérfano.
        CurrentAcount::find($pago_id)->current_acount_payment_methods()->detach();
        CurrentAcount::where('id', $pago_id)->delete();

        $this->assertNull(CurrentAcount::find($pago_id));

        $max_movimiento = (int) CurrentAcount::max('id');

        $response = $this->rechazar_por_proveedor($emitido->id);

        $response->assertStatus(200);
        $this->assertNull($response->json('nota_debito'));
        $this->assertEquals(
            'Cheque N° 8009 marcado como rechazado. El pago de este cheque ya no existe, así que no se cargó nota de débito.',
            $response->json('mensaje')
        );

        $this->assertEquals('rechazado', $emitido->fresh()->estado_manual);
        $this->assertEquals($max_movimiento, (int) CurrentAcount::max('id'), 'Sin pago no hay a qué cuenta cargarle la nota.');
    }

    /**
     * Después de rechazar, el pago sigue sin poder eliminarse (la guarda de la 4.3.9): la nota de
     * débito es el único camino para que la deuda vuelva, y queda en su lugar.
     *
     * @test
     */
    public function despues_de_rechazar_el_pago_sigue_sin_poder_eliminarse()
    {
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor del pago frenado ' . uniqid(), self::DEUDA_PROVEEDOR);

        list($pago_id, $emitido) = $this->pagar_con_cheque_nuevo($proveedor, $cuenta, '8010');

        $response = $this->rechazar_por_proveedor($emitido->id);

        $response->assertStatus(200);

        $nota_id = (int) $response->json('nota_debito.id');
        $this->cobros_cc_creados_por_escenarios[] = $nota_id;

        $borrado = $this->deleteJson('api/current-acount/provider/' . $pago_id . '?compensar_caja=1');

        $borrado->assertStatus(422);
        $this->assertEquals('No se puede eliminar este pago: el cheque N° 8010 que se entregó en él ya figura como rechazado.', $borrado->json('message'));

        $this->assertNotNull(CurrentAcount::find($pago_id));
        $this->assertNotNull(CurrentAcount::find($nota_id));
        $this->assertNotNull(Cheque::find($emitido->id));
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA);
    }

    /**
     * `PUT cheque/rechazar-por-proveedor`, como lo manda RechazadoPorProveedor.vue.
     *
     * @param mixed $cheque_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function rechazar_por_proveedor($cheque_id)
    {
        return $this->putJson('api/cheque/rechazar-por-proveedor', ['cheque_id' => $cheque_id]);
    }

    /**
     * Un pago al proveedor con un cheque NUEVO por `POST current-acount/pago`, como la pantalla.
     *
     * @param Provider $proveedor
     * @param CreditAccount $cuenta
     * @param string $numero
     * @return array{0: int, 1: Cheque}  El id del pago y el cheque emitido.
     */
    protected function pagar_con_cheque_nuevo($proveedor, $cuenta, $numero)
    {
        $fila = $this->fila_de_pago([
            'numero'        => $numero,
            'banco'         => 'Banco Provincia',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, [$fila]));

        $response->assertStatus(201);

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $emitido = Cheque::where('current_acount_id', $pago_id)->where('user_id', $this->dueno->id)->first();

        $this->assertNotNull($emitido, 'El pago con una fila de tipo cheque tenía que dejar un cheque emitido.');
        $this->assertEquals('emitido', $emitido->tipo);

        return [$pago_id, $emitido];
    }

    /**
     * Endosa un recibido en un pago a proveedor por `POST current-acount/pago`, como la pantalla.
     *
     * @param Cheque $recibido
     * @param Provider $proveedor
     * @param CreditAccount $cuenta_proveedor
     * @return array{0: CurrentAcount, 1: Cheque}  El pago y la copia emitida.
     */
    protected function endosar_en_pago(Cheque $recibido, $proveedor, $cuenta_proveedor)
    {
        $fila = $this->fila_de_pago($this->claves_de_endoso($recibido->fresh()));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago = CurrentAcount::find((int) $response->json('current_acount.id'));
        $this->cobros_cc_creados_por_escenarios[] = $pago->id;

        $copia = Cheque::where('endosado_desde_cheque_id', $recibido->id)
                        ->where('current_acount_id', $pago->id)
                        ->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida colgada del pago.');

        return [$pago, $copia];
    }

    /**
     * Los ids de los movimientos que hoy tiene una cuenta corriente.
     *
     * @param CreditAccount $cuenta
     * @return array<int, int>
     */
    protected function movimientos_de($cuenta)
    {
        return CurrentAcount::where('credit_account_id', $cuenta->id)->pluck('id')->all();
    }
}
