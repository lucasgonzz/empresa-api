<?php

namespace Tests\Feature\Cheques;

use App\Models\Cheque;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\MovimientoCaja;

/**
 * Misión cheques-endoso-y-bancos — endosar un cheque recibido desde el modal de PAGO A PROVEEDOR:
 * una fila de tipo cheque que elige un recibido (`cheque_id`) en vez de cargar uno nuevo.
 *
 * Lo que protege: que el recibido salga de cartera marcado al proveedor, que nazca UNA sola copia
 * emitida colgada del pago (con el rastro del cliente y del cheque de origen), que la cuenta del
 * proveedor baje por el monto del cheque y que no se mueva ninguna caja (el cheque no es plata de
 * caja: es un papel que cambia de manos).
 *
 * @group cheques
 */
class Endoso_en_pago_a_proveedor_Test extends ChequesTestCase
{
    /**
     * @test
     */
    public function el_pago_a_proveedor_con_un_cheque_elegido_endosa_ese_cheque()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente del cheque ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor endosatario ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '1001', 'banco' => 'Banco Nación', 'es_echeq' => 1, 'notes' => 'Cheque del test 1']);

        $this->assertEquals('recibido', $recibido->tipo);
        $this->assertNull($recibido->endosado_a_provider_id);
        $this->assertContains($recibido->id, $this->ids_disponibles_para_endosar());

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();
        $movimientos_antes = $this->max_id_movimiento_caja();
        $saldo_antes = (float) CreditAccount::find($cuenta_proveedor->id)->saldo;

        $fila = $this->fila_de_pago($this->claves_de_endoso($recibido));

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago = CurrentAcount::find((int) $response->json('current_acount.id'));
        $this->cobros_cc_creados_por_escenarios[] = $pago->id;
        $this->registrar_movimientos_caja_nuevos($movimientos_antes);

        // --- El recibido salió de cartera, marcado al proveedor ---------------------------------
        $recibido = $recibido->fresh();

        $this->assertEquals($proveedor->id, $recibido->endosado_a_provider_id);
        $this->assertNotNull($recibido->fecha_endoso);
        $this->assertNull($recibido->endosado_en_expense_id);
        $this->assertNull($recibido->estado_manual);
        $this->assertEquals('recibido.endosados', $this->solapa_de($recibido->id));
        $this->assertNotContains($recibido->id, $this->ids_disponibles_para_endosar());

        // --- UNA copia emitida, colgada del pago, con el rastro del origen ----------------------
        $this->assertEquals($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count(), 'El endoso tenía que crear exactamente un cheque: la copia emitida.');

        $copias = $this->copias_de($recibido);

        $this->assertCount(1, $copias);

        $copia = $copias->first();

        $this->assertEquals('emitido', $copia->tipo);
        $this->assertEquals($proveedor->id, $copia->provider_id);
        $this->assertEquals($pago->id, $copia->current_acount_id);
        $this->assertNull($copia->expense_id);
        $this->assertNull($copia->client_id);
        $this->assertEquals($cliente->id, $copia->endosado_desde_client_id);
        $this->assertEquals($recibido->id, $copia->endosado_desde_cheque_id);
        $this->assertEquals('1001', $copia->numero);
        $this->assertEquals('Banco Nación', $copia->banco);
        $this->assertEquals(1, (int) $copia->es_echeq);
        $this->assertEquals('Cheque del test 1', $copia->notes);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $copia->amount, self::DELTA);
        $this->assertEquals($recibido->fecha_pago->format('Y-m-d'), $copia->fecha_pago->format('Y-m-d'));
        $this->assertNull($copia->endosado_a_provider_id);
        $this->assertNull($copia->endosado_en_expense_id);
        $this->assertNull($copia->fecha_endoso);
        $this->assertEquals('emitido.pendientes', $this->solapa_de($copia->id));

        // --- El pago del proveedor: su desglose y la cuenta --------------------------------------
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $pago->haber, self::DELTA);
        $this->assertEquals($proveedor->id, $pago->provider_id);
        $this->assertNull($pago->client_id);

        $pago->load('current_acount_payment_methods');

        $this->assertCount(1, $pago->current_acount_payment_methods);
        $this->assertEquals($this->metodo_cheque->id, $pago->current_acount_payment_methods[0]->id);
        $this->assertEqualsWithDelta(self::MONTO_CHEQUE, (float) $pago->current_acount_payment_methods[0]->pivot->amount, self::DELTA);
        $this->assertEmpty($pago->current_acount_payment_methods[0]->pivot->caja_id);

        $this->assertEqualsWithDelta($saldo_antes - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA, 'La cuenta del proveedor tenía que bajar por el monto del cheque.');
        $this->assertEqualsWithDelta(self::DEUDA_PROVEEDOR - self::MONTO_CHEQUE, (float) CreditAccount::find($cuenta_proveedor->id)->saldo, self::DELTA);

        // --- Ninguna caja se movió --------------------------------------------------------------
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $movimientos_antes)->count(), 'Un endoso no mueve plata de ninguna caja.');
    }

    /**
     * Un pago a proveedor con un cheque NUEVO sigue exactamente como antes: cheque emitido, sin
     * endoso, y ahora con el banco del catálogo si la fila lo trae.
     *
     * @test
     */
    public function un_cheque_nuevo_en_el_pago_a_proveedor_sigue_naciendo_emitido()
    {
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor cheque nuevo ' . uniqid(), self::DEUDA_PROVEEDOR);

        $cheques_antes = Cheque::where('user_id', $this->dueno->id)->count();

        $fila = $this->fila_de_pago([
            'numero'        => '2002',
            'banco'         => 'Banco Provincia',
            'fecha_emision' => now()->format('Y-m-d'),
            'fecha_pago'    => now()->addDays(15)->format('Y-m-d'),
        ]);

        /*
         * Sin la clave `notes`, que es como manda la fila el payment_method_factory de
         * current-acounts/pago/PaymentMethods.vue cuando el usuario no escribe ninguna nota: el
         * default de crear_cheque tiene que ser null y no el entero 0, que se veía como un "0"
         * escrito en la columna Notas y en el campo Notas al endosar el cheque.
         */
        unset($fila['notes']);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$fila]));

        $response->assertStatus(201);

        $pago_id = (int) $response->json('current_acount.id');
        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        $this->assertEquals($cheques_antes + 1, Cheque::where('user_id', $this->dueno->id)->count());

        $emitido = Cheque::where('current_acount_id', $pago_id)->first();

        $this->assertNotNull($emitido);
        $this->assertEquals('emitido', $emitido->tipo);
        $this->assertEquals($proveedor->id, $emitido->provider_id);
        $this->assertEquals('2002', $emitido->numero);
        $this->assertEquals('Banco Provincia', $emitido->banco);
        $this->assertNull($emitido->cheque_banco_id);
        $this->assertNull($emitido->endosado_desde_cheque_id);
        $this->assertNull($emitido->endosado_desde_client_id);
        $this->assertNull($emitido->expense_id);
        $this->assertNull($emitido->notes, 'Un cheque sin notas queda con notes null, no con un "0".');
        $this->assertEquals('emitido.pendientes', $this->solapa_de($emitido->id));
    }
}
