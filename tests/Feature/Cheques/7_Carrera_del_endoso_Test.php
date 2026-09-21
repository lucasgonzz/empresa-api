<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\Helpers\ChequeHelper;
use App\Models\Cheque;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use Carbon\Carbon;

/**
 * Misión cheques-endoso-y-bancos — la carrera del endoso (chequeo independiente, 21/9/2026).
 *
 * Dos requests con el mismo `cheque_id` pueden pasar las dos la prevalidación (las dos leyeron el
 * cheque en cartera). Lo que protege este archivo: que de las dos gane exactamente UNA (la marca
 * del origen es un UPDATE condicional atómico y la otra corta antes de crear su copia), y que la
 * que corta no deje un pago huérfano (el alta de la pantalla corre adentro de una transacción).
 *
 * @group cheques
 */
class Carrera_del_endoso_Test extends ChequesTestCase
{
    /**
     * Un pago a proveedor mínimo, como modelo destino de ChequeHelper::endosar(). Se crea directo
     * porque lo que se prueba acá es el helper, no el alta del pago.
     *
     * @param int $provider_id
     * @param CreditAccount $cuenta
     * @return CurrentAcount
     */
    protected function pago_a_proveedor($provider_id, $cuenta)
    {
        $pago = CurrentAcount::create([
            'haber'             => self::MONTO_CHEQUE,
            'status'            => 'pago_from_client',
            'user_id'           => $this->dueno->id,
            'provider_id'       => $provider_id,
            'credit_account_id' => $cuenta->id,
            'created_at'        => Carbon::now(),
        ]);

        $this->cobros_cc_creados_por_escenarios[] = $pago->id;

        return $pago;
    }

    /**
     * @test
     */
    public function dos_endosos_del_mismo_cheque_con_lecturas_viejas_dejan_una_sola_copia()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente carrera ' . uniqid());
        list($proveedor_a, $cuenta_a) = $this->proveedor_con_cuenta('Proveedor carrera A ' . uniqid());
        list($proveedor_b, $cuenta_b) = $this->proveedor_con_cuenta('Proveedor carrera B ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '9001']);

        // Dos lecturas del mismo cheque, las dos "en cartera": es lo que ven dos requests que
        // pasaron la prevalidación a la vez.
        $lectura_a = Cheque::find($recibido->id);
        $lectura_b = Cheque::find($recibido->id);

        $pago_a = $this->pago_a_proveedor($proveedor_a->id, $cuenta_a);
        $pago_b = $this->pago_a_proveedor($proveedor_b->id, $cuenta_b);

        $cheques_antes = Cheque::count();

        // La primera gana.
        $copia_a = ChequeHelper::endosar($lectura_a, $pago_a, ['cheque_id' => $recibido->id]);

        $this->assertEquals('emitido', $copia_a->tipo);
        $this->assertEquals($proveedor_a->id, $copia_a->provider_id);
        $this->assertEquals($recibido->id, $copia_a->endosado_desde_cheque_id);

        // La segunda, con su lectura vieja todavía "en cartera", tiene que cortar ANTES de crear nada.
        $this->assertTrue(ChequeHelper::en_cartera($lectura_b), 'La lectura vieja tiene que seguir viéndose en cartera: es la carrera.');

        try {
            ChequeHelper::endosar($lectura_b, $pago_b, ['cheque_id' => $recibido->id]);
            $this->fail('El segundo endoso del mismo cheque tenía que cortar con una excepción.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no se endosó dos veces', $e->getMessage());
        }

        $recibido = $recibido->fresh();

        $this->assertEquals($proveedor_a->id, $recibido->endosado_a_provider_id, 'El cheque queda endosado al que ganó, y solo a ese.');
        $this->assertNotNull($recibido->fecha_endoso);
        $this->assertEquals($cheques_antes + 1, Cheque::count(), 'Una sola copia emitida: la del que ganó.');
        $this->assertCount(1, $this->copias_de($recibido));
        $this->assertEquals(0, Cheque::where('provider_id', $proveedor_b->id)->count(), 'El que perdió no dejó ninguna copia.');
    }

    /**
     * El endoso que corta adentro de attachPaymentMethods() —con el pago ya creado— no deja el pago
     * huérfano: el alta de `POST current-acount/pago` corre adentro de una transacción y se revierte
     * entera.
     *
     * La "otra request" se simula con un listener de `created` de CurrentAcount: apenas nace el
     * pago, marca el cheque como endosado a otro proveedor, o sea exactamente entre la
     * prevalidación (que ya pasó) y el endoso. Esa marca corre adentro de la misma transacción
     * que se revierte, así que después del request el cheque vuelve a cartera: lo que se mira acá
     * es que NO quedó ni el pago ni la copia.
     *
     * @test
     */
    public function si_el_endoso_corta_adentro_del_alta_el_pago_no_queda_huerfano()
    {
        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente huérfano ' . uniqid());
        list($proveedor, $cuenta) = $this->proveedor_con_cuenta('Proveedor huérfano ' . uniqid(), self::DEUDA_PROVEEDOR);
        list($otro, $cuenta_otro) = $this->proveedor_con_cuenta('Proveedor que se adelantó ' . uniqid());

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '9002']);

        $cheques_antes = Cheque::count();
        $pagos_antes = CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count();
        $pivotes_antes = \DB::table('current_acount_current_acount_payment_method')->count();
        $saldo_antes = (float) CreditAccount::find($cuenta->id)->saldo;

        $dispatcher = CurrentAcount::getEventDispatcher();

        CurrentAcount::created(function ($pago) use ($recibido, $otro) {

            if ((int) $pago->provider_id !== (int) $otro->id) {

                Cheque::where('id', $recibido->id)
                        ->whereNull('endosado_a_provider_id')
                        ->update(['endosado_a_provider_id' => $otro->id, 'fecha_endoso' => Carbon::now()]);
            }
        });

        try {
            $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta, [$this->fila_de_pago($this->claves_de_endoso($recibido))]));
        } finally {
            $dispatcher->forget('eloquent.created: ' . CurrentAcount::class);
        }

        $this->assertEquals(500, $response->getStatusCode(), 'El endoso que se pisó tenía que cortar el alta: ' . $response->getContent());

        $this->assertEquals($pagos_antes, CurrentAcount::where('credit_account_id', $cuenta->id)->whereNotNull('haber')->count(), 'El pago no tenía que quedar registrado.');
        $this->assertEquals($pivotes_antes, \DB::table('current_acount_current_acount_payment_method')->count(), 'Ni su desglose.');
        $this->assertEquals($cheques_antes, Cheque::count(), 'Ni la copia emitida.');
        $this->assertEqualsWithDelta($saldo_antes, (float) CreditAccount::find($cuenta->id)->saldo, self::DELTA, 'Ni el saldo del proveedor.');
        $this->assertEquals(0, Cheque::where('provider_id', $proveedor->id)->count());
    }
}
