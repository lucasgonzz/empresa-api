<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\CurrentAcount;
use App\Models\MovimientoCaja;
use App\Models\Provider;

/**
 * Misión cheques-endoso-y-bancos — el botón Endosar del módulo de cheques (`PUT cheque/endosar`)
 * deja EXACTAMENTE las mismas filas que el endoso desde el modal de pago a proveedor: es el mismo
 * camino (ChequeHelper::endosar), y si mañana alguien "simplifica" el botón marcando el cheque a
 * mano de vuelta, este test se pone rojo.
 *
 * @group cheques
 */
class Endosar_desde_el_modulo_Test extends ChequesTestCase
{
    /**
     * @test
     */
    public function el_boton_endosar_deja_las_mismas_filas_que_el_pago_a_proveedor()
    {
        // Dos clientes con un cheque igual cada uno, dos proveedores con la misma deuda: los dos
        // caminos arrancan del mismo estado.
        list($cliente_pago, $cuenta_cliente_pago) = $this->cliente_con_cuenta('Paridad pago ' . uniqid());
        list($cliente_boton, $cuenta_cliente_boton) = $this->cliente_con_cuenta('Paridad botón ' . uniqid());
        list($proveedor_pago, $cuenta_pago) = $this->proveedor_con_cuenta('Proveedor paridad pago ' . uniqid(), self::DEUDA_PROVEEDOR);
        list($proveedor_boton, $cuenta_boton) = $this->proveedor_con_cuenta('Proveedor paridad botón ' . uniqid(), self::DEUDA_PROVEEDOR);

        $datos_del_cheque = [
            'numero'        => '7007',
            'banco'         => 'Banco Santander',
            'fecha_emision' => now()->format('Y-m-d'),
            'fecha_pago'    => now()->addDays(12)->format('Y-m-d'),
            'es_echeq'      => 1,
            'notes'         => 'Cheque de paridad',
        ];

        $recibido_pago = $this->cobrar_con_cheque($cliente_pago, $cuenta_cliente_pago, $datos_del_cheque);
        $recibido_boton = $this->cobrar_con_cheque($cliente_boton, $cuenta_cliente_boton, $datos_del_cheque);

        $movimientos_antes = $this->max_id_movimiento_caja();

        // --- Camino del modal de pago -----------------------------------------------------------
        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor_pago->id, $cuenta_pago, [$this->fila_de_pago($this->claves_de_endoso($recibido_pago))]));
        $response->assertStatus(201);
        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

        $radiografia_pago = $this->radiografia_del_endoso($recibido_pago, $proveedor_pago, $cuenta_pago);

        // --- Camino del botón -------------------------------------------------------------------
        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();

        $response = $this->putJson('api/cheque/endosar', [
            'cheque_id'   => $recibido_boton->id,
            'provider_id' => $proveedor_boton->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals($recibido_boton->id, (int) $response->json('model.id'));
        $this->assertEquals($proveedor_boton->id, (int) $response->json('model.endosado_a_provider_id'));

        $this->assertEquals($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count(), 'El botón tenía que crear exactamente un cheque: la copia emitida.');

        $copia = $this->copias_de($recibido_boton)->first();
        $this->assertNotNull($copia);
        $this->cobros_cc_creados_por_escenarios[] = (int) $copia->current_acount_id;

        $radiografia_boton = $this->radiografia_del_endoso($recibido_boton, $proveedor_boton, $cuenta_boton);

        // --- Lo mismo, fila por fila ------------------------------------------------------------
        $this->assertEquals($radiografia_pago, $radiografia_boton, 'El botón Endosar tiene que dejar exactamente lo mismo que el pago a proveedor con el cheque elegido.');

        // Y valores concretos, para que no pase con dos radiografías vacías.
        $this->assertTrue($radiografia_boton['recibido']['endosado_al_proveedor']);
        $this->assertTrue($radiografia_boton['recibido']['tiene_fecha_endoso']);
        $this->assertEquals('recibido.endosados', $radiografia_boton['recibido']['solapa']);
        $this->assertEquals(1, $radiografia_boton['copias']);
        $this->assertEquals('emitido', $radiografia_boton['copia']['tipo']);
        $this->assertEquals('7007', $radiografia_boton['copia']['numero']);
        $this->assertEquals('Banco Santander', $radiografia_boton['copia']['banco']);
        $this->assertEquals(1, $radiografia_boton['copia']['es_echeq']);
        $this->assertTrue($radiografia_boton['copia']['es_del_proveedor']);
        $this->assertTrue($radiografia_boton['copia']['endosado_desde_cliente']);
        $this->assertTrue($radiografia_boton['copia']['tiene_pago']);
        $this->assertEquals('emitido.pendientes', $radiografia_boton['copia']['solapa']);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, $radiografia_boton['pago']['haber'], self::DELTA);
        $this->assertTrue($radiografia_boton['pago']['es_del_proveedor']);
        $this->assertCount(1, $radiografia_boton['pago']['metodos']);
        $this->assertEquals($this->metodo_cheque->id, $radiografia_boton['pago']['metodos'][0]['metodo_de_pago']);
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, $radiografia_boton['saldo_de_la_cuenta'], self::DELTA);

        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Ninguno de los dos caminos mueve caja.');

        // Los dos pagos tienen su recibo numerado con el mismo detalle que la pantalla.
        $pago_boton = CurrentAcount::find($copia->current_acount_id);
        $this->assertEquals('Pago N°' . $pago_boton->num_receipt, $pago_boton->detalle);
        $this->assertEquals($this->dueno->id, (int) $pago_boton->employee_id);
    }

    /**
     * @test
     */
    public function el_boton_frena_con_422_si_el_cheque_ya_no_esta_disponible()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente botón 422 ' . uniqid());
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor botón 422 ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7008']);

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);
        $response->assertStatus(200);
        $this->cobros_cc_creados_por_escenarios[] = (int) $this->copias_de($recibido)->first()->current_acount_id;

        $cheques_antes = Cheque::count();
        $pagos_antes = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count();
        $antes = $recibido->fresh()->toArray();

        // Segunda vez: ya está endosado.
        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $proveedor->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('El cheque N° 7008 ya fue endosado', $response->json('message'));
        $this->assertEquals($cheques_antes, Cheque::count());
        $this->assertEquals($pagos_antes, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count());
        $this->assertEquals($antes, $recibido->fresh()->toArray());
        $this->assertCount(1, $this->copias_de($recibido));

        // Uno que no existe, y ninguno.
        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => 999999999, 'provider_id' => $proveedor->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no existe o no es de tu cuenta', $response->json('message'));

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => 0, 'provider_id' => $proveedor->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no existe o no es de tu cuenta', $response->json('message'));
    }

    /**
     * Sin cuenta corriente del proveedor en la moneda del cheque no hay dónde registrar el pago:
     * antes se devolvía 200 sin marcar nada; ahora es 422 con el motivo y el cheque sigue en cartera.
     *
     * @test
     */
    public function el_boton_frena_con_422_si_el_proveedor_no_tiene_cuenta_corriente()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente sin cuenta prov ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '7009']);

        // Un proveedor SIN credit_accounts: se crea a mano, sin pasar por CreditAccountHelper.
        $sin_cuenta = Provider::create([
            'name'    => 'Proveedor sin cuenta ' . uniqid(),
            'user_id' => $this->dueno->id,
            'status'  => 'active',
        ]);

        $cheques_antes = Cheque::count();
        $antes = $recibido->fresh()->toArray();

        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => $sin_cuenta->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no tiene cuenta corriente', $response->json('message'));
        $this->assertStringContainsString('7009', $response->json('message'));
        $this->assertEquals($cheques_antes, Cheque::count());
        $this->assertEquals($antes, $recibido->fresh()->toArray(), 'El cheque tenía que seguir en cartera, sin marca.');
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());
        $this->assertEquals(0, CurrentAcount::where('provider_id', $sin_cuenta->id)->count());

        // Sin proveedor, tampoco.
        $response = $this->putJson('api/cheque/endosar', ['cheque_id' => $recibido->id, 'provider_id' => 0]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Elegí el proveedor', $response->json('message'));
        $this->assertEquals($antes, $recibido->fresh()->toArray());
    }
}
