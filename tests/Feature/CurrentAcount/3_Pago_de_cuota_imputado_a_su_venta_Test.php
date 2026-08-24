<?php

namespace Tests\Feature\CurrentAcount;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\PaymentPlanCuota;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda correctivos 24/8 — ítem 13: el pago registrado desde una CUOTA de un plan de pago
 * se imputa a LA VENTA sobre la que se creó el plan, no al comprobante sin saldar más
 * viejo (regla de Lucas, informe 20260824 §7).
 *
 * Verificado antes de construir: la SPA (PagarBtn.vue de cuotas-pendientes) abre el modal
 * genérico de pago y manda `payment_plan_cuota`, pero nunca `to_pay`; y
 * CurrentAcountController::pago() solo usaba `to_pay` para dirigir la imputación, así que
 * el pago de una cuota entraba a la cola FIFO y saldaba la deuda MÁS VIEJA del cliente.
 * CurrentAcountCuotaHelper::pagar_cuota() solo marcaba la cuota como paga.
 *
 * El fix carga to_pay_id con el débito de la venta del plan
 * (CurrentAcountCuotaHelper::get_to_pay_id), reusando el motor de imputación dirigida que
 * ya usaba la NC de una devolución (CurrentAcountPagoHelper::setSinPagar + to_pay_id).
 *
 * Molde del escenario calcado de tests/Feature/Devoluciones/1.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Pago_de_cuota_imputado_a_su_venta_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Escenario: cliente con cuenta corriente en pesos y dos ventas con débito sin_pagar,
     * la vieja (A, 1000) primero y la nueva (B, 500) después. El plan de pago se arma
     * sobre B por el endpoint real.
     *
     * @param string $nombre_cliente
     * @return array
     */
    protected function escenario_base($nombre_cliente)
    {
        $client = Client::create([
            'name'    => $nombre_cliente,
            'user_id' => 500,
        ]);

        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => 500,
        ]);

        $venta_a = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);

        $debito_a = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta_a->id,
            'debe'              => 1000,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta_a->id,
            'credit_account_id' => $credit_account->id,
            'user_id'           => 500,
            'created_at'        => Carbon::now()->subDays(2),
        ]);

        $venta_b = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => 1,
            'total'     => 500,
            'terminada' => 1,
        ]);

        $debito_b = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta_b->id,
            'debe'              => 500,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta_b->id,
            'credit_account_id' => $credit_account->id,
            'user_id'           => 500,
            'created_at'        => Carbon::now()->subDay(),
        ]);

        /* El plan sobre la venta B, por el endpoint real: 2 cuotas de 250. */
        $response = $this->postJson('api/payment-plan', [
            'sale_id'         => $venta_b->id,
            'cantidad_cuotas' => 2,
            'frequency'       => 'monthly',
            'start_date'      => Carbon::now()->format('Y-m-d'),
        ]);

        $response->assertStatus(201);

        /** Primera cuota pendiente del plan (250). */
        $cuota = PaymentPlanCuota::where('sale_id', $venta_b->id)
                                ->orderBy('numero_cuota')
                                ->first();

        return [
            'client'         => $client,
            'credit_account' => $credit_account,
            'venta_a'        => $venta_a,
            'debito_a'       => $debito_a,
            'venta_b'        => $venta_b,
            'debito_b'       => $debito_b,
            'cuota'          => $cuota,
        ];
    }

    /**
     * Payload mínimo de POST api/current-acount/pago sin caja (el foco es la imputación).
     *
     * @param array $e     Escenario de escenario_base().
     * @param float $monto
     * @param array $overrides
     * @return array
     */
    protected function payload_pago($e, $monto, $overrides = [])
    {
        /** Cualquier método de pago del fixture sirve; el pago va sin caja. */
        $metodo = CurrentAcountPaymentMethod::first();

        return array_merge([
            'description'                    => 'Pago cuota (test tanda 2408)',
            'credit_account_id'              => $e['credit_account']->id,
            'is_provisorio'                  => 0,
            'model_name'                     => 'client',
            'model_id'                       => $e['client']->id,
            'haber'                          => $monto,
            'current_date'                   => 1,
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
            'current_acount_payment_methods' => [
                [
                    'current_acount_payment_method_id' => $metodo->id,
                    'amount'                           => $monto,
                    'caja_id'                          => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * El pago desde la cuota se imputa al débito de la venta del plan (B) y la deuda más
     * vieja (A) queda intacta. La cuota queda con su pago registrado.
     *
     * @group current-acount
     * @test
     */
    public function el_pago_desde_una_cuota_se_imputa_a_la_venta_del_plan()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Plan 2408 A');

        $this->assertNotNull($e['cuota'], 'El endpoint del plan no dejó cuotas.');

        $response = $this->postJson('api/current-acount/pago', $this->payload_pago($e, 250, [
            'payment_plan_cuota' => ['id' => $e['cuota']->id],
        ]));

        $response->assertStatus(201);

        /** El pago que quedó registrado. */
        $pago = CurrentAcount::where('status', 'pago_from_client')
                            ->where('client_id', $e['client']->id)
                            ->latest('id')
                            ->first();

        $this->assertNotNull($pago, 'No se registró el pago.');

        // La imputación dirigida: el pago apunta al débito de la venta del plan.
        $this->assertEquals(
            $e['debito_b']->id,
            $pago->to_pay_id,
            'El pago de la cuota tiene que imputarse al débito de la venta del plan, no entrar FIFO.'
        );

        // El débito de la venta del plan se está pagando con los 250 de la cuota...
        $debito_b = $e['debito_b']->fresh();
        $this->assertEquals('pagandose', $debito_b->status);
        $this->assertEquals(250, (float) $debito_b->pagandose);

        // ...y la deuda más vieja del cliente quedó intacta (antes del fix se saldaba esta).
        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('sin_pagar', $debito_a->status);
        $this->assertEquals(0, (float) $debito_a->pagandose);

        // La cuota registró su pago (comportamiento preexistente que tiene que seguir).
        $cuota = $e['cuota']->fresh();
        $this->assertEquals(250, (float) $cuota->amount_paid);
        $this->assertSame('pagado', $cuota->estado);
    }

    /**
     * Control: el pago común (sin cuota y sin to_pay) sigue entrando FIFO y salda la deuda
     * más vieja, como siempre.
     *
     * @group current-acount
     * @test
     */
    public function el_pago_comun_sigue_entrando_fifo()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Plan 2408 B');

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 250))->assertStatus(201);

        // FIFO: el pago cae en la deuda más vieja (A); la venta del plan no se toca.
        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('pagandose', $debito_a->status);
        $this->assertEquals(250, (float) $debito_a->pagandose);

        $debito_b = $e['debito_b']->fresh();
        $this->assertEquals('sin_pagar', $debito_b->status);
        $this->assertEquals(0, (float) $debito_b->pagandose);
    }

    /**
     * Un to_pay explícito del request le gana a la cuota: la imputación elegida a mano
     * se respeta.
     *
     * @group current-acount
     * @test
     */
    public function el_to_pay_explicito_le_gana_a_la_cuota()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Plan 2408 C');

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 250, [
            'payment_plan_cuota' => ['id' => $e['cuota']->id],
            'to_pay'             => ['id' => $e['debito_a']->id],
        ]))->assertStatus(201);

        $pago = CurrentAcount::where('status', 'pago_from_client')
                            ->where('client_id', $e['client']->id)
                            ->latest('id')
                            ->first();

        $this->assertEquals(
            $e['debito_a']->id,
            $pago->to_pay_id,
            'El to_pay explícito del request tiene que ganarle a la imputación por cuota.'
        );

        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('pagandose', $debito_a->status);
        $this->assertEquals(250, (float) $debito_a->pagandose);
    }
}
