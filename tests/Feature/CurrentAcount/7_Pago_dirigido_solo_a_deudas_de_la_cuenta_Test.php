<?php

namespace Tests\Feature\CurrentAcount;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MovimientoCaja;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ticket 171 (Unidad 2, prevención): el alta del pago no acepta un `to_pay` / `to_pay_ids` de la MISMA cuenta
 * que no es una deuda (pago, crédito, NC, saldo inicial, provisorio), porque `CreditosHistoricosHelper::plan()`
 * lo rechaza y la cuenta se traba el día que tiene un crédito. Las formas que el alta ya aceptaba (débito propio,
 * débito pagado, id inexistente) o rechazaba (débito de otra cuenta) no cambian.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Pago_dirigido_solo_a_deudas_de_la_cuenta_Test extends TestCase
{
    use DatabaseTransactions;

    /** Mensaje nuevo del alta cuando el destino es de la misma cuenta pero no es una deuda. */
    const TEXTO_NO_ES_DEUDA = 'no es una deuda de esta cuenta';

    protected function movimiento($cuenta, $debe, $haber, $fecha, $extra = [])
    {
        return CurrentAcount::create(array_merge(['credit_account_id' => $cuenta->id,
            'client_id' => $cuenta->model_id, 'user_id' => $cuenta->user_id, 'moneda_id' => $cuenta->moneda_id,
            'debe' => $debe, 'haber' => $haber, 'pagandose' => 0, 'is_provisorio' => 0,
            'detalle' => 'Historial de prueba', 'status' => is_null($haber) ? 'sin_pagar' : 'pago_from_client',
            'created_at' => $fecha, 'updated_at' => $fecha], $extra));
    }

    /**
     * Cuenta A (cliente, pesos) con D1 100 sin pagar, un pago previo P0 de 25 y, si $con_credito, un crédito C de -50
     * (sin crédito el alta no corre `plan()`: es la cuenta donde el hueco guardaba el dato y la trababa después).
     *
     * @param bool $con_credito
     * @return array [cuenta, movimientos por clave]
     */
    protected function cuenta_con_pago_previo($con_credito = true)
    {
        $user = User::findOrFail(500);
        $this->actingAs($user, 'web');
        $client = Client::create(['name' => 'Alta dirigida ticket 171', 'user_id' => $user->id]);
        $cuenta = CreditAccount::create(['model_name' => 'client', 'model_id' => $client->id,
            'moneda_id' => 1, 'user_id' => $user->id, 'saldo' => 0]);
        $m = [];
        $m['D1'] = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        if ($con_credito) {
            $m['C'] = $this->movimiento($cuenta, -50, null, '2026-02-01 10:00:00');
        }
        $m['P0'] = $this->movimiento($cuenta, null, 25, '2026-03-01 10:00:00');
        $cuenta->update(['saldo' => $con_credito ? 25 : 75]);
        return [$cuenta, $m];
    }

    protected function payload_pago($cuenta, $monto, $extra = [])
    {
        return array_merge(['credit_account_id' => $cuenta->id, 'model_name' => 'client', 'model_id' => $cuenta->model_id,
            'is_provisorio' => 0, 'description' => 'Pago dirigido (test)', 'current_date' => 1, 'haber' => $monto,
            'to_pay' => null, 'payment_plan_cuota' => null,
            'current_acount_payment_methods' => [[
                'current_acount_payment_method_id' => CurrentAcountPaymentMethod::where('name', 'Efectivo')->firstOrFail()->id,
                'amount' => $monto, 'moneda_id' => 1]]], $extra);
    }

    /** Foto de la cuenta: movimientos, pivotes y cantidad de pagos, para comparar "no se creó nada". */
    protected function foto($cuenta)
    {
        $ids = CurrentAcount::where('credit_account_id', $cuenta->id)->pluck('id');
        return [CurrentAcount::whereIn('id', $ids)->orderBy('id')->get(['id', 'debe', 'haber', 'status', 'pagandose', 'saldo', 'to_pay_id'])->toArray(),
            DB::table('pagado_por')->whereIn('debe_id', $ids)->orderBy('debe_id')->orderBy('haber_id')->get(['debe_id', 'haber_id', 'pagado'])->map(function ($r) { return (array) $r; })->all(),
            CurrentAcount::where('credit_account_id', $cuenta->id)->where('status', 'pago_from_client')->count(),
            (float) $cuenta->fresh()->saldo];
    }

    /**
     * CA14: un pago dirigido a un pago, un crédito o una lista que incluye un pago se rechaza con 422, sin crear
     * nada (ni pago, ni imputación, ni movimiento de caja) y la cuenta sigue pudiendo chequearse.
     *
     * @test
     */
    public function un_pago_dirigido_a_algo_que_no_es_deuda_de_la_cuenta_se_rechaza_sin_crear_nada()
    {
        foreach (['pago', 'credito', 'lista'] as $caso) {
            // 'pago' y 'lista' en una cuenta sin crédito (hoy el alta los guarda: 201); 'credito' necesita el crédito.
            list($cuenta, $m) = $this->cuenta_con_pago_previo($caso === 'credito');
            $antes = $this->foto($cuenta);
            $cajas_antes = MovimientoCaja::count();
            if ($caso === 'pago') {
                $extra = ['to_pay' => ['id' => $m['P0']->id]];
                $culpable = $m['P0'];
            } elseif ($caso === 'credito') {
                $extra = ['to_pay' => ['id' => $m['C']->id]];
                $culpable = $m['C'];
            } else {
                $extra = ['to_pay' => ['id' => $m['D1']->id], 'to_pay_ids' => [$m['D1']->id, $m['P0']->id]];
                $culpable = $m['P0'];
            }
            $respuesta = $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 30, $extra));
            $respuesta->assertStatus(422);
            $this->assertStringContainsString('#'.$culpable->id, $respuesta->json('message'), $caso);
            $this->assertStringContainsString(self::TEXTO_NO_ES_DEUDA, $respuesta->json('message'), $caso);
            $this->assertEquals($antes, $this->foto($cuenta), $caso.': el rechazo no puede dejar nada creado.');
            $this->assertEquals($cajas_antes, MovimientoCaja::count(), $caso.': ninguna caja con movimiento nuevo.');
            // Segunda parte: aunque la cuenta reciba después un crédito, sigue chequeable (el dato que traba el plan nunca se guardó).
            if ($caso !== 'credito') {
                $this->movimiento($cuenta, -50, null, '2026-04-01 10:00:00');
            }
            $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);
        }
    }

    /**
     * CA15: lo que el alta ya aceptaba o rechazaba no cambia: débito propio pendiente (201), id inexistente (201),
     * débito propio ya pagado (201) y débito de otra cuenta (422 con el texto de siempre, sin crear nada).
     *
     * @test
     */
    public function las_formas_que_el_alta_ya_aceptaba_o_rechazaba_no_cambian()
    {
        // (a) débito propio pendiente: 201 y queda imputado.
        list($cuenta, $m) = $this->cuenta_con_pago_previo();
        $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 30, ['to_pay' => ['id' => $m['D1']->id]]))->assertStatus(201);
        $this->assertGreaterThan(0, (float) $m['D1']->fresh()->pagandose);
        $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);

        // (b) id inexistente: se ignora y el pago entra en orden.
        list($cuenta, $m) = $this->cuenta_con_pago_previo();
        $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 30, ['to_pay' => ['id' => CurrentAcount::max('id') + 1000]]))->assertStatus(201);
        $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);

        // (c) débito propio ya pagado: se acepta.
        list($cuenta, $m) = $this->cuenta_con_pago_previo();
        $m['D1']->update(['status' => 'pagado', 'pagandose' => 100]);
        $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 30, ['to_pay' => ['id' => $m['D1']->id]]))->assertStatus(201);

        // (d) débito de otra cuenta: 422 con el texto de hoy y sin pago creado.
        list($cuenta, $m) = $this->cuenta_con_pago_previo();
        $otro = $this->movimiento(CreditAccount::create(['model_name' => 'client',
            'model_id' => Client::create(['name' => 'Otro cliente ticket 171', 'user_id' => 500])->id,
            'moneda_id' => 1, 'user_id' => 500, 'saldo' => 0]), 100, null, '2026-01-10 10:00:00');
        $antes = $this->foto($cuenta);
        $respuesta = $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 30, ['to_pay' => ['id' => $otro->id]]));
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('pertenece a otra cuenta corriente (de otra moneda)', $respuesta->json('message'));
        $this->assertEquals($antes, $this->foto($cuenta));
    }
}
