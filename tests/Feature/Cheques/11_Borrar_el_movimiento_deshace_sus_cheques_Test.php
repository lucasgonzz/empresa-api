<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use Carbon\Carbon;

/**
 * Misión cheque-endoso-deshacer-al-borrar-pago (9/10/2026) — borrar el movimiento que originó
 * cheques deshace esos cheques.
 *
 * El defecto, medido en demo2 4.3.8 filmando el T5.22: borrar un pago a proveedor que había
 * endosado un cheque recibido (`DELETE current-acount/provider/{id}?compensar_caja=1`) solo soltaba
 * los métodos de pago y borraba el movimiento. El recibido seguía endosado —fuera de la cartera, sin
 * poder volver a endosarse— y la copia emitida seguía pendiente con el proveedor, sin pago.
 *
 * La clase, en sus cinco puertas: el pago a proveedor con endoso (desde la pantalla y desde el botón
 * Endosar del módulo), el pago a proveedor con cheque nuevo, el cobro a un cliente con cheque, el
 * gasto con endoso y el gasto con cheque nuevo; más el borrado a mano de la copia de un endoso. Y la
 * decisión de Lucas (9/10/2026): si un cheque del movimiento ya tuvo vida propia (se cobró, se pagó,
 * se rechazó o se endosó), el borrado se frena con 422 y no se toca NADA.
 *
 * Los borrados son los de la SPA: `DELETE current-acount/<client|provider>/{id}` y
 * `DELETE expense/{id}` con `compensar_caja=1` (el checkbox del confirm, tildado por defecto), y
 * `DELETE cheque/{id}` del módulo de cheques.
 *
 * @group cheques
 */
class Borrar_el_movimiento_deshace_sus_cheques_Test extends ChequesTestCase
{
    /**
     * El defecto tal cual se midió: el recibido vuelve a la cartera, la copia emitida desaparece, la
     * cuenta del proveedor vuelve a la deuda de antes, ninguna caja se mueve, y el mismo cheque se
     * puede volver a endosar.
     *
     * @test
     */
    public function el_pago_a_proveedor_que_endoso_un_cheque_al_borrarse_lo_devuelve_a_la_cartera()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del cheque ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor endosatario ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7001']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->assertEquals('recibido.endosados', $this->solapa_de($recibido->id));
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);

        $movimientos_antes = $this->max_id_movimiento_caja();

        $response = $this->borrar_movimiento('provider', $pago->id);

        $response->assertStatus(200);
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        // --- El pago y la copia ya no están -----------------------------------------------------
        $this->assertNull(CurrentAcount::find($pago->id));
        $this->assertNull(Cheque::find($copia->id), 'La copia emitida tenía que borrarse con el pago que la respaldaba.');
        $this->assertCount(0, $this->copias_de($recibido));
        $this->assertNull($this->solapa_de($copia->id));

        // --- El recibido volvió a la cartera ----------------------------------------------------
        $recibido = $recibido->fresh();

        $this->assertNull($recibido->endosado_a_provider_id);
        $this->assertNull($recibido->endosado_en_expense_id);
        $this->assertNull($recibido->fecha_endoso);
        $this->assertNull($recibido->estado_manual);
        $this->assertEquals('recibido.pendientes', $this->solapa_de($recibido->id));
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());

        // --- La cuenta del proveedor, como antes del pago; ninguna caja se movió -----------------
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Deshacer un endoso no mueve plata de ninguna caja.');

        // --- Y se puede volver a endosar --------------------------------------------------------
        list($pago_nuevo, $copia_nueva) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->assertEquals($recibido->id, $copia_nueva->endosado_desde_cheque_id);
        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);
    }

    /**
     * El botón Endosar del módulo arma el pago por el mismo alta: borrar ese pago también deshace.
     *
     * @test
     */
    public function el_pago_que_dejo_el_boton_endosar_del_modulo_tambien_se_deshace()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del botón ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor del botón ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $response->assertStatus(200);

        $copia = $this->copias_de($recibido)->first();

        $this->assertNotNull($copia);

        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $this->borrar_movimiento('provider', $copia->current_acount_id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($copia->current_acount_id));
        $this->assertNull(Cheque::find($copia->id));
        $this->assertNull($recibido->fresh()->endosado_a_provider_id);
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
    }

    /**
     * El cheque nuevo de un pago a proveedor se va con el pago. Un cheque de OTRO dueño que casualmente
     * apunta al mismo movimiento (base compartida) no se toca.
     *
     * @test
     */
    public function el_pago_a_proveedor_con_un_cheque_nuevo_al_borrarse_borra_ese_cheque()
    {
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor cheque nuevo ' . uniqid(), self::DEUDA_PROVEEDOR);

        $fila = $this->fila_de_pago([
            'numero'        => '7002',
            'banco'         => 'Banco Provincia',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $emitido = Cheque::where('current_acount_id', $pago_id)->where('user_id', $this->dueno->id)->first();

        $this->assertNotNull($emitido);
        $this->assertEquals('emitido.pendientes', $this->solapa_de($emitido->id));

        $ajeno = $this->cheque_a_mano([
            'tipo'              => 'emitido',
            'user_id'           => $this->dueno->id + 100000,
            'provider_id'       => $proveedor->id,
            'current_acount_id' => $pago_id,
        ]);

        $this->borrar_movimiento('provider', $pago_id)->assertStatus(200);

        $this->assertNull(Cheque::find($emitido->id), 'El cheque emitido en el pago tenía que borrarse con el pago.');
        $this->assertNotNull(Cheque::find($ajeno->id), 'Un cheque de otro dueño no se toca nunca.');
    }

    /**
     * El cheque de un cobro a un cliente sale de la cartera con el cobro: si no, se puede endosar o
     * cobrar un cheque que nadie entregó.
     *
     * @test
     */
    public function el_cobro_de_un_cliente_con_cheque_al_borrarse_saca_el_cheque_de_la_cartera()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente que paga con cheque ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7003']);

        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());

        $this->borrar_movimiento('client', $recibido->current_acount_id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($recibido->current_acount_id));
        $this->assertNull(Cheque::find($recibido->id));
        $this->assertNotContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertNull($this->solapa_de($recibido->id));
    }

    /**
     * crear_cheque() le graba el id de la VENTA en `current_acount_id` al cheque de una venta de
     * contado (hallazgo abierto). Este caso: un cobro SIN fila de cheque no tiene cheques, aunque haya
     * uno del mismo cliente apuntando a su id. (El cobro con fila de cheque y una venta real con el
     * mismo id lo cubre el test siguiente.)
     *
     * @test
     */
    public function el_cobro_sin_fila_de_cheque_no_toca_un_cheque_de_venta_que_comparte_el_id()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente en efectivo ' . uniqid());

        $efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->first();

        $this->assertNotNull($efectivo, 'El catálogo de métodos de pago no tiene Efectivo.');

        $fila = $this->fila_de_pago(['current_acount_payment_method_id' => $efectivo->id, 'amount' => 1000]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('client', $cliente->id, $cuenta_cliente, [$fila]));

        $response->assertStatus(201);

        $cobro_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $cobro_id;

        // Lo que deja crear_cheque() para una venta de contado del mismo cliente cuyo id coincide.
        $de_la_venta = $this->cheque_a_mano([
            'tipo'              => 'recibido',
            'client_id'         => $cliente->id,
            'current_acount_id' => $cobro_id,
        ]);

        $this->borrar_movimiento('client', $cobro_id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($cobro_id));
        $this->assertNotNull(Cheque::find($de_la_venta->id), 'El cheque de otra operación que comparte el id no es del cobro.');
    }

    /**
     * El cobro CON fila de cheque y una venta del mismo cliente, con el MISMO id y pagada con cheque:
     * no hay forma de saber qué cheque es de quién, así que no se toca ninguno (como antes del
     * 9/10/2026). La venta se inserta a mano con el id del cobro: es la coincidencia de ids que
     * ningún endpoint fuerza.
     *
     * @test
     */
    public function el_cobro_que_comparte_el_id_con_una_venta_pagada_con_cheque_no_toca_cheques()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente id compartido ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7021']);

        $cobro_id = (int) $recibido->current_acount_id;

        if (\App\Models\Sale::withTrashed()->where('id', $cobro_id)->exists()) {
            $this->markTestSkipped('Ya hay una venta con el id ' . $cobro_id . ' en la base de testing.');
        }

        \Illuminate\Support\Facades\DB::table('sales')->insert([
            'id'         => $cobro_id,
            'num'        => 990000 + ($cobro_id % 1000),
            'user_id'    => $this->dueno->id,
            'client_id'  => $cliente->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('current_acount_payment_method_sale')->insert([
            'sale_id'                          => $cobro_id,
            'current_acount_payment_method_id' => $this->metodo_cheque->id,
            'amount'                           => self::MONTO_CHEQUE,
        ]);

        $de_la_venta = $this->cheque_a_mano([
            'tipo'              => 'recibido',
            'client_id'         => $cliente->id,
            'current_acount_id' => $cobro_id,
        ]);

        try {
            $this->borrar_movimiento('client', $cobro_id)->assertStatus(200);

            $this->assertNull(CurrentAcount::find($cobro_id));
            $this->assertNotNull(Cheque::find($de_la_venta->id), 'El cheque de la venta no se toca.');
            $this->assertNotNull(Cheque::find($recibido->id), 'Ambiguo: tampoco se toca el del cobro.');
        } finally {
            \Illuminate\Support\Facades\DB::table('current_acount_payment_method_sale')->where('sale_id', $cobro_id)->delete();
            \Illuminate\Support\Facades\DB::table('sales')->where('id', $cobro_id)->delete();
        }
    }

    /**
     * Un endoso viejo sin vínculo y un pago POSTERIOR al mismo proveedor con un cheque nuevo de igual
     * número y monto: borrar ese pago se lleva su cheque nuevo, y el recibido viejo sigue endosado
     * (su copia sigue con el proveedor). Un cheque nuevo no trae `endosado_desde_client_id`.
     *
     * @test
     */
    public function un_cheque_nuevo_de_igual_numero_no_rescata_un_endoso_viejo()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso viejo y nuevo ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor endoso viejo y nuevo ' . uniqid(), self::DEUDA_PROVEEDOR * 2);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7022']);

        list($pago_viejo, $copia_vieja) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        Cheque::where('id', $copia_vieja->id)->update(['endosado_desde_cheque_id' => null]);

        $fila = $this->fila_de_pago([
            'numero'        => '7022',
            'banco'         => 'Banco Nación',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago_nuevo_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_nuevo_id;

        $nuevo = Cheque::where('current_acount_id', $pago_nuevo_id)->where('user_id', $this->dueno->id)->first();

        $this->assertNull($nuevo->endosado_desde_client_id);

        $this->borrar_movimiento('provider', $pago_nuevo_id)->assertStatus(200);

        $this->assertNull(Cheque::find($nuevo->id));
        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id, 'El recibido viejo sigue en manos del proveedor.');
        $this->assertNotNull(Cheque::find($copia_vieja->id));

        // Y a mano tampoco: borrar un cheque nuevo con ese número no devuelve el recibido.
        $otro_pago = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));
        $otro_pago->assertStatus(201);
        $this->cobros_cc_creados_por_escenarios[] = (int) $otro_pago->json('current_acount.id');
        $otro = Cheque::where('current_acount_id', (int) $otro_pago->json('current_acount.id'))->where('user_id', $this->dueno->id)->first();

        $this->deleteJson('api/cheque/' . $otro->id)->assertStatus(200);

        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);
    }

    /**
     * El gasto que endosó un cheque: al borrarlo el recibido vuelve a la cartera y la copia se va.
     *
     * @test
     */
    public function el_gasto_que_endoso_un_cheque_al_borrarse_lo_devuelve_a_la_cartera()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del cheque gasto ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7004']);

        list($gasto, $copia) = $this->endosar_en_gasto($recibido);

        $this->assertEquals($gasto->id, $recibido->fresh()->endosado_en_expense_id);
        $this->assertEquals('recibido.endosados', $this->solapa_de($recibido->id));

        $movimientos_antes = $this->max_id_movimiento_caja();

        $this->deleteJson('api/expense/' . $gasto->id . '?compensar_caja=1')->assertStatus(200);

        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        $this->assertNull(Expense::find($gasto->id));
        $this->assertNull(Cheque::find($copia->id));

        $recibido = $recibido->fresh();

        $this->assertNull($recibido->endosado_en_expense_id);
        $this->assertNull($recibido->endosado_a_provider_id);
        $this->assertNull($recibido->fecha_endoso);
        $this->assertEquals('recibido.pendientes', $this->solapa_de($recibido->id));
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count());
    }

    /**
     * El cheque nuevo de un gasto se va con el gasto.
     *
     * @test
     */
    public function el_gasto_con_un_cheque_nuevo_al_borrarse_borra_ese_cheque()
    {
        $fila = $this->fila_de_gasto([
            'numero'        => '7005',
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

        $this->deleteJson('api/expense/' . $gasto_id . '?compensar_caja=1')->assertStatus(200);

        $this->assertNull(Expense::find($gasto_id));
        $this->assertNull(Cheque::find($emitido->id));
    }

    /**
     * El cobro cuyo cheque ya se endosó a un proveedor no se borra: el cheque está en manos del
     * proveedor. 422 con el motivo y NADA cambia.
     *
     * @test
     */
    public function el_cobro_cuyo_cheque_ya_se_endoso_no_se_borra()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endosado ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor Que Lo Tiene ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7006']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $saldo_cliente = (float) CreditAccount::find($cuenta_cliente->id)->saldo;

        $response = $this->borrar_movimiento('client', $recibido->current_acount_id);

        $response->assertStatus(422);

        $mensaje = (string) $response->json('message');

        $this->assertStringContainsString('No se puede eliminar este cobro', $mensaje);
        $this->assertStringContainsString('7006', $mensaje);
        $this->assertStringContainsString($proveedor->name, $mensaje);
        $this->assertStringContainsString('eliminá primero ese pago', $mensaje);

        // --- Nada cambió ------------------------------------------------------------------------
        $cobro = CurrentAcount::find($recibido->current_acount_id);

        $this->assertNotNull($cobro);
        $this->assertCount(1, $cobro->current_acount_payment_methods()->get(), 'El cobro frenado no puede perder su fila de método de pago.');
        $this->assertEqualsWithDelta($saldo_cliente, (float) CreditAccount::find($cuenta_cliente->id)->saldo, self::DELTA);
        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);
        $this->assertNotNull(Cheque::find($copia->id));
        $this->assertNotNull(CurrentAcount::find($pago->id));
    }

    /**
     * El cobro cuyo cheque ya se cobró (o se rechazó) no se borra.
     *
     * @test
     */
    public function el_cobro_cuyo_cheque_ya_se_cobro_o_se_rechazo_no_se_borra()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente cobrado ' . uniqid());

        $cobrado = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7007']);

        $this->putJson('api/cheque/cobrar', ['cheque_id' => $cobrado->id, 'caja_id' => 0])->assertStatus(200);

        $response = $this->borrar_movimiento('client', $cobrado->current_acount_id);

        $response->assertStatus(422);
        $this->assertStringContainsString('el cheque N° 7007 que entró con él ya figura como cobrado', (string) $response->json('message'));
        $this->assertNotNull(CurrentAcount::find($cobrado->current_acount_id));
        $this->assertEquals('cobrado', Cheque::find($cobrado->id)->estado_manual);

        $rechazado = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7008']);

        $this->putJson('api/cheque/rechazar', ['cheque_id' => $rechazado->id, 'notas' => 'Sin fondos'])->assertStatus(200);

        $response = $this->borrar_movimiento('client', $rechazado->current_acount_id);

        $response->assertStatus(422);
        $this->assertStringContainsString('el cheque N° 7008 que entró con él ya figura como rechazado', (string) $response->json('message'));
        $this->assertNotNull(CurrentAcount::find($rechazado->current_acount_id));
        $this->assertNotNull(Cheque::find($rechazado->id));
    }

    /**
     * El pago cuya copia endosada ya se marcó pagada no se borra, y no toca nada: ni el pago, ni la
     * cuenta del proveedor, ni el recibido, ni la copia.
     *
     * @test
     */
    public function el_pago_cuya_copia_ya_se_pago_no_se_borra_y_no_toca_nada()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente de la copia pagada ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor que cobró ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7009']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->putJson('api/cheque/pagar', ['cheque_id' => $copia->id, 'caja_id' => 0])->assertStatus(200);

        $saldo = (float) CreditAccount::find($cuenta_proveedor->id)->saldo;

        $response = $this->borrar_movimiento('provider', $pago->id);

        $response->assertStatus(422);
        $this->assertEquals('No se puede eliminar este pago: el cheque N° 7009 que se entregó en él ya figura como pagado.', $response->json('message'));

        $this->assertNotNull(CurrentAcount::find($pago->id));
        $this->assertCount(1, CurrentAcount::find($pago->id)->current_acount_payment_methods()->get());
        $this->assertEqualsWithDelta($saldo, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);
        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);
        $this->assertNotNull($recibido->fresh()->fecha_endoso);
        $this->assertEquals('cobrado', Cheque::find($copia->id)->estado_manual);
    }

    /**
     * El gasto cuya copia endosada fue rechazada no se borra.
     *
     * @test
     */
    public function el_gasto_cuya_copia_fue_rechazada_no_se_borra()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente de la copia rechazada ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7010']);

        list($gasto, $copia) = $this->endosar_en_gasto($recibido);

        $this->putJson('api/cheque/rechazar', ['cheque_id' => $copia->id, 'notas' => 'Sin fondos'])->assertStatus(200);

        $response = $this->deleteJson('api/expense/' . $gasto->id . '?compensar_caja=1');

        $response->assertStatus(422);
        $this->assertEquals('No se puede eliminar este gasto: el cheque N° 7010 que se entregó en él ya figura como rechazado.', $response->json('message'));

        $this->assertNotNull(Expense::find($gasto->id));
        $this->assertCount(1, Expense::find($gasto->id)->current_acount_payment_methods()->get());
        $this->assertEquals($gasto->id, $recibido->fresh()->endosado_en_expense_id);
        $this->assertEquals('rechazado', Cheque::find($copia->id)->estado_manual);
    }

    /**
     * Borrar a mano la copia pendiente de un endoso (Tesorería → Cheques) devuelve el recibido a la
     * cartera, en las dos formas de endoso. El pago sigue: queda como hoy con cualquier cheque
     * borrado, y después se puede borrar sin cheques que deshacer.
     *
     * @test
     */
    public function borrar_a_mano_la_copia_de_un_endoso_devuelve_el_recibido_a_la_cartera()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente de la copia borrada ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor de la copia borrada ' . uniqid(), self::DEUDA_PROVEEDOR);

        // --- Endoso a un proveedor --------------------------------------------------------------
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7011']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->deleteJson('api/cheque/' . $copia->id)->assertStatus(200);

        $this->assertNull(Cheque::find($copia->id));
        $this->assertNull($recibido->fresh()->endosado_a_provider_id);
        $this->assertNull($recibido->fresh()->fecha_endoso);
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertNotNull(CurrentAcount::find($pago->id), 'Borrar el cheque no borra el pago.');

        $this->borrar_movimiento('provider', $pago->id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($pago->id));
        $this->assertNotNull(Cheque::find($recibido->id), 'El recibido ya estaba en la cartera: borrar el pago no lo toca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());

        // --- Endoso en un gasto -----------------------------------------------------------------
        $otro = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7012']);

        list($gasto, $copia_del_gasto) = $this->endosar_en_gasto($otro);

        $this->deleteJson('api/cheque/' . $copia_del_gasto->id)->assertStatus(200);

        $this->assertNull(Cheque::find($copia_del_gasto->id));
        $this->assertNull($otro->fresh()->endosado_en_expense_id);
        $this->assertContains($otro->id, $this->ids_disponibles_para_endosar());
        $this->assertNotNull(Expense::find($gasto->id));
    }

    /**
     * Una copia que ya se pagó: ese endoso sí pasó y terminó. Borrar el registro de la copia no
     * devuelve el recibido a la cartera.
     *
     * @test
     */
    public function borrar_a_mano_una_copia_ya_pagada_no_devuelve_el_recibido()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente de la copia pagada borrada ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor de la copia pagada borrada ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7013']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $this->putJson('api/cheque/pagar', ['cheque_id' => $copia->id, 'caja_id' => 0])->assertStatus(200);

        $this->deleteJson('api/cheque/' . $copia->id)->assertStatus(200);

        $this->assertNull(Cheque::find($copia->id));
        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);
        $this->assertNotContains($recibido->id, $this->ids_disponibles_para_endosar());
    }

    /**
     * Un pago con DOS filas de cheque —un endoso y un cheque nuevo—: al borrarlo, el recibido vuelve a
     * la cartera y se van las dos emitidas.
     *
     * @test
     */
    public function el_pago_con_un_endoso_y_un_cheque_nuevo_deshace_los_dos()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente dos filas ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor dos filas ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7014']);

        $filas = [
            $this->fila_de_pago($this->claves_de_endoso($recibido->fresh())),
            $this->fila_de_pago([
                'numero'        => '7015',
                'banco'         => 'Banco Provincia',
                'amount'        => 10000,
                'fecha_emision' => Carbon::today()->format('Y-m-d'),
                'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
            ]),
        ];

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, $filas));

        $response->assertStatus(201);

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $emitidos = Cheque::where('current_acount_id', $pago_id)->where('user_id', $this->dueno->id)->pluck('id')->all();

        $this->assertCount(2, $emitidos);

        $this->borrar_movimiento('provider', $pago_id)->assertStatus(200);

        $this->assertEquals(0, Cheque::whereIn('id', $emitidos)->count());
        $this->assertNull($recibido->fresh()->endosado_a_provider_id);
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
    }

    /**
     * Un endoso de ANTES del 21/9/2026: la copia nació sin `endosado_desde_cheque_id` (el botón viejo
     * no lo escribía). El recibido se encuentra por proveedor, número y monto y vuelve a la cartera.
     *
     * @test
     */
    public function un_endoso_viejo_sin_vinculo_tambien_se_deshace()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso viejo ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor endoso viejo ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7016']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        // El estado que dejaba el endoso viejo, y que ningún endpoint de hoy deja: la copia sin vínculo.
        Cheque::where('id', $copia->id)->update(['endosado_desde_cheque_id' => null]);

        $this->borrar_movimiento('provider', $pago->id)->assertStatus(200);

        $this->assertNull(Cheque::find($copia->id));
        $this->assertNull($recibido->fresh()->endosado_a_provider_id);
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
    }

    /**
     * Un recibido que quedó endosado SIN copia (su pago y su copia se borraron antes de esta misión,
     * o es un dato sembrado) no frena el borrado de su cobro: si frenara, el aviso mandaría a borrar
     * un pago que ya no existe y el cobro no se podría borrar nunca.
     *
     * @test
     */
    public function el_cobro_cuyo_cheque_quedo_endosado_sin_copia_se_borra()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso huérfano ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor endoso huérfano ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7017']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        // Lo que dejaba el borrado de antes, a mano: el pago y la copia se van, el recibido queda marcado.
        Cheque::where('id', $copia->id)->delete();
        $pago->current_acount_payment_methods()->detach();
        CurrentAcount::where('id', $pago->id)->delete();

        $this->assertEquals($proveedor->id, $recibido->fresh()->endosado_a_provider_id);

        $this->borrar_movimiento('client', $recibido->current_acount_id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($recibido->current_acount_id));
        $this->assertNull(Cheque::find($recibido->id));
    }

    /**
     * La copia sigue viva pero su pago ya no (se borró antes de esta misión): el aviso del cobro manda
     * a borrar la copia, y después de borrarla el cobro se borra.
     *
     * @test
     */
    public function si_la_copia_vive_sin_su_pago_el_aviso_manda_a_borrar_la_copia()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente copia sin pago ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor copia sin pago ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7018']);

        list($pago, $copia) = $this->endosar_en_pago($recibido, $proveedor, $cuenta_proveedor);

        $pago->current_acount_payment_methods()->detach();
        CurrentAcount::where('id', $pago->id)->delete();

        $response = $this->borrar_movimiento('client', $recibido->current_acount_id);

        $response->assertStatus(422);
        $this->assertStringContainsString('eliminá primero su copia en Tesorería → Cheques → Emitidos', (string) $response->json('message'));

        $this->deleteJson('api/cheque/' . $copia->id)->assertStatus(200);

        $this->assertTrue(\App\Http\Controllers\Helpers\ChequeHelper::en_cartera($recibido->fresh()));

        $this->borrar_movimiento('client', $recibido->current_acount_id)->assertStatus(200);

        $this->assertNull(Cheque::find($recibido->id));
    }

    /**
     * El cobro cuyo cheque se endosó en un GASTO: el aviso nombra el gasto.
     *
     * @test
     */
    public function el_cobro_cuyo_cheque_se_endoso_en_un_gasto_no_se_borra()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente endoso en gasto ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7019']);

        list($gasto, $copia) = $this->endosar_en_gasto($recibido);

        $response = $this->borrar_movimiento('client', $recibido->current_acount_id);

        $response->assertStatus(422);
        $this->assertEquals(
            'No se puede eliminar este cobro: el cheque N° 7019 que entró con él ya se endosó en el gasto N° ' . $gasto->num . ' (eliminá primero ese gasto).',
            $response->json('message')
        );
        $this->assertNotNull(CurrentAcount::find($recibido->current_acount_id));
        $this->assertEquals($gasto->id, $recibido->fresh()->endosado_en_expense_id);
        $this->assertNotNull(Cheque::find($copia->id));
    }

    /**
     * El cheque NUEVO de un pago a proveedor que ya se marcó pagado frena el borrado del pago.
     *
     * @test
     */
    public function el_pago_cuyo_cheque_nuevo_ya_se_pago_no_se_borra()
    {
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor cheque pagado ' . uniqid(), self::DEUDA_PROVEEDOR);

        $fila = $this->fila_de_pago([
            'numero'        => '7020',
            'banco'         => 'Banco Nación',
            'fecha_emision' => Carbon::today()->format('Y-m-d'),
            'fecha_pago'    => Carbon::today()->addDays(15)->format('Y-m-d'),
        ]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $emitido = Cheque::where('current_acount_id', $pago_id)->where('user_id', $this->dueno->id)->first();

        $this->putJson('api/cheque/pagar', ['cheque_id' => $emitido->id, 'caja_id' => 0])->assertStatus(200);

        $response = $this->borrar_movimiento('provider', $pago_id);

        $response->assertStatus(422);
        $this->assertEquals('No se puede eliminar este pago: el cheque N° 7020 que se entregó en él ya figura como pagado.', $response->json('message'));
        $this->assertNotNull(CurrentAcount::find($pago_id));
        $this->assertNotNull(Cheque::find($emitido->id));
    }

    /**
     * Endosa un recibido en un pago a proveedor por `POST current-acount/pago`, como la pantalla.
     *
     * @param Cheque $recibido
     * @param \App\Models\Provider $proveedor
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
     * Endosa un recibido en un gasto por `POST expense`, como la pantalla.
     *
     * @param Cheque $recibido
     * @return array{0: Expense, 1: Cheque}  El gasto y la copia emitida.
     */
    protected function endosar_en_gasto(Cheque $recibido)
    {
        $fila = $this->fila_de_gasto($this->claves_de_endoso($recibido->fresh()));

        $response = $this->postJson('api/expense', $this->payload_de_gasto([$fila]));

        $response->assertStatus(201);

        $gasto = Expense::find((int) $response->json('model.id'));
        $this->gastos_creados_por_escenarios[] = $gasto->id;

        $copia = Cheque::where('endosado_desde_cheque_id', $recibido->id)
                        ->where('expense_id', $gasto->id)
                        ->first();

        $this->assertNotNull($copia, 'El endoso tenía que dejar la copia emitida colgada del gasto.');

        return [$gasto, $copia];
    }

    /**
     * `DELETE current-acount/<client|provider>/{id}?compensar_caja=1`, como el confirm de la SPA
     * (store current_acount.js, con el checkbox tildado por defecto).
     *
     * @param string $model_name
     * @param int $id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function borrar_movimiento($model_name, $id)
    {
        return $this->deleteJson('api/current-acount/' . $model_name . '/' . $id . '?compensar_caja=1');
    }
}
