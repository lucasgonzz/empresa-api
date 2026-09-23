<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026) — toda escritura deja la cadena entera
 * cerrando.
 *
 * Antes de la misión varios caminos recalculaban "solo desde acá" o no recalculaban:
 * `CurrentAcountFromSaleHelper::es_el_ultimo_movimiento()` comparaba por día (una venta con hora
 * anterior a otros movimientos del mismo día se tomaba como la última), `checkCurrentAcountSaldo()`
 * miraba solo las últimas 3 filas, y el cobro con la fecha de hoy no recalculaba nada de lo que
 * viniera después. Hay cortes vivos en producción que no son de la carrera (Servian, Masquito,
 * 3D Tisk).
 *
 * Cada test arma una cadena, hace UNA operación por el camino real y verifica que la cadena
 * ENTERA cierre, con una suma hecha acá (`ArmaCadenas::assert_cadena_cierra()`), no con el helper
 * bajo prueba.
 *
 * @group cuenta-corriente
 */
class Recalculo_completo_en_cada_escritura_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /** Efectivo, del catálogo global de métodos de pago. */
    const METODO_EFECTIVO = 3;

    /** @var \App\Models\Client */
    protected $cliente;

    /** @var \App\Models\CreditAccount */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        list($this->cliente, $this->cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Recalculo completo');
    }

    /**
     * Una venta que entra a la cuenta por el camino real (CurrentAcountFromSaleHelper).
     *
     * @param  float   $total
     * @param  Carbon  $cuando
     * @return Sale
     */
    protected function venta_en_la_cuenta($total, $cuando)
    {
        $venta = $this->venta($this->cliente, $total, $cuando);

        SaleHelper::create_current_acount($venta);

        return $venta;
    }

    /**
     * Payload de `POST api/current-acount/pago`, igual al que manda la SPA.
     *
     * @param  float        $monto
     * @param  string|null  $fecha  Y-m-d para un pago con fecha pasada; null = fecha de hoy.
     * @return array
     */
    protected function pago($monto, $fecha = null)
    {
        return [
            'credit_account_id'              => $this->cuenta->id,
            'model_name'                     => 'client',
            'model_id'                       => $this->cliente->id,
            'haber'                          => $monto,
            'description'                    => 'Pago del test de recalculo',
            'is_provisorio'                  => 0,
            'current_date'                   => is_null($fecha) ? 1 : 0,
            'created_at'                     => $fecha,
            'current_acount_payment_methods' => [
                ['current_acount_payment_method_id' => self::METODO_EFECTIVO, 'amount' => $monto, 'caja_id' => null],
            ],
        ];
    }

    /**
     * El caso que el `whereDate` dejaba pasar: una venta con hora ANTERIOR a otros movimientos del
     * MISMO día, en una cuenta con más de tres movimientos después de ella.
     *
     * @test
     */
    public function venta_con_hora_anterior_a_otros_movimientos_del_mismo_dia()
    {
        $dia = Carbon::parse('2026-09-10');

        foreach ([10, 11, 12, 13, 14] as $hora) {
            $this->venta_en_la_cuenta(100, $dia->copy()->setTime($hora, 0));
        }

        $this->assert_cadena_cierra($this->cuenta->id, 'Antes de la venta del medio');

        $this->venta_en_la_cuenta(55.50, $dia->copy()->setTime(10, 30));

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Venta con hora anterior del mismo día');

        $this->assertEqualsWithDelta(555.50, $saldo, 0.01);
    }

    /**
     * @test
     */
    public function pago_con_fecha_pasada()
    {
        $this->venta_en_la_cuenta(1000, Carbon::now()->subDays(10));
        $this->venta_en_la_cuenta(500, Carbon::now()->subDays(2));

        $this->postJson('api/current-acount/pago', $this->pago(300, Carbon::now()->subDays(5)->format('Y-m-d')))->assertStatus(201);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Pago con fecha pasada');

        $this->assertEqualsWithDelta(1200, $saldo, 0.01);
    }

    /**
     * El cobro de todos los días con una venta que tiene fecha POSTERIOR a hoy (la fecha de las
     * ventas es editable): antes el saldo de esa venta quedaba sin el pago.
     *
     * @test
     */
    public function pago_con_fecha_actual_y_un_movimiento_posterior()
    {
        $this->venta_en_la_cuenta(1000, Carbon::now()->subDays(3));
        $this->venta_en_la_cuenta(400, Carbon::now()->addDays(2));

        $this->postJson('api/current-acount/pago', $this->pago(250))->assertStatus(201);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Pago con fecha actual');

        $this->assertEqualsWithDelta(1150, $saldo, 0.01);
    }

    /**
     * @test
     */
    public function edicion_de_una_venta_del_medio()
    {
        $dia = Carbon::parse('2026-09-11');

        $this->venta_en_la_cuenta(100, $dia->copy()->setTime(9, 0));
        $del_medio = $this->venta_en_la_cuenta(200, $dia->copy()->setTime(10, 0));
        $this->venta_en_la_cuenta(300, $dia->copy()->setTime(11, 0));
        $this->movimiento($this->cuenta, ['detalle' => 'Pago sembrado', 'haber' => 50, 'created_at' => $dia->copy()->setTime(12, 0)]);
        $this->venta_en_la_cuenta(400, $dia->copy()->setTime(13, 0));

        // Lo que hace la edición: cambia el total y rehace el movimiento.
        $del_medio->total = 275.25;
        $del_medio->save();

        SaleHelper::updateCurrentAcountsAndCommissions($del_medio->fresh());

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Edición de una venta del medio');

        $this->assertEqualsWithDelta(1025.25, $saldo, 0.01);
    }

    /**
     * Una edición que le cambia el cliente a la venta: la cuenta de la que sale el movimiento
     * también tiene que quedar cerrando (antes nadie la recalculaba).
     *
     * @test
     */
    public function edicion_que_cambia_el_cliente_recalcula_la_cuenta_vieja()
    {
        $dia = Carbon::parse('2026-09-12');

        $primera = $this->venta_en_la_cuenta(100, $dia->copy()->setTime(9, 0));
        $this->venta_en_la_cuenta(200, $dia->copy()->setTime(10, 0));
        $this->venta_en_la_cuenta(300, $dia->copy()->setTime(11, 0));

        list($otro_cliente, $otra_cuenta) = $this->cliente_con_cuenta($this->app['auth']->user()->id, 'Otro cliente');

        $primera->client_id = $otro_cliente->id;
        $primera->save();

        SaleHelper::updateCurrentAcountsAndCommissions($primera->fresh());

        $this->assertEqualsWithDelta(500, $this->assert_cadena_cierra($this->cuenta->id, 'Cuenta de la que salió la venta'), 0.01);
        $this->assertEqualsWithDelta(100, $this->assert_cadena_cierra($otra_cuenta->id, 'Cuenta a la que entró la venta'), 0.01);
    }

    /**
     * @test
     */
    public function borrado_de_una_venta_del_medio()
    {
        $dia = Carbon::parse('2026-09-13');

        $this->venta_en_la_cuenta(100, $dia->copy()->setTime(9, 0));
        $del_medio = $this->venta_en_la_cuenta(200, $dia->copy()->setTime(10, 0));
        $this->venta_en_la_cuenta(300, $dia->copy()->setTime(11, 0));
        $this->venta_en_la_cuenta(400, $dia->copy()->setTime(12, 0));

        $this->deleteJson('api/sale/'.$del_medio->id)->assertStatus(200);

        $this->assertEquals(0, CurrentAcount::where('sale_id', $del_medio->id)->count());

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Borrado de una venta del medio');

        $this->assertEqualsWithDelta(800, $saldo, 0.01);
    }

    /**
     * @test
     */
    public function borrado_de_un_pago_del_medio()
    {
        $this->venta_en_la_cuenta(1000, Carbon::now()->subDays(10));

        $this->postJson('api/current-acount/pago', $this->pago(300, Carbon::now()->subDays(8)->format('Y-m-d')))->assertStatus(201);

        $this->venta_en_la_cuenta(500, Carbon::now()->subDays(6));

        $this->postJson('api/current-acount/pago', $this->pago(100, Carbon::now()->subDays(4)->format('Y-m-d')))->assertStatus(201);

        $pago_del_medio = CurrentAcount::where('credit_account_id', $this->cuenta->id)
                                        ->where('haber', 300)
                                        ->first();

        $this->deleteJson('api/current-acount/client/'.$pago_del_medio->id)->assertStatus(200);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Borrado de un pago del medio');

        $this->assertEqualsWithDelta(1400, $saldo, 0.01);
    }

    /**
     * Nota de débito y nota de crédito de monto libre, con una venta de fecha posterior: las dos
     * terminan en un recálculo de la cadena entera.
     *
     * @test
     */
    public function nota_de_debito_y_nota_de_credito_con_un_movimiento_posterior()
    {
        $this->venta_en_la_cuenta(1000, Carbon::now()->subDays(3));
        $this->venta_en_la_cuenta(200, Carbon::now()->addDay());

        $this->postJson('api/current-acount/nota-debito', [
            'credit_account_id' => $this->cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $this->cliente->id,
            'debe'              => 75,
            'description'       => 'ND del test',
        ])->assertStatus(201);

        $this->assert_cadena_cierra($this->cuenta->id, 'Nota de débito');

        $this->postJson('api/current-acount/nota-credito', [
            'credit_account_id' => $this->cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $this->cliente->id,
            'form'              => ['nota_credito' => 125, 'description' => 'NC del test'],
        ])->assertStatus(201);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Nota de crédito');

        $this->assertEqualsWithDelta(1150, $saldo, 0.01);
    }
}
