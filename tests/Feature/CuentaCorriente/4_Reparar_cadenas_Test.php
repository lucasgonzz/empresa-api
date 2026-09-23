<?php

namespace Tests\Feature\CuentaCorriente;

use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — `cuenta_corriente:reparar_cadenas`.
 *
 * Se siembran a propósito los dos cortes que había en producción y que no son de la carrera:
 *   - el de Servian: un pago cargado con fecha ANTERIOR a una venta que ya existía, y la venta sin
 *     recalcular (su saldo sigue sin descontar el pago);
 *   - el de 3D Tisk: un pago no provisorio con el saldo en NULL.
 * Sin `--aplicar` el comando los lista y no escribe nada; con `--aplicar` los repara; una segunda
 * corrida no encuentra nada.
 *
 * @group cuenta-corriente
 */
class Reparar_cadenas_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /**
     * La cuenta de Servian, reducida: venta 100 el 1/6, venta 250 el 4/6 (saldo 350), y después se
     * carga un pago de 80 con fecha 2/6, ENTRE las dos ventas, sin recalcular la venta posterior.
     * La cadena queda 100, 20, 350 (la venta tendría que dar 270).
     *
     * @return array
     */
    protected function cuenta_servian()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Servian');

        $this->movimiento($cuenta, ['detalle' => 'Venta 1', 'debe' => 100, 'saldo' => 100, 'created_at' => Carbon::parse('2026-06-01 10:00:00')]);
        $venta_2 = $this->movimiento($cuenta, ['detalle' => 'Venta 2', 'debe' => 250, 'saldo' => 350, 'created_at' => Carbon::parse('2026-06-04 10:00:00')]);
        $this->movimiento($cuenta, ['detalle' => 'Pago con fecha pasada', 'haber' => 80, 'saldo' => 20, 'created_at' => Carbon::parse('2026-06-02 10:00:00')]);

        DB::table('credit_accounts')->where('id', $cuenta->id)->update(['saldo' => 350]);

        return [$cliente, $cuenta, $venta_2];
    }

    /**
     * La cuenta de 3D Tisk: un pago con el saldo en NULL en el medio.
     *
     * @return array
     */
    protected function cuenta_saldo_null()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, '3D Tisk');

        $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 500, 'saldo' => 500, 'created_at' => Carbon::parse('2026-07-01 10:00:00')]);
        $pago = $this->movimiento($cuenta, ['detalle' => 'Pago N°116', 'haber' => 200, 'saldo' => null, 'created_at' => Carbon::parse('2026-07-02 10:00:00')]);
        $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 50, 'saldo' => 350, 'created_at' => Carbon::parse('2026-07-03 10:00:00')]);

        return [$cliente, $cuenta, $pago];
    }

    /**
     * @param  array  $opciones
     * @return string
     */
    protected function correr($opciones)
    {
        Artisan::call('cuenta_corriente:reparar_cadenas', $opciones);

        return Artisan::output();
    }

    /**
     * @test
     */
    public function sin_aplicar_lista_y_no_escribe_con_aplicar_repara_y_la_segunda_corrida_da_cero()
    {
        list($cliente_s, $cuenta_s, $venta_2) = $this->cuenta_servian();
        list($cliente_n, $cuenta_n, $pago_null) = $this->cuenta_saldo_null();

        foreach ([$cuenta_s, $cuenta_n] as $cuenta) {

            $foto = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get(['id', 'saldo', 'status'])->toJson();

            $salida = $this->correr(['--credit_account_id' => $cuenta->id]);

            $this->assertStringContainsString('Cuenta '.$cuenta->id.' ', $salida);
            $this->assertStringContainsString('A reparar: 1', $salida);
            $this->assertStringContainsString('no se escribió nada', $salida);

            $this->assertEquals($foto, DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get(['id', 'saldo', 'status'])->toJson(), 'Sin --aplicar no se toca nada.');
        }

        $this->assertStringContainsString('movimiento '.$venta_2->id, $this->correr(['--credit_account_id' => $cuenta_s->id]), 'El primer corte de Servian es la venta posterior al pago.');
        $this->assertStringContainsString('movimiento '.$pago_null->id, $this->correr(['--credit_account_id' => $cuenta_n->id]), 'El primer corte de 3D Tisk es el pago con saldo NULL.');

        foreach ([$cuenta_s, $cuenta_n] as $cuenta) {

            $salida = $this->correr(['--credit_account_id' => $cuenta->id, '--aplicar' => true]);

            $this->assertStringContainsString('Reparadas: 1', $salida);

            $this->assert_cadena_cierra($cuenta->id, 'Después de reparar');

            $segunda = $this->correr(['--credit_account_id' => $cuenta->id, '--aplicar' => true]);

            $this->assertStringContainsString('A reparar: 0', $segunda, 'La segunda corrida no encuentra nada.');
        }

        $this->assertEqualsWithDelta(270, (float) CurrentAcount::find($venta_2->id)->saldo, 0.01);
        $this->assertEqualsWithDelta(270, (float) $cuenta_s->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(270, (float) $cliente_s->fresh()->saldo_pesos, 0.01);

        $this->assertEqualsWithDelta(300, (float) CurrentAcount::find($pago_null->id)->saldo, 0.01);
        $this->assertEqualsWithDelta(350, (float) $cuenta_n->fresh()->saldo, 0.01);
    }

    /**
     * La cadena cierra pero el saldo guardado de la cuenta, o el del dueño, no es el del último
     * movimiento: también se repara.
     *
     * @test
     */
    public function una_cadena_que_cierra_con_el_saldo_final_descuadrado_se_repara()
    {
        $user_id = $this->app['auth']->user()->id;

        list($cliente_c, $cuenta_c) = $this->cliente_con_cuenta($user_id, 'Cuenta descuadrada');
        list($cliente_d, $cuenta_d) = $this->cliente_con_cuenta($user_id, 'Dueño descuadrado');

        foreach ([$cuenta_c, $cuenta_d] as $cuenta) {
            $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 400, 'saldo' => 400, 'created_at' => Carbon::parse('2026-08-05 10:00:00')]);
            $this->movimiento($cuenta, ['detalle' => 'Pago', 'haber' => 150, 'saldo' => 250, 'created_at' => Carbon::parse('2026-08-06 10:00:00')]);
        }

        DB::table('credit_accounts')->where('id', $cuenta_c->id)->update(['saldo' => 400]);
        DB::table('clients')->where('id', $cliente_c->id)->update(['saldo_pesos' => 250]);

        DB::table('credit_accounts')->where('id', $cuenta_d->id)->update(['saldo' => 250]);
        DB::table('clients')->where('id', $cliente_d->id)->update(['saldo_pesos' => 999]);

        $this->assertStringContainsString('la cadena cierra en 250', $this->correr(['--credit_account_id' => $cuenta_c->id]));
        $this->assertStringContainsString('el dueño 999', $this->correr(['--credit_account_id' => $cuenta_d->id]));

        foreach ([$cuenta_c, $cuenta_d] as $cuenta) {
            $this->assertStringContainsString('Reparadas: 1', $this->correr(['--credit_account_id' => $cuenta->id, '--aplicar' => true]));
            $this->assertStringContainsString('A reparar: 0', $this->correr(['--credit_account_id' => $cuenta->id]));
        }

        $this->assertEqualsWithDelta(250, (float) $cuenta_c->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(250, (float) $cliente_d->fresh()->saldo_pesos, 0.01);

        // Una cuenta SIN movimientos y con saldo no se toca: no hay cadena contra la cual comparar.
        list($cliente_v, $cuenta_v) = $this->cliente_con_cuenta($user_id, 'Sin movimientos');

        DB::table('credit_accounts')->where('id', $cuenta_v->id)->update(['saldo' => 1234.5]);

        $this->assertStringContainsString('A reparar: 0', $this->correr(['--credit_account_id' => $cuenta_v->id, '--aplicar' => true]));
        $this->assertEqualsWithDelta(1234.5, (float) $cuenta_v->fresh()->saldo, 0.01);
    }

    /**
     * Con `{user_id}` solo se miran las cuentas de los clientes de ese comercio: en una base
     * compartida, las de otro comercio no se tocan.
     *
     * @test
     */
    public function con_user_id_solo_mira_las_cuentas_de_ese_comercio()
    {
        $user_id = $this->app['auth']->user()->id;

        list($propio, $cuenta_propia) = $this->cliente_con_cuenta($user_id, 'Del comercio');
        list($ajeno, $cuenta_ajena) = $this->cliente_con_cuenta($user_id, 'De otro comercio');

        // Otro comercio de la misma base (clients.user_id tiene foreign key a users). Se crea adentro
        // de la transacción del test, así que no queda.
        $otro_user_id = DB::table('users')->insertGetId([
            'name'      => 'zz Otro comercio',
            'email'     => 'zz-otro-comercio-'.uniqid().'@test.local',
            'password'  => 'x',
            'status'    => 'commerce',
        ]);

        DB::table('clients')->where('id', $ajeno->id)->update(['user_id' => $otro_user_id]);

        foreach ([$cuenta_propia, $cuenta_ajena] as $cuenta) {
            $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 100, 'saldo' => 7, 'created_at' => Carbon::parse('2026-08-07 10:00:00')]);
        }

        $salida = $this->correr(['user_id' => (string) $user_id, '--aplicar' => true]);

        $this->assertStringContainsString('Cuenta '.$cuenta_propia->id.' ', $salida);
        $this->assertStringNotContainsString('Cuenta '.$cuenta_ajena->id.' ', $salida);

        $this->assert_cadena_cierra($cuenta_propia->id, 'Cuenta del comercio');

        $this->assertEqualsWithDelta(7, (float) DB::table('current_acounts')->where('credit_account_id', $cuenta_ajena->id)->value('saldo'), 0.01, 'La cuenta de otro comercio no se toca.');

        $this->assertStringContainsString('Cuenta '.$cuenta_ajena->id.' ', $this->correr(['user_id' => (string) $otro_user_id]));

        // Un user_id que no es un número no recorre toda la base: no revisa nada y sale en 0.
        $this->assertEquals(0, Artisan::call('cuenta_corriente:reparar_cadenas', ['user_id' => 'abc', '--aplicar' => true]));
        $this->assertStringContainsString('user_id inválido', Artisan::output());
    }

    /**
     * Por defecto solo recalcula los saldos: NO re-imputa los pagos (en masa, en el despliegue,
     * re-imputar puede desliquidar comisiones). Con `--con-pagos`, sí.
     *
     * @test
     */
    public function por_defecto_no_reimputa_y_con_pagos_si()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Sin reimputar');

        $venta = $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 100, 'saldo' => 100, 'status' => 'sin_pagar', 'created_at' => Carbon::parse('2026-08-08 10:00:00')]);
        $this->movimiento($cuenta, ['detalle' => 'Pago', 'haber' => 100, 'saldo' => 55, 'created_at' => Carbon::parse('2026-08-09 10:00:00')]);

        $this->correr(['--credit_account_id' => $cuenta->id, '--aplicar' => true]);

        $this->assert_cadena_cierra($cuenta->id, 'Sin --con-pagos');
        $this->assertEquals('sin_pagar', $venta->fresh()->status, 'Sin --con-pagos no se re-imputa.');
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $venta->id)->count());

        DB::table('current_acounts')->where('id', $venta->id)->update(['saldo' => 1]);

        $this->correr(['--credit_account_id' => $cuenta->id, '--aplicar' => true, '--con-pagos' => true]);

        $this->assert_cadena_cierra($cuenta->id, 'Con --con-pagos');
        $this->assertEquals('pagado', $venta->fresh()->status, 'Con --con-pagos se re-imputa.');
    }

    /**
     * Si una cuenta no se puede reparar, el comando la informa y sale igual con 0: con otro código
     * el despliegue del admin frena antes de rotar el frente.
     *
     * @test
     */
    public function una_cuenta_que_falla_no_cambia_el_exit_code()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Falla');

        $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 100, 'saldo' => 3, 'created_at' => Carbon::parse('2026-08-10 10:00:00')]);

        $dispatcher = CurrentAcount::getEventDispatcher();

        CurrentAcount::saving(function () {
            throw new \RuntimeException('falla forzada por el test');
        });

        try {
            $codigo = Artisan::call('cuenta_corriente:reparar_cadenas', ['--credit_account_id' => $cuenta->id, '--aplicar' => true]);
            $salida = Artisan::output();
        } finally {
            $dispatcher->forget('eloquent.saving: '.CurrentAcount::class);
        }

        $this->assertEquals(0, $codigo);
        $this->assertStringContainsString('Sin reparar: 1 (cuentas '.$cuenta->id.')', $salida);
        $this->assertStringContainsString('falla forzada por el test', $salida);
    }

    /**
     * Una cuenta que cierra no se lista, y la tolerancia de 0,05 no confunde un redondeo con un
     * corte.
     *
     * @test
     */
    public function una_cadena_que_cierra_no_aparece()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Cadena sana');

        $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 100.10, 'saldo' => 100.10, 'created_at' => Carbon::parse('2026-08-01 10:00:00')]);
        $this->movimiento($cuenta, ['detalle' => 'Pago', 'haber' => 50.05, 'saldo' => 50.06, 'created_at' => Carbon::parse('2026-08-02 10:00:00')]);

        // Y el saldo final guardado, en la cuenta y en el dueño, es el de la cadena.
        DB::table('credit_accounts')->where('id', $cuenta->id)->update(['saldo' => 50.06]);
        DB::table('clients')->where('id', $cliente->id)->update(['saldo_pesos' => 50.06]);

        $salida = $this->correr(['--credit_account_id' => $cuenta->id]);

        $this->assertStringContainsString('A reparar: 0', $salida);
    }
}
