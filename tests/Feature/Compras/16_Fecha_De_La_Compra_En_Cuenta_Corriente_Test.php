<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Provider;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CuentaCorriente\ArmaCadenas;

/**
 * Misión compra-fecha-en-cuenta-corriente (9/10/2026): el movimiento que una compra deja en la
 * cuenta corriente del proveedor lleva la FECHA DE LA COMPRA.
 *
 * Desde fecha-creacion-editable (22/9/2026) la compra se guarda con el día que elige el usuario,
 * pero `NewProviderOrderHelper::crear_current_acount()` creaba el movimiento con `now()` y
 * `actualizar_current_acount()` no lo movía si después se cambiaba la fecha. Medido en `demo2`
 * 4.3.8 filmando el T5.20: la compra N° 1 del 21/8 salía "09/10/26" en la cuenta de Distribuidora
 * Central, DESPUÉS de un pago del 31/8, y el saldo de cada fila se calculaba en ese orden.
 *
 * El criterio es el de la venta: `CurrentAcountFromSaleHelper` crea el movimiento con la fecha de
 * la venta y la edición lo recrea con ella, siempre.
 *
 * Todo pasa por la API real (`POST/PUT api/provider-order`, `POST api/current-acount/pago`) sobre
 * un proveedor nuevo, con su cuenta en pesos vacía: el orden de la cadena se puede afirmar entero.
 * La cadena se verifica con la suma de `ArmaCadenas::assert_cadena_cierra()`, hecha en el test, no
 * con el recálculo bajo prueba.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 *
 * @group compras
 * @group cuenta-corriente
 */
class Fecha_De_La_Compra_En_Cuenta_Corriente_Test extends ComprasTestCase
{
    use ArmaCadenas;

    /** Lo que se paga a cuenta en cada escenario. Menor que el total de la compra. */
    const PAGO = 300;

    /** @var \App\Models\Provider */
    protected $proveedor_del_test;

    /** @var \App\Models\CreditAccount La cuenta en pesos del proveedor. */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $user_id = auth()->id();

        $this->proveedor_del_test = Provider::create([
            'name'    => 'zz Fecha de la compra '.uniqid(),
            'user_id' => $user_id,
            'status'  => 'active',
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $this->proveedor_del_test->id, $user_id);

        $this->cuenta = CreditAccount::where('model_name', 'provider')
                                    ->where('model_id', $this->proveedor_del_test->id)
                                    ->where('moneda_id', 1)
                                    ->first();

        $this->assertNotNull($this->cuenta, 'El proveedor del test no quedó con su cuenta corriente en pesos.');
    }

    /**
     * Payload de la compra: el proveedor del test, en pesos, a cuenta corriente, una línea de
     * $1000 y sin tocar stock, precios ni el proveedor del artículo del fixture.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload($overrides = [])
    {
        return $this->payload_compra(array_merge([
            'provider_id'             => $this->proveedor_del_test->id,
            'moneda_id'               => 1,
            'generate_current_acount' => 1,
            'update_stock'            => 0,
            'update_prices'           => 0,
            'articles'                => [$this->item('Pinza', 1000, 1, ['update_provider' => 0])],
        ], $overrides));
    }

    /**
     * @param  array  $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($overrides = [])
    {
        $respuesta = $this->postJson('api/provider-order', $this->payload($overrides));

        $respuesta->assertStatus(201);

        return ProviderOrder::find($respuesta->json('model.id'));
    }

    /**
     * @param  \App\Models\ProviderOrder  $compra
     * @param  array                      $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function editar_compra($compra, $overrides = [])
    {
        $this->putJson('api/provider-order/'.$compra->id, $this->payload($overrides))->assertStatus(200);

        return ProviderOrder::find($compra->id);
    }

    /**
     * Un pago a cuenta al proveedor, con fecha pasada, por el endpoint de la SPA.
     *
     * @param  float   $monto
     * @param  Carbon  $dia
     * @return \App\Models\CurrentAcount
     */
    protected function pagar($monto, $dia)
    {
        $efectivo = CurrentAcountPaymentMethod::where('name', TestingFerreteriaSeeder::PAGO_EFECTIVO)->value('id');

        $respuesta = $this->postJson('api/current-acount/pago', [
            'credit_account_id'              => $this->cuenta->id,
            'model_name'                     => 'provider',
            'model_id'                       => $this->proveedor_del_test->id,
            'haber'                          => $monto,
            'description'                    => 'Pago del test de fecha de la compra',
            'is_provisorio'                  => 0,
            'current_date'                   => 0,
            'created_at'                     => $dia->format('Y-m-d'),
            'current_acount_payment_methods' => [
                ['current_acount_payment_method_id' => $efectivo, 'amount' => $monto, 'caja_id' => null],
            ],
            'to_pay'                         => null,
            'payment_plan_cuota'             => null,
        ]);

        $respuesta->assertStatus(201);

        return CurrentAcount::find($respuesta->json('current_acount.id'));
    }

    /**
     * El único movimiento de la compra (falla si hay más de uno: la edición no puede duplicarlo).
     *
     * @param  \App\Models\ProviderOrder  $compra
     * @return \App\Models\CurrentAcount
     */
    protected function movimiento_de($compra)
    {
        $movimientos = CurrentAcount::where('provider_order_id', $compra->id)->get();

        $this->assertCount(1, $movimientos, 'La compra tiene que dejar exactamente un movimiento en la cuenta corriente.');

        return $movimientos->first();
    }

    /**
     * El movimiento lleva el mismo instante que la compra (día y hora, al segundo).
     *
     * @param  \App\Models\ProviderOrder   $compra
     * @param  \App\Models\CurrentAcount   $movimiento
     * @param  string                      $contexto
     * @return void
     */
    protected function assert_misma_fecha($compra, $movimiento, $contexto)
    {
        $this->assertSame(
            $compra->created_at->format('Y-m-d H:i:s'),
            $movimiento->created_at->format('Y-m-d H:i:s'),
            $contexto.': el movimiento de la cuenta corriente no tiene la fecha de la compra.'
        );
    }

    /**
     * El pago quedó imputado a la compra después del recálculo: el débito de la compra está
     * "pagandose" por el monto del pago (el total la supera) y la imputación existe en `pagado_por`.
     *
     * @param  \App\Models\CurrentAcount  $pago
     * @param  \App\Models\CurrentAcount  $movimiento
     * @param  string                       $contexto
     * @return void
     */
    protected function assert_el_pago_imputa_la_compra($pago, $movimiento, $contexto)
    {
        $debito = CurrentAcount::find($movimiento->id);

        $this->assertSame('pagandose', $debito->status, $contexto.': el pago a cuenta tenía que dejar la compra "pagandose".');

        $this->assertEqualsWithDelta(self::PAGO, (float) $debito->pagandose, 0.01, $contexto.': lo imputado a la compra tiene que ser el pago.');

        $imputado = DB::table('pagado_por')->where('debe_id', $movimiento->id)->where('haber_id', $pago->id)->sum('pagado');

        $this->assertEqualsWithDelta(self::PAGO, (float) $imputado, 0.01, $contexto.': falta la imputación del pago a la compra en pagado_por.');
    }

    /**
     * Los ids de la cuenta en el orden de la cadena (`created_at, id`).
     *
     * @return array<int>
     */
    protected function orden_de_la_cadena()
    {
        return $this->filas_de_la_cadena($this->cuenta->id)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    /**
     * El caso medido en demo2: la compra se carga HOY con una fecha anterior a un pago que ya
     * estaba. Su movimiento tiene que quedar en el día de la compra y ANTES del pago, y el saldo de
     * cada fila calculado en ese orden.
     *
     * @test
     * @return void
     */
    public function una_compra_con_fecha_pasada_entra_a_la_cuenta_ese_dia_y_antes_del_pago_posterior()
    {
        $pago = $this->pagar(self::PAGO, Carbon::now()->subDays(20));

        $dia = Carbon::now()->subDays(30);

        $compra = $this->crear_compra(['created_at' => $dia->format('Y-m-d')]);

        $this->assertSame($dia->format('Y-m-d'), $compra->created_at->format('Y-m-d'), 'Precondición: la compra no quedó en el día elegido.');

        $movimiento = $this->movimiento_de($compra);

        $this->assert_misma_fecha($compra, $movimiento, 'Alta con fecha pasada');

        $this->assertSame(
            [(int) $movimiento->id, (int) $pago->id],
            $this->orden_de_la_cadena(),
            'La compra del día '.$dia->format('d/m').' tiene que quedar ANTES del pago del '.Carbon::now()->subDays(20)->format('d/m').' en la cuenta corriente.'
        );

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Alta con fecha pasada');

        $total = (float) $compra->total;

        $this->assertGreaterThan(self::PAGO, $total, 'Precondición: el total de la compra tiene que superar al pago.');

        $this->assertEqualsWithDelta($total, (float) CurrentAcount::find($movimiento->id)->saldo, 0.01, 'La compra es el primer movimiento: su saldo es su total.');

        $this->assertEqualsWithDelta($total - self::PAGO, $saldo, 0.01);

        $this->assert_el_pago_imputa_la_compra($pago, $movimiento, 'Alta con fecha pasada');
    }

    /**
     * Cambiar la fecha de una compra muda su movimiento al día nuevo (conservando la hora), y la
     * cadena se recalcula en el orden nuevo: la compra estaba DESPUÉS del pago y pasa a estar ANTES.
     *
     * @test
     * @return void
     */
    public function cambiar_la_fecha_de_la_compra_muda_su_movimiento_y_reordena_la_cadena()
    {
        $pago = $this->pagar(self::PAGO, Carbon::now()->subDays(20));

        $compra = $this->crear_compra(['created_at' => Carbon::now()->subDays(10)->format('Y-m-d')]);

        $movimiento = $this->movimiento_de($compra);

        $this->assertSame([(int) $pago->id, (int) $movimiento->id], $this->orden_de_la_cadena(), 'Precondición: la compra del día 10 va después del pago del día 20.');

        $hora_original = $compra->created_at->format('H:i:s');

        $dia_nuevo = Carbon::now()->subDays(30);

        $compra = $this->editar_compra($compra, ['created_at' => $dia_nuevo->format('Y-m-d')]);

        $this->assertSame($dia_nuevo->format('Y-m-d'), $compra->created_at->format('Y-m-d'), 'Precondición: la edición no le cambió el día a la compra.');
        $this->assertSame($hora_original, $compra->created_at->format('H:i:s'), 'Precondición: la edición tenía que conservar la hora de la compra.');

        $movido = $this->movimiento_de($compra);

        $this->assertSame((int) $movimiento->id, (int) $movido->id, 'La edición tiene que mudar el mismo movimiento, no reemplazarlo.');

        $this->assert_misma_fecha($compra, $movido, 'Edición que cambia la fecha');

        $this->assertSame(
            [(int) $movido->id, (int) $pago->id],
            $this->orden_de_la_cadena(),
            'Después de pasar la compra a un día anterior al pago, su movimiento tiene que quedar ANTES del pago.'
        );

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Edición que cambia la fecha');

        $this->assertEqualsWithDelta((float) $compra->total, (float) CurrentAcount::find($movido->id)->saldo, 0.01, 'La compra pasó a ser el primer movimiento: su saldo es su total.');

        $this->assertEqualsWithDelta((float) $compra->total - self::PAGO, $saldo, 0.01);

        $this->assert_el_pago_imputa_la_compra($pago, $movido, 'Edición que cambia la fecha');
    }

    /**
     * El caso de todos los días no cambia: una compra sin fecha queda hoy, y su movimiento también.
     *
     * @test
     * @return void
     */
    public function una_compra_sin_fecha_deja_el_movimiento_con_la_de_hoy()
    {
        $ahora = Carbon::now();

        $compra = $this->crear_compra();

        $movimiento = $this->movimiento_de($compra);

        $this->assert_misma_fecha($compra, $movimiento, 'Alta sin fecha');

        $this->assertSame($ahora->format('Y-m-d'), $movimiento->created_at->format('Y-m-d'), 'Una compra sin fecha tiene que dejar el movimiento con la fecha de hoy.');

        $this->assertLessThanOrEqual(120, abs($ahora->getTimestamp() - $movimiento->created_at->getTimestamp()), 'El movimiento de una compra sin fecha tiene que quedar con la hora de ahora.');

        $this->assert_cadena_cierra($this->cuenta->id, 'Alta sin fecha');
    }

    /**
     * Una compra que ya quedó cargada con el defecto (el movimiento con la fecha del día en que se
     * cargó, no con la de la compra) se acomoda la próxima vez que se guarda, aunque esa edición no
     * mande la fecha: el mismo criterio que la venta, que recrea su movimiento en cada edición.
     *
     * @test
     * @return void
     */
    public function una_compra_ya_cargada_con_el_movimiento_corrido_se_acomoda_al_guardarla()
    {
        $pago = $this->pagar(self::PAGO, Carbon::now()->subDays(20));

        $compra = $this->crear_compra(['created_at' => Carbon::now()->subDays(30)->format('Y-m-d')]);

        $movimiento = $this->movimiento_de($compra);

        /*
         * Lo que dejó la versión con el defecto: el movimiento con la fecha de hoy y la cadena
         * recalculada en ese orden (la compra después del pago).
         */
        DB::table('current_acounts')->where('id', $movimiento->id)->update(['created_at' => Carbon::now()->format('Y-m-d H:i:s')]);

        \App\Http\Controllers\Helpers\CurrentAcountHelper::check_saldos_y_pagos($this->cuenta->id);

        $this->assertSame([(int) $pago->id, (int) $movimiento->id], $this->orden_de_la_cadena(), 'Precondición: el movimiento corrido tenía que quedar después del pago.');

        // Una edición que no habla de la fecha: el payload de siempre, SIN la clave created_at.
        $compra = $this->editar_compra($compra);

        $acomodado = $this->movimiento_de($compra);

        $this->assert_misma_fecha($compra, $acomodado, 'Edición de una compra con el movimiento corrido');

        $this->assertSame(
            [(int) $acomodado->id, (int) $pago->id],
            $this->orden_de_la_cadena(),
            'Al guardar la compra, su movimiento tiene que volver al día de la compra, antes del pago.'
        );

        $this->assert_cadena_cierra($this->cuenta->id, 'Edición de una compra con el movimiento corrido');
    }
}
