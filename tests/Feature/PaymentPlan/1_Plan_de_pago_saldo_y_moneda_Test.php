<?php

namespace Tests\Feature\PaymentPlan;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanCuota;
use App\Models\Sale;
use App\Models\User;
use App\Services\PaymentPlan\PaymentPlanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión planes-de-pago-saldo-y-dolares (21/10/2026).
 *
 * Pedido de Lucas: el importe a financiar de un plan nuevo es, por defecto, lo que falta saldar de
 * la venta (venta de 1.000.000 con 700.000 pagados → 300.000), se puede cambiar, cada cuota puede
 * llevar su nota, y el plan va en la moneda de la venta (pesos o dólares).
 *
 * Se le pega a los endpoints reales (GET payment-plan/saldo-de-venta/{id} y POST payment-plan) y se
 * mira lo que quedó en la base.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Plan_de_pago_saldo_y_moneda_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Loguea al usuario 500 de la base de testing o saltea el test si no está sembrado.
     *
     * @return void
     */
    protected function loguear()
    {
        $user = User::find(500);
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
    }

    /**
     * Venta de un cliente con su débito en cuenta corriente y lo que ya se le imputó.
     *
     * @param float $total Total de la venta.
     * @param float $pagado Lo ya imputado al débito (`pagandose`).
     * @param int $moneda_id 1 pesos, 2 dólares.
     * @param bool $con_debito false para una venta que no va a la cuenta corriente.
     * @return \App\Models\Sale
     */
    protected function venta($total, $pagado = 0, $moneda_id = 1, $con_debito = true)
    {
        $client = Client::create([
            'name'    => 'zz Cliente plan de pago '.uniqid(),
            'user_id' => 500,
        ]);

        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => $moneda_id,
            'user_id'    => 500,
        ]);

        $sale = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => $moneda_id,
            'total'     => $total,
            'terminada' => 1,
        ]);

        if ($con_debito) {
            CurrentAcount::create([
                'detalle'           => 'Venta N°'.$sale->id,
                'debe'              => $total,
                'pagandose'         => $pagado,
                'status'            => $pagado > 0 ? 'pagandose' : 'sin_pagar',
                'client_id'         => $client->id,
                'sale_id'           => $sale->id,
                'credit_account_id' => $credit_account->id,
                // Sin `user_id`, como lo crea CurrentAcountFromSaleHelper: con 500 acá el test tapó el
                // 21/10 un filtro por dueño que no encontraba el débito de ninguna venta real.
            ]);
        }

        return $sale;
    }

    /**
     * El ejemplo de Lucas: venta de 1.000.000 con 700.000 pagados → el saldo a financiar es 300.000.
     *
     * @group payment-plan
     * @test
     */
    public function el_saldo_de_la_venta_es_el_total_menos_lo_pagado()
    {
        $this->loguear();
        $sale = $this->venta(1000000, 700000);

        $response = $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id);

        $response->assertStatus(200);
        $response->assertJson([
            'sale_id'                      => $sale->id,
            'total'                        => 1000000,
            'pagado'                       => 700000,
            'saldo_pendiente'              => 300000,
            'moneda_id'                    => 1,
            'en_cuenta_corriente'          => true,
            'planes_con_cuotas_pendientes' => 0,
        ]);
    }

    /**
     * Sin `total_amount`, el plan financia lo que falta saldar (no el total) y lo reparte exacto.
     *
     * @group payment-plan
     * @test
     */
    public function sin_importe_el_plan_financia_el_saldo_pendiente()
    {
        $this->loguear();
        $sale = $this->venta(1000000, 700000);

        $response = $this->postJson('api/payment-plan', [
            'sale_id'         => $sale->id,
            'cantidad_cuotas' => 3,
            'start_date'      => '2026-11-10',
        ]);

        $response->assertStatus(201);

        $plan = PaymentPlan::where('sale_id', $sale->id)->first();
        $this->assertEquals(300000, (float) $plan->total_amount);
        $this->assertEquals(1, (int) $plan->moneda_id);

        $cuotas = PaymentPlanCuota::where('payment_plan_id', $plan->id)->orderBy('numero_cuota')->get();
        $this->assertCount(3, $cuotas);
        foreach ($cuotas as $cuota) {
            $this->assertEquals(100000, (float) $cuota->amount);
            $this->assertEquals(1, (int) $cuota->moneda_id);
        }
    }

    /**
     * Con un importe elegido a mano se financia ese, y la última cuota absorbe el redondeo: la suma
     * cierra exacta (antes 1.000.000 en 3 sumaba 999.999,99).
     *
     * @group payment-plan
     * @test
     */
    public function el_importe_editado_se_reparte_y_la_suma_cierra_exacta()
    {
        $this->loguear();
        $sale = $this->venta(1000000, 700000);

        $response = $this->postJson('api/payment-plan', [
            'sale_id'         => $sale->id,
            'cantidad_cuotas' => 3,
            'start_date'      => '2026-11-10',
            'total_amount'    => 1000000,
        ]);

        $response->assertStatus(201);

        $importes = PaymentPlanCuota::where('sale_id', $sale->id)->orderBy('numero_cuota')->pluck('amount')->all();
        $this->assertEquals([333333.33, 333333.33, 333333.34], array_map('floatval', $importes));
        $this->assertEquals(1000000, round(array_sum(array_map('floatval', $importes)), 2));
    }

    /**
     * Una venta en dólares arma el plan y las cuotas en dólares.
     *
     * @group payment-plan
     * @test
     */
    public function la_venta_en_dolares_arma_el_plan_en_dolares()
    {
        $this->loguear();
        $sale = $this->venta(1200, 200, 2);

        $saldo = $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id);
        $saldo->assertStatus(200);
        $saldo->assertJson(['moneda_id' => 2, 'saldo_pendiente' => 1000]);

        $response = $this->postJson('api/payment-plan', [
            'sale_id'         => $sale->id,
            'cantidad_cuotas' => 4,
            'start_date'      => '2026-11-10',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('model.moneda_id', 2);
        $response->assertJsonPath('model.cuotas.0.moneda_id', 2);

        $this->assertEquals(4, PaymentPlanCuota::where('sale_id', $sale->id)->where('moneda_id', 2)->count());
        $this->assertEquals(250, (float) PaymentPlanCuota::where('sale_id', $sale->id)->value('amount'));
    }

    /**
     * Las fechas se cuentan desde el inicio: un plan que arranca el 31 vuelve al 31 después de febrero.
     *
     * @group payment-plan
     * @test
     */
    public function las_fechas_mensuales_se_cuentan_desde_el_inicio()
    {
        $this->loguear();
        $sale = $this->venta(3000);

        $this->postJson('api/payment-plan', [
            'sale_id'         => $sale->id,
            'cantidad_cuotas' => 3,
            'start_date'      => '2027-01-31',
            'frequency'       => 'monthly',
        ])->assertStatus(201);

        $fechas = PaymentPlanCuota::where('sale_id', $sale->id)->orderBy('numero_cuota')->pluck('fecha_vencimiento')->all();
        $this->assertEquals(['2027-01-31', '2027-02-28', '2027-03-31'], array_map(function ($f) {
            return substr((string) $f, 0, 10);
        }, $fechas));
    }

    /**
     * Cada cuota guarda su nota; la que viene vacía hereda la nota del plan, que queda en el plan.
     *
     * @group payment-plan
     * @test
     */
    public function cada_cuota_guarda_su_nota_y_la_vacia_hereda_la_del_plan()
    {
        $this->loguear();
        $sale = $this->venta(3000);

        $this->postJson('api/payment-plan', [
            'sale_id'         => $sale->id,
            'cantidad_cuotas' => 3,
            'start_date'      => '2026-11-10',
            'notes'           => 'Acordado por teléfono',
            'cuotas'          => [
                ['numero_cuota' => 1, 'notes' => ''],
                ['numero_cuota' => 2, 'notes' => 'Paga con cheque'],
                ['numero_cuota' => 3, 'notes' => null],
            ],
        ])->assertStatus(201);

        $this->assertEquals('Acordado por teléfono', PaymentPlan::where('sale_id', $sale->id)->value('notes'));

        $notas = PaymentPlanCuota::where('sale_id', $sale->id)->orderBy('numero_cuota')->pluck('observations')->all();
        $this->assertEquals(['Acordado por teléfono', 'Paga con cheque', 'Acordado por teléfono'], $notas);
    }

    /**
     * Camino real de punta a punta: la venta entra por POST api/sale (que crea su débito como lo crea
     * el sistema, sin user_id) y el plan se arma sobre ella. Cubre el defecto del 21/10, en el que el
     * saldo no encontraba el débito de ninguna venta real.
     *
     * @group payment-plan
     * @test
     */
    public function una_venta_hecha_por_la_api_esta_en_la_cuenta_corriente_y_admite_plan()
    {
        $this->loguear();

        $client = Client::create(['name' => 'zz Cliente plan api '.uniqid(), 'user_id' => 500]);
        \App\Http\Controllers\Helpers\CreditAccountHelper::crear_credit_accounts('client', $client->id, 500);

        /** Débito de una venta real, armado por el mismo helper que usa SaleController. */
        $sale = Sale::create([
            'user_id'             => 500,
            'client_id'           => $client->id,
            'moneda_id'           => 1,
            'total'               => 8000,
            'terminada'           => 1,
            'save_current_acount' => 1,
        ]);
        \App\Http\Controllers\Helpers\SaleHelper::create_current_acount($sale);

        $this->assertNull(CurrentAcount::where('sale_id', $sale->id)->value('user_id'), 'Precondición: el débito real nace sin user_id.');

        $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id)
            ->assertStatus(200)
            ->assertJson(['en_cuenta_corriente' => true, 'saldo_pendiente' => 8000]);

        $this->postJson('api/payment-plan', ['sale_id' => $sale->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'])
            ->assertStatus(201);
    }

    /**
     * Regla de Lucas (22/10/2026): el plan de pago se arma solo sobre una venta que fue a la cuenta
     * corriente. El saldo lo informa (`en_cuenta_corriente = false`) y el alta la rechaza, con o sin
     * importe explícito.
     *
     * @group payment-plan
     * @test
     */
    public function la_venta_sin_cuenta_corriente_no_admite_plan()
    {
        $this->loguear();
        $sale = $this->venta(5000, 0, 1, false);

        $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id)
            ->assertStatus(200)
            ->assertJson(['total' => 5000, 'pagado' => 0, 'saldo_pendiente' => 5000, 'en_cuenta_corriente' => false]);

        $base = ['sale_id' => $sale->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'];
        $this->postJson('api/payment-plan', $base)->assertStatus(422);
        $this->postJson('api/payment-plan', array_merge($base, ['total_amount' => 5000]))->assertStatus(422);

        $this->assertEquals(0, PaymentPlan::where('sale_id', $sale->id)->count());
    }

    /**
     * Los datos que no permiten armar el plan vuelven 422 sin guardar nada; la venta de otro negocio, 404.
     *
     * @group payment-plan
     * @test
     */
    public function los_datos_invalidos_no_guardan_nada()
    {
        $this->loguear();
        $sale = $this->venta(1000);
        $saldada = $this->venta(1000, 1000);

        $base = ['sale_id' => $sale->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'];

        $this->postJson('api/payment-plan', array_merge($base, ['cantidad_cuotas' => 0]))->assertStatus(422);
        $this->postJson('api/payment-plan', array_merge($base, ['cantidad_cuotas' => 2.5]))->assertStatus(422);
        $this->postJson('api/payment-plan', array_merge($base, ['total_amount' => -5]))->assertStatus(422);
        $this->postJson('api/payment-plan', array_merge($base, ['start_date' => null]))->assertStatus(422);
        $this->postJson('api/payment-plan', array_merge($base, ['frequency' => 'anual']))->assertStatus(422);
        $this->assertEquals(0, PaymentPlan::where('sale_id', $sale->id)->count());

        // Venta saldada sin importe explícito: no hay nada para financiar.
        $this->postJson('api/payment-plan', ['sale_id' => $saldada->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'])
            ->assertStatus(422);

        // Venta de otro negocio (sales.user_id tiene FK a users en la base de testing: un usuario real).
        $otro_usuario = User::where('id', '<>', 500)->whereNull('owner_id')->first();
        if (is_null($otro_usuario)) {
            return;
        }
        $ajena = Sale::create(['user_id' => $otro_usuario->id, 'total' => 100, 'terminada' => 1]);
        $this->postJson('api/payment-plan', array_merge($base, ['sale_id' => $ajena->id]))->assertStatus(404);
        $this->getJson('api/payment-plan/saldo-de-venta/'.$ajena->id)->assertStatus(404);
    }

    /**
     * Hallazgos del chequeo independiente: lo que dejaba cuotas en $0 o planes rotos ahora es 422,
     * con mensaje en castellano.
     *
     * @group payment-plan
     * @test
     */
    public function los_importes_que_dejarian_cuotas_en_cero_y_los_datos_mal_tipados_son_422()
    {
        $this->loguear();
        $sale = $this->venta(1000);
        $base = ['sale_id' => $sale->id, 'cantidad_cuotas' => 3, 'start_date' => '2026-11-10'];

        // 2 centavos no alcanzan para 3 cuotas.
        $this->postJson('api/payment-plan', array_merge($base, ['total_amount' => 0.02]))->assertStatus(422);
        // 0,004 redondea a 0: se valida después de redondear.
        $this->postJson('api/payment-plan', array_merge($base, ['total_amount' => '0.004']))->assertStatus(422);
        // Más de lo que entra en decimal(12,2).
        $this->postJson('api/payment-plan', array_merge($base, ['total_amount' => 99999999999]))->assertStatus(422);
        // Fecha que Carbon no entiende: mensaje propio, no el de Carbon en inglés.
        $this->postJson('api/payment-plan', array_merge($base, ['start_date' => 'hola']))
            ->assertStatus(422)
            ->assertJson(['message' => 'La fecha de la primera cuota no es válida.']);
        // Intervalo de días no entero.
        $this->postJson('api/payment-plan', array_merge($base, ['frequency' => 'custom_days', 'interval_in_days' => '3.7']))->assertStatus(422);
        // Importe por cuota negativo.
        $this->postJson('api/payment-plan', array_merge($base, ['amount_per_installment' => -10]))->assertStatus(422);

        $this->assertEquals(0, PaymentPlan::where('sale_id', $sale->id)->count());

        // Una nota que no es texto se ignora en lugar de romper el alta.
        $this->postJson('api/payment-plan', array_merge($base, ['cuotas' => [['numero_cuota' => 1, 'notes' => ['x']]]]))
            ->assertStatus(201);
    }

    /**
     * Una venta sin cliente no admite plan: no hay cuenta corriente donde pagar las cuotas.
     *
     * @group payment-plan
     * @test
     */
    public function la_venta_sin_cliente_no_admite_plan()
    {
        $this->loguear();
        $sale = Sale::create(['user_id' => 500, 'total' => 1000, 'terminada' => 1]);

        $this->postJson('api/payment-plan', ['sale_id' => $sale->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'])
            ->assertStatus(422);
        $this->assertEquals(0, PaymentPlan::where('sale_id', $sale->id)->count());
    }

    /**
     * Un débito marcado 'pagado' por el camino viejo (sin completar `pagandose`) es una venta saldada.
     *
     * @group payment-plan
     * @test
     */
    public function el_debito_marcado_pagado_sin_pagandose_tiene_saldo_cero()
    {
        $this->loguear();
        $sale = $this->venta(1000);
        CurrentAcount::where('sale_id', $sale->id)->update(['status' => 'pagado', 'pagandose' => null]);

        $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id)
            ->assertStatus(200)
            ->assertJson(['pagado' => 1000, 'saldo_pendiente' => 0]);
    }

    /**
     * Un segundo plan sobre la misma venta se avisa en el saldo.
     *
     * @group payment-plan
     * @test
     */
    public function el_saldo_avisa_si_la_venta_ya_tiene_un_plan_con_cuotas_pendientes()
    {
        $this->loguear();
        $sale = $this->venta(1000);

        $this->postJson('api/payment-plan', ['sale_id' => $sale->id, 'cantidad_cuotas' => 2, 'start_date' => '2026-11-10'])
            ->assertStatus(201);

        $this->getJson('api/payment-plan/saldo-de-venta/'.$sale->id)
            ->assertStatus(200)
            ->assertJson(['planes_con_cuotas_pendientes' => 1]);
    }

    /**
     * El reparto nunca deja una cuota negativa y siempre suma el total (1 peso en 120 cuotas).
     *
     * @group payment-plan
     * @test
     */
    public function el_reparto_no_deja_cuotas_negativas()
    {
        $importes = PaymentPlanService::importes_de_las_cuotas(1, 120);

        $this->assertCount(120, $importes);
        foreach ($importes as $importe) {
            $this->assertGreaterThanOrEqual(0, $importe);
        }
        $this->assertEquals(1, round(array_sum($importes), 2));
    }
}
