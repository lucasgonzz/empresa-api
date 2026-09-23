<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — `checkSaldos()` en una sola lectura.
 *
 * Antes de la misión `checkSaldos()` hacía una consulta por movimiento (`getSaldo()` del anterior) y
 * ordenaba solo por `created_at`, mientras `getSaldo()` desempataba por `created_at, id`: con dos
 * movimientos en el mismo segundo el orden del recálculo y el del saldo anterior podían no coincidir.
 * En Fenix, 192 movimientos eran 35 segundos.
 *
 * Lo que se fija acá:
 *   - el resultado es la suma corrida en orden `created_at, id`, con empates, pagos, notas de
 *     crédito y provisorios (que no entran a la cadena ni se tocan);
 *   - la cantidad de consultas NO crece con la cantidad de movimientos.
 *
 * @group cuenta-corriente
 */
class Check_saldos_en_una_lectura_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /**
     * @test
     */
    public function el_resultado_es_la_suma_corrida_en_orden_created_at_id()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Suma corrida');

        $t = Carbon::parse('2026-09-01 10:00:00');

        // Sembrados con los saldos en NULL o mal, y en un orden de inserción que NO es el de la
        // cadena: el id más bajo de los empatados se inserta último.
        $venta_c = $this->movimiento($cuenta, ['detalle' => 'Venta C', 'debe' => 300, 'saldo' => 999, 'created_at' => $t->copy()->addMinutes(5)]);
        $venta_a = $this->movimiento($cuenta, ['detalle' => 'Venta A', 'debe' => 100, 'saldo' => null, 'created_at' => $t->copy()]);
        $empate_1 = $this->movimiento($cuenta, ['detalle' => 'Pago empatado 1', 'haber' => 40, 'saldo' => null, 'created_at' => $t->copy()->addMinutes(2)]);
        $empate_2 = $this->movimiento($cuenta, ['detalle' => 'Venta empatada 2', 'debe' => 1000, 'saldo' => 5, 'created_at' => $t->copy()->addMinutes(2)]);
        $nc = $this->movimiento($cuenta, ['detalle' => 'Nota de credito', 'haber' => 60, 'status' => 'nota_credito', 'saldo' => null, 'created_at' => $t->copy()->addMinutes(3)]);
        $provisorio = $this->movimiento($cuenta, ['detalle' => 'Pago provisorio', 'haber' => 500, 'is_provisorio' => 1, 'saldo' => 123.45, 'created_at' => $t->copy()->addMinutes(4)]);
        $venta_b = $this->movimiento($cuenta, ['detalle' => 'Venta B', 'debe' => 50.55, 'saldo' => null, 'created_at' => $t->copy()->addMinute()]);

        CurrentAcountHelper::checkSaldos($cuenta->id);

        // A (100) -> B (+50,55) -> empate 1 (-40) -> empate 2 (+1000) -> NC (-60) -> C (+300).
        $esperados = [
            $venta_a->id  => 100.00,
            $venta_b->id  => 150.55,
            $empate_1->id => 110.55,
            $empate_2->id => 1110.55,
            $nc->id       => 1050.55,
            $venta_c->id  => 1350.55,
        ];

        foreach ($esperados as $id => $saldo) {
            $this->assertEqualsWithDelta($saldo, (float) CurrentAcount::find($id)->saldo, 0.001, 'Saldo del movimiento '.$id);
        }

        $this->assertEqualsWithDelta(123.45, (float) CurrentAcount::find($provisorio->id)->saldo, 0.001, 'Un provisorio no entra a la cadena ni se toca.');

        $this->assertEqualsWithDelta(1350.55, (float) $cuenta->fresh()->saldo, 0.001);
        $this->assertEqualsWithDelta(1350.55, (float) $cliente->fresh()->saldo_pesos, 0.001);

        $this->assert_cadena_cierra($cuenta->id, 'Suma corrida');
    }

    /**
     * Con `$from_current_acount` el saldo de arranque es el del movimiento anterior al primero que
     * se recalcula, y los anteriores no se tocan.
     *
     * @test
     */
    public function desde_un_movimiento_arranca_del_saldo_del_anterior()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Desde un movimiento');

        $t = Carbon::parse('2026-09-02 10:00:00');

        $primero = $this->movimiento($cuenta, ['detalle' => 'Primero', 'debe' => 100, 'saldo' => 777, 'created_at' => $t->copy()]);
        $segundo = $this->movimiento($cuenta, ['detalle' => 'Segundo', 'debe' => 10, 'saldo' => null, 'created_at' => $t->copy()->addMinute()]);
        $tercero = $this->movimiento($cuenta, ['detalle' => 'Tercero', 'haber' => 5, 'saldo' => null, 'created_at' => $t->copy()->addMinutes(2)]);

        CurrentAcountHelper::checkSaldos($cuenta->id, $primero);

        $this->assertEqualsWithDelta(777, (float) $primero->fresh()->saldo, 0.001, 'El movimiento desde el que se recalcula no se toca (operador >).');
        $this->assertEqualsWithDelta(787, (float) $segundo->fresh()->saldo, 0.001);
        $this->assertEqualsWithDelta(782, (float) $tercero->fresh()->saldo, 0.001);
        $this->assertEqualsWithDelta(782, (float) $cuenta->fresh()->saldo, 0.001);
    }

    /**
     * Un movimiento sin debe ni haber es un ancla: conserva su saldo guardado y el siguiente arranca
     * de ahí, como hizo siempre checkSaldos(). Caso real de Fenix: "A cta saldo inicial ($170.000)".
     * Uno en NULL arranca de 0, igual que antes.
     *
     * @test
     */
    public function un_movimiento_sin_debe_ni_haber_es_un_ancla()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Ancla');

        $t = Carbon::parse('2026-09-03 10:00:00');

        $venta = $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 1000, 'saldo' => null, 'created_at' => $t->copy()]);
        $ancla = $this->movimiento($cuenta, ['detalle' => 'A cta saldo inicial ($170.000)', 'saldo' => 170000, 'created_at' => $t->copy()->addMinute()]);
        $despues = $this->movimiento($cuenta, ['detalle' => 'Pago', 'haber' => 20000, 'saldo' => null, 'created_at' => $t->copy()->addMinutes(2)]);
        $ancla_null = $this->movimiento($cuenta, ['detalle' => 'Nota de debito', 'saldo' => null, 'created_at' => $t->copy()->addMinutes(3)]);
        $ultimo = $this->movimiento($cuenta, ['detalle' => 'Venta', 'debe' => 30, 'saldo' => null, 'created_at' => $t->copy()->addMinutes(4)]);

        CurrentAcountHelper::checkSaldos($cuenta->id);

        $this->assertEqualsWithDelta(1000, (float) $venta->fresh()->saldo, 0.001);
        $this->assertEqualsWithDelta(170000, (float) $ancla->fresh()->saldo, 0.001, 'El ancla conserva su saldo.');
        $this->assertEqualsWithDelta(150000, (float) $despues->fresh()->saldo, 0.001, 'El siguiente arranca del ancla.');
        $this->assertNull($ancla_null->fresh()->saldo, 'Un ancla en NULL no se toca.');
        $this->assertEqualsWithDelta(30, (float) $ultimo->fresh()->saldo, 0.001, 'Después de un ancla en NULL se arranca de 0.');
        $this->assertEqualsWithDelta(30, (float) $cuenta->fresh()->saldo, 0.001);

        $this->assertNull(CurrentAcountHelper::primer_corte_de_la_cadena($cuenta->id), 'Las anclas no son cortes.');
    }

    /**
     * @test
     */
    public function la_cantidad_de_consultas_no_crece_con_los_movimientos()
    {
        $user_id = $this->app['auth']->user()->id;

        list($cliente_corto, $cuenta_corta) = $this->cliente_con_cuenta($user_id, 'Cadena corta');
        list($cliente_largo, $cuenta_larga) = $this->cliente_con_cuenta($user_id, 'Cadena larga');

        $this->sembrar_cadena($cuenta_corta, 10);
        $this->sembrar_cadena($cuenta_larga, 120);

        // Primera pasada: deja las dos cadenas bien. Lo que se mide es la segunda.
        CurrentAcountHelper::checkSaldos($cuenta_corta->id);
        CurrentAcountHelper::checkSaldos($cuenta_larga->id);

        $consultas_corta = $this->contar_consultas(function () use ($cuenta_corta) {
            CurrentAcountHelper::checkSaldos($cuenta_corta->id);
        });

        $consultas_larga = $this->contar_consultas(function () use ($cuenta_larga) {
            CurrentAcountHelper::checkSaldos($cuenta_larga->id);
        });

        $this->assertEquals($consultas_corta, $consultas_larga, 'checkSaldos() hizo '.$consultas_corta.' consultas con 10 movimientos y '.$consultas_larga.' con 120.');

        $this->assertLessThanOrEqual(8, $consultas_larga, 'checkSaldos() sobre una cadena que ya cierra no debería pasar de un puñado de consultas.');

        // Y con un solo movimiento a corregir, escribe esa fila y ninguna más.
        $del_medio = CurrentAcount::where('credit_account_id', $cuenta_larga->id)->orderBy('created_at')->orderBy('id')->skip(60)->first();
        DB::table('current_acounts')->where('id', $del_medio->id)->update(['saldo' => -1]);

        $updates = 0;

        DB::flushQueryLog();
        DB::enableQueryLog();

        CurrentAcountHelper::checkSaldos($cuenta_larga->id);

        foreach (DB::getQueryLog() as $consulta) {
            if (stripos($consulta['query'], 'update `current_acounts`') === 0) {
                $updates++;
            }
        }

        DB::disableQueryLog();

        $this->assertEquals(1, $updates, 'Solo se guarda la fila cuyo saldo cambió.');

        $this->assert_cadena_cierra($cuenta_larga->id, 'Cadena larga');
    }

    /**
     * @param  \App\Models\CreditAccount  $cuenta
     * @param  int  $cantidad
     * @return void
     */
    protected function sembrar_cadena($cuenta, $cantidad)
    {
        $t = Carbon::parse('2026-08-01 09:00:00');

        for ($i = 0; $i < $cantidad; $i++) {

            // Cada tres, dos movimientos en el mismo segundo.
            $cuando = $t->copy()->addMinutes(intdiv($i, 2));

            if ($i % 4 == 3) {
                $this->movimiento($cuenta, ['detalle' => 'Pago '.$i, 'haber' => 37.5, 'created_at' => $cuando]);
            } else {
                $this->movimiento($cuenta, ['detalle' => 'Venta '.$i, 'debe' => 100 + $i, 'created_at' => $cuando]);
            }
        }
    }

    /**
     * @param  callable  $que
     * @return int
     */
    protected function contar_consultas($que)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $que();

        $cantidad = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $cantidad;
    }
}
