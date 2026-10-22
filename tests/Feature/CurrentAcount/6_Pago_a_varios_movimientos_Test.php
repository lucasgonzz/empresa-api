<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión pago-cc-multiples-movimientos (22/10/2026): un único pago/recibo dirigido a VARIOS
 * débitos de la cuenta corriente (`to_pay_ids` en `POST api/current-acount/pago`).
 *
 * Reglas que fijan estos tests:
 *  - los débitos elegidos que sigan pendientes se saldan primero, del más viejo al más nuevo
 *    (created_at, id), sin importar el orden en que vengan en la lista;
 *  - el sobrante sigue FIFO sobre el resto de los pendientes (como con un solo `to_pay`);
 *  - la elección se GUARDA: la imputación se rehace desde el pago (check_saldos_y_pagos), y si la
 *    lista no se persistiera el primer recálculo lo convertiría en FIFO;
 *  - un id de otra cuenta se rechaza con 422 y sin crear nada; con menos de 2 ids distintos el
 *    pago es el de siempre.
 *
 * Molde del escenario y del payload calcado de 3_Pago_de_cuota_imputado_a_su_venta_Test.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Pago_a_varios_movimientos_Test extends TestCase
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
     * Autentica con el usuario 500 o saltea el test si la base de testing no lo tiene.
     *
     * @return void
     */
    protected function entrar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');
    }

    /**
     * Un cliente con cuenta corriente en pesos.
     *
     * @param string $nombre_cliente
     * @return array [client, credit_account]
     */
    protected function cliente_con_cuenta($nombre_cliente)
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

        return [$client, $credit_account];
    }

    /**
     * Un débito de venta sin pagar en la cuenta.
     *
     * @param \App\Models\Client $client
     * @param \App\Models\CreditAccount $credit_account
     * @param float $monto
     * @param int $hace_dias Antigüedad: más días = más viejo.
     * @return \App\Models\CurrentAcount
     */
    protected function debito($client, $credit_account, $monto, $hace_dias)
    {
        $venta = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => 1,
            'total'     => $monto,
            'terminada' => 1,
        ]);

        return CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta->id,
            'debe'              => $monto,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta->id,
            'credit_account_id' => $credit_account->id,
            'user_id'           => 500,
            'created_at'        => Carbon::now()->subDays($hace_dias),
        ]);
    }

    /**
     * Escenario: cuatro débitos, de más viejo a más nuevo, A (1000), B (500), C (300) y D (200).
     * Los "elegidos" de los tests son B, C y D: suman 1000; A queda fuera.
     *
     * @param string $nombre_cliente
     * @return array
     */
    protected function escenario($nombre_cliente)
    {
        list($client, $credit_account) = $this->cliente_con_cuenta($nombre_cliente);

        return [
            'client'         => $client,
            'credit_account' => $credit_account,
            'a'              => $this->debito($client, $credit_account, 1000, 4),
            'b'              => $this->debito($client, $credit_account, 500, 3),
            'c'              => $this->debito($client, $credit_account, 300, 2),
            'd'              => $this->debito($client, $credit_account, 200, 1),
        ];
    }

    /**
     * Payload mínimo de POST api/current-acount/pago sin caja (el foco es la imputación).
     *
     * @param array $e Escenario.
     * @param float $monto
     * @param array $overrides
     * @return array
     */
    protected function payload_pago($e, $monto, $overrides = [])
    {
        /** Cualquier método de pago del fixture sirve; el pago va sin caja. */
        $metodo = CurrentAcountPaymentMethod::first();

        return array_merge([
            'description'                    => 'Pago a varios movimientos (test)',
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
     * Payload de un pago a los débitos B, C y D, con la lista a propósito DESORDENADA (D, B, C) y
     * `to_pay` con el primero de la lista, como lo manda la SPA.
     *
     * @param array $e
     * @param float $monto
     * @return array
     */
    protected function payload_a_bcd($e, $monto)
    {
        return $this->payload_pago($e, $monto, [
            'to_pay'     => ['id' => $e['b']->id],
            'to_pay_ids' => [$e['d']->id, $e['b']->id, $e['c']->id],
        ]);
    }

    /**
     * El último pago registrado del cliente.
     *
     * @param array $e
     * @return \App\Models\CurrentAcount|null
     */
    protected function ultimo_pago($e)
    {
        return CurrentAcount::where('status', 'pago_from_client')
                            ->where('client_id', $e['client']->id)
                            ->latest('id')
                            ->first();
    }

    /**
     * Foto del estado de imputación de los cuatro débitos: [id => "status|pagandose"].
     *
     * @param array $e
     * @return array
     */
    protected function foto_de_estados($e)
    {
        $foto = [];

        foreach (['a', 'b', 'c', 'd'] as $letra) {

            $debito = $e[$letra]->fresh();
            $foto[$debito->id] = $debito->status.'|'.(float) $debito->pagandose;
        }

        return $foto;
    }

    /**
     * (a) El monto es la suma de los tres elegidos: los tres quedan pagados y A, que no se eligió,
     * queda sin tocar aunque sea el más viejo.
     *
     * @group current-acount
     * @test
     */
    public function el_pago_a_tres_movimientos_los_salda_y_deja_el_resto()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios A');

        $this->postJson('api/current-acount/pago', $this->payload_a_bcd($e, 1000))->assertStatus(201);

        foreach (['b', 'c', 'd'] as $letra) {

            $debito = $e[$letra]->fresh();
            $this->assertEquals('pagado', $debito->status, 'El débito '.$letra.' elegido tiene que quedar pagado.');
            $this->assertEquals((float) $debito->debe, (float) $debito->pagandose);
        }

        $a = $e['a']->fresh();
        $this->assertEquals('sin_pagar', $a->status, 'El débito más viejo, no elegido, no se toca.');
        $this->assertEquals(0, (float) $a->pagandose);
    }

    /**
     * (b) Monto menor que la suma: se saldan en orden cronológico (B, C) sin importar el orden de la
     * lista, el último que alcanza (D) queda "pagandose" y A queda intacto. Y (d) recalcular la
     * cuenta entera NO cambia nada: la elección está persistida.
     *
     * @group current-acount
     * @test
     */
    public function con_monto_menor_se_saldan_en_orden_cronologico_y_el_recalculo_lo_conserva()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios B');

        $this->postJson('api/current-acount/pago', $this->payload_a_bcd($e, 900))->assertStatus(201);

        $this->assertEquals('pagado', $e['b']->fresh()->status);
        $this->assertEquals('pagado', $e['c']->fresh()->status);

        $d = $e['d']->fresh();
        $this->assertEquals('pagandose', $d->status, 'El último de los elegidos queda con pago parcial.');
        $this->assertEquals(100, (float) $d->pagandose);

        $a = $e['a']->fresh();
        $this->assertEquals('sin_pagar', $a->status);
        $this->assertEquals(0, (float) $a->pagandose);

        // (d) Recalcular todo: la imputación se rehace leyendo el pago guardado.
        $antes = $this->foto_de_estados($e);

        CurrentAcountHelper::check_saldos_y_pagos($e['credit_account']->id);

        $this->assertEquals(
            $antes,
            $this->foto_de_estados($e),
            'Recalcular la cuenta no puede mover el pago a otros movimientos: la lista tiene que estar persistida.'
        );
    }

    /**
     * (c) Monto mayor que la suma: se saldan los tres elegidos y el sobrante cae en A (FIFO). Y
     * recalcular la cuenta tampoco lo cambia.
     *
     * @group current-acount
     * @test
     */
    public function el_sobrante_cae_fifo_en_las_demas_deudas()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios C');

        $this->postJson('api/current-acount/pago', $this->payload_a_bcd($e, 1300))->assertStatus(201);

        foreach (['b', 'c', 'd'] as $letra) {

            $this->assertEquals('pagado', $e[$letra]->fresh()->status, 'El débito '.$letra.' elegido tiene que quedar pagado.');
        }

        $a = $e['a']->fresh();
        $this->assertEquals('pagandose', $a->status, 'Lo que sobra sigue FIFO sobre las demás deudas.');
        $this->assertEquals(300, (float) $a->pagandose);

        $antes = $this->foto_de_estados($e);

        CurrentAcountHelper::check_saldos_y_pagos($e['credit_account']->id);

        $this->assertEquals($antes, $this->foto_de_estados($e));
    }

    /**
     * Se guarda la lista normalizada (enteros, sin repetidos) y `to_pay_id` queda con el `to_pay`
     * explícito, o sea el primero que manda la SPA.
     *
     * @group current-acount
     * @test
     */
    public function guarda_la_lista_y_to_pay_id_queda_con_el_primero()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios Lista');

        $ids = [(string) $e['b']->id, $e['c']->id, $e['b']->id, $e['d']->id];

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 1000, [
            'to_pay'     => ['id' => $e['b']->id],
            'to_pay_ids' => $ids,
        ]))->assertStatus(201);

        $pago = $this->ultimo_pago($e);

        $this->assertNotNull($pago);
        $this->assertSame([(int) $e['b']->id, (int) $e['c']->id, (int) $e['d']->id], $pago->to_pay_ids);
        $this->assertEquals($e['b']->id, $pago->to_pay_id);
    }

    /**
     * Un id de la lista que ya no está pendiente (un débito ya pagado) se ignora y el pago sigue
     * con los demás.
     *
     * @group current-acount
     * @test
     */
    public function un_id_ya_pagado_o_inexistente_se_ignora()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios Pagado');

        // C ya está saldado de antes.
        $e['c']->update(['status' => 'pagado', 'pagandose' => 300]);

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 700, [
            'to_pay'     => ['id' => $e['b']->id],
            'to_pay_ids' => [$e['b']->id, $e['c']->id, $e['d']->id, 999999999],
        ]))->assertStatus(201);

        $this->assertEquals('pagado', $e['b']->fresh()->status);
        $this->assertEquals('pagado', $e['d']->fresh()->status);
        $this->assertEquals('sin_pagar', $e['a']->fresh()->status, 'Con 700 alcanza justo para B y D.');
    }

    /**
     * (e) Un id de otra cuenta se rechaza con 422, con el mismo mensaje de siempre, y no se crea el
     * pago.
     *
     * @group current-acount
     * @test
     */
    public function un_id_de_otra_cuenta_se_rechaza_sin_crear_el_pago()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios D');

        list($otro_cliente, $otra_cuenta) = $this->cliente_con_cuenta('zz Cliente Varios D Ajeno');
        $ajeno = $this->debito($otro_cliente, $otra_cuenta, 400, 1);

        $pagos_antes = CurrentAcount::where('status', 'pago_from_client')->where('client_id', $e['client']->id)->count();
        $antes = $this->foto_de_estados($e);

        $response = $this->postJson('api/current-acount/pago', $this->payload_pago($e, 800, [
            'to_pay'     => ['id' => $e['b']->id],
            'to_pay_ids' => [$e['b']->id, $ajeno->id],
        ]));

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'El comprobante al que se quiere imputar el pago pertenece a otra cuenta corriente (de otra moneda). '
                        .'Un pago solo puede saldar comprobantes de la cuenta que se está pagando.',
        ]);

        $this->assertEquals(
            $pagos_antes,
            CurrentAcount::where('status', 'pago_from_client')->where('client_id', $e['client']->id)->count(),
            'Un rechazo no puede dejar un pago creado.'
        );
        $this->assertEquals($antes, $this->foto_de_estados($e));
        $this->assertEquals('sin_pagar', $ajeno->fresh()->status);
    }

    /**
     * (f) Sin `to_pay` ni `to_pay_ids` el pago es FIFO de siempre: cae en el más viejo (A).
     *
     * @group current-acount
     * @test
     */
    public function sin_to_pay_ni_lista_el_pago_sigue_fifo()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios E');

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 1200))->assertStatus(201);

        $this->assertEquals('pagado', $e['a']->fresh()->status);

        $b = $e['b']->fresh();
        $this->assertEquals('pagandose', $b->status);
        $this->assertEquals(200, (float) $b->pagandose);

        $this->assertEquals('sin_pagar', $e['c']->fresh()->status);
        $this->assertEquals('sin_pagar', $e['d']->fresh()->status);

        $this->assertNull($this->ultimo_pago($e)->to_pay_ids, 'Un pago común no guarda lista.');
    }

    /**
     * (g) Un `to_pay` único sigue funcionando como siempre, también con una `to_pay_ids` de un solo
     * id (que no es "varios" y no se guarda).
     *
     * @group current-acount
     * @test
     */
    public function el_to_pay_unico_sigue_andando()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios F');

        $this->postJson('api/current-acount/pago', $this->payload_pago($e, 700, [
            'to_pay'     => ['id' => $e['c']->id],
            'to_pay_ids' => [$e['c']->id],
        ]))->assertStatus(201);

        $this->assertEquals('pagado', $e['c']->fresh()->status);

        // El sobrante (400) sigue FIFO: cae en A.
        $a = $e['a']->fresh();
        $this->assertEquals('pagandose', $a->status);
        $this->assertEquals(400, (float) $a->pagandose);

        $this->assertEquals('sin_pagar', $e['b']->fresh()->status);
        $this->assertEquals('sin_pagar', $e['d']->fresh()->status);

        $pago = $this->ultimo_pago($e);
        $this->assertEquals($e['c']->id, $pago->to_pay_id);
        $this->assertNull($pago->to_pay_ids, 'Un solo id no es un pago a varios movimientos.');
    }

    /**
     * (h) Borrar el pago por el endpoint real reabre los tres débitos que saldaba.
     *
     * @group current-acount
     * @test
     */
    public function borrar_el_pago_reabre_los_movimientos()
    {
        $this->entrar();
        $e = $this->escenario('zz Cliente Varios G');

        $this->postJson('api/current-acount/pago', $this->payload_a_bcd($e, 1000))->assertStatus(201);

        $pago = $this->ultimo_pago($e);
        $this->assertNotNull($pago);

        foreach (['b', 'c', 'd'] as $letra) {

            $this->assertEquals('pagado', $e[$letra]->fresh()->status);
        }

        $this->deleteJson('api/current-acount/client/'.$pago->id)->assertStatus(200);

        foreach (['a', 'b', 'c', 'd'] as $letra) {

            $debito = $e[$letra]->fresh();
            $this->assertEquals('sin_pagar', $debito->status, 'El débito '.$letra.' tiene que volver a estar abierto.');
            $this->assertEquals(0, (float) $debito->pagandose);
        }
    }
}
