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
            $this->assertStringContainsString('Con la cadena cortada: 1', $salida);
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

            $this->assertStringContainsString('Con la cadena cortada: 0', $segunda, 'La segunda corrida no encuentra nada.');
        }

        $this->assertEqualsWithDelta(270, (float) CurrentAcount::find($venta_2->id)->saldo, 0.01);
        $this->assertEqualsWithDelta(270, (float) $cuenta_s->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(270, (float) $cliente_s->fresh()->saldo_pesos, 0.01);

        $this->assertEqualsWithDelta(300, (float) CurrentAcount::find($pago_null->id)->saldo, 0.01);
        $this->assertEqualsWithDelta(350, (float) $cuenta_n->fresh()->saldo, 0.01);
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

        $salida = $this->correr(['--credit_account_id' => $cuenta->id]);

        $this->assertStringContainsString('Con la cadena cortada: 0', $salida);
    }
}
