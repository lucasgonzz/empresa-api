<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Caja;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\MovimientoCaja;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Un pago de cuenta corriente repartido entre DOS metodos de pago en monedas distintas.
 *
 * El caso real que los origino (21/8/2026): una cuenta corriente en dolares cobrada con efectivo
 * en dolares a una caja en dolares MAS efectivo en pesos a una caja en pesos, dentro del mismo
 * pago. El efectivo en pesos no impactaba en su caja.
 *
 * Los dos metodos comparten el mismo `current_acount_payment_method_id` (los dos son "Efectivo"),
 * que es justamente lo que hace particular al escenario y lo que ninguna prueba cubria.
 *
 * @group current-acount
 */
class Pago_Multimoneda_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** @var \App\Models\Caja */
    protected $caja_usd;

    /** @var \App\Models\Caja */
    protected $caja_ars;

    /** @var \App\Models\User */
    protected $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        // El escenario es el de un comercio con la extencion de ventas en dolares activa.
        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'ventas_en_dolares',
                'name' => 'Ventas en dolares',
            ]);
        }

        $this->usuario->extencions()->syncWithoutDetaching([$extencion->id]);

        // Dos cajas del mismo comercio, una por moneda, las dos abiertas.
        $this->caja_usd = $this->caja_de_moneda('Caja Dolares Test', 2);
        $this->caja_ars = $this->caja_de_moneda('Caja Pesos Test', 1);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Crea (o reusa) una caja de la moneda pedida. Si $abrir es true la deja abierta.
     *
     * @param string $name
     * @param int $moneda_id
     * @param bool $abrir
     * @return \App\Models\Caja
     */
    protected function caja_de_moneda($name, $moneda_id, $abrir = true)
    {
        $caja = Caja::where('name', $name)->first();

        if (is_null($caja)) {

            $num = (int) Caja::where('user_id', $this->usuario->id)->max('num') + 1;

            $caja = Caja::create([
                'name'                  => $name,
                'num'                   => $num,
                'moneda_id'             => $moneda_id,
                'user_id'               => $this->usuario->id,
                'saldo'                 => 0,
                'abierta'               => 0,
                'comision_iva_incluido' => 0,
            ]);
        }

        if ($abrir) {
            $this->asegurar_caja_abierta($caja);
        }

        return $caja;
    }

    /**
     * La cuenta corriente EN DOLARES del cliente del fixture.
     *
     * @param \App\Models\Client $cliente
     * @return \App\Models\CreditAccount
     */
    protected function credit_account_en_dolares($cliente)
    {
        CreditAccountHelper::crear_credit_accounts('client', $cliente->id);

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $cliente->id)
                                        ->where('moneda_id', 2)
                                        ->first();

        $this->assertNotNull($credit_account, 'El cliente no tiene credit_account en dolares (moneda 2).');

        return $credit_account;
    }

    /**
     * Payload de `POST api/current-acount/pago` para el cliente y los metodos de pago dados.
     *
     * @param \App\Models\Client $cliente
     * @param \App\Models\CreditAccount $credit_account
     * @param array $payment_methods
     * @return array
     */
    protected function payload_de_pago($cliente, $credit_account, $payment_methods)
    {
        return [
            'description'                    => 'Pago multimoneda de test',
            'credit_account_id'              => $credit_account->id,
            'is_provisorio'                  => 0,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            // Evita que pago() dispare el recalculo completo de la cuenta corriente.
            'current_date'                   => 1,
            'current_acount_payment_methods' => $payment_methods,
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------------------------------

    /**
     * El caso reportado: un solo pago sobre una cuenta corriente en dolares, con DOS metodos de
     * pago -los dos "Efectivo", o sea el mismo current_acount_payment_method_id- en monedas
     * distintas y con caja destino distinta.
     *
     * Cada caja tiene que recibir el monto EN SU PROPIA MONEDA: 100 dolares la de dolares y
     * 120000 pesos la de pesos (no los 100 dolares cotizados).
     *
     * @group current-acount
     * @test
     */
    public function un_pago_con_efectivo_en_dolares_y_efectivo_en_pesos_impacta_en_las_dos_cajas()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $credit_account = $this->credit_account_en_dolares($cliente);

        $payload = $this->payload_de_pago($cliente, $credit_account, [
            [
                // Efectivo en dolares. Misma moneda que la cuenta corriente: no lleva cotizacion.
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 100,
                'moneda_id'                        => 2,
                'caja_id'                          => $this->caja_usd->id,
            ],
            [
                // Efectivo en PESOS: 120000 ARS cotizados a 100 USD.
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 120000,
                'moneda_id'                        => 1,
                'cotizacion'                       => 1200,
                'amount_cotizado'                  => 100,
                'caja_id'                          => $this->caja_ars->id,
            ],
        ]);

        $antes = $this->max_id_movimiento_caja();

        $response = $this->postJson('api/current-acount/pago', $payload);

        $response->assertStatus(201);

        $pago_id = $this->extraer_id_de_respuesta(
            $response,
            'api/current-acount/pago',
            null,
            ['current_acount', 'model']
        );

        $this->cobros_cc_creados_por_escenarios[] = $pago_id;
        $this->registrar_movimientos_caja_nuevos($antes);

        $pago = CurrentAcount::find($pago_id);

        // Los dos metodos quedaron guardados aunque compartan el mismo id de metodo de pago.
        $this->assertCount(
            2,
            $pago->current_acount_payment_methods,
            'El pago no guardo los dos metodos de pago.'
        );

        $movimiento_usd = MovimientoCaja::where('caja_id', $this->caja_usd->id)
                                        ->where('id', '>', $antes)
                                        ->first();

        $movimiento_ars = MovimientoCaja::where('caja_id', $this->caja_ars->id)
                                        ->where('id', '>', $antes)
                                        ->first();

        $this->assertNotNull(
            $movimiento_usd,
            'El efectivo en dolares no genero movimiento en la caja de dolares.'
        );

        $this->assertNotNull(
            $movimiento_ars,
            'El efectivo en PESOS no genero movimiento en la caja de pesos. Es el bug reportado el 21/8/2026.'
        );

        $this->assertEquals(100, (float) $movimiento_usd->ingreso);
        $this->assertEquals(120000, (float) $movimiento_ars->ingreso);
    }

    /**
     * Si una de las cajas destino NUNCA se abrio, el pago se rechaza entero con 422 y un mensaje
     * que nombra la caja, ANTES de crear nada.
     *
     * Antes de esto el flujo se cortaba a la mitad: el primer metodo ya habia impactado, el pago ya
     * estaba creado, y reventaba al buscar la apertura de la segunda caja. Quedaba un pago sin
     * saldo ni imputacion y una caja sin la plata.
     *
     * @group current-acount
     * @test
     */
    public function una_caja_destino_sin_apertura_rechaza_el_pago_entero_y_no_crea_nada()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $credit_account = $this->credit_account_en_dolares($cliente);

        // Caja de pesos que NUNCA se abrio.
        $caja_sin_apertura = $this->caja_de_moneda('Caja Pesos Nunca Abierta Test', 1, false);

        $pagos_antes = CurrentAcount::where('credit_account_id', $credit_account->id)->count();
        $antes = $this->max_id_movimiento_caja();

        $payload = $this->payload_de_pago($cliente, $credit_account, [
            [
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 100,
                'moneda_id'                        => 2,
                'caja_id'                          => $this->caja_usd->id,
            ],
            [
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 120000,
                'moneda_id'                        => 1,
                'cotizacion'                       => 1200,
                'amount_cotizado'                  => 100,
                'caja_id'                          => $caja_sin_apertura->id,
            ],
        ]);

        $response = $this->postJson('api/current-acount/pago', $payload);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'Caja Pesos Nunca Abierta Test',
            $response->json('message'),
            'El mensaje de error no nombra la caja sin apertura.'
        );

        // Nada quedo a medias: ni el pago ni el movimiento de la caja que si tenia apertura.
        $this->assertEquals(
            $pagos_antes,
            CurrentAcount::where('credit_account_id', $credit_account->id)->count(),
            'Se creo el pago a pesar de que una caja no tenia apertura.'
        );

        $this->assertEquals(
            0,
            MovimientoCaja::where('id', '>', $antes)->count(),
            'Se creo algun movimiento de caja a pesar de que el pago se rechazo.'
        );
    }

    /**
     * 🔴 Una caja CERRADA pero que ya tuvo aperturas tiene que seguir aceptando el cobro.
     *
     * Es el caso del comercio que abre la caja a la manana y la cierra a la noche: el movimiento se
     * cuelga de la ultima apertura y el pago entra sin problema. Asi funciona hoy en todos los
     * flujos (las ventas ni siquiera validan esto), y medido el 21/8/2026 devuelve 201.
     *
     * Este test existe porque la primera version de la validacion miraba `cajas.abierta` en vez de
     * la existencia de aperturas, y le habria devuelto un 422 a todo el que cobra despues del
     * cierre. Si alguien vuelve a confundir "cerrada" con "sin apertura", este test se pone rojo.
     *
     * @group current-acount
     * @test
     */
    public function una_caja_cerrada_con_apertura_previa_sigue_aceptando_el_cobro()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $credit_account = $this->credit_account_en_dolares($cliente);

        // Se abre (queda con AperturaCaja) y despues se cierra, como al final de la jornada.
        $caja = $this->caja_de_moneda('Caja Pesos Cerrada De Noche Test', 1);
        $caja->abierta = 0;
        $caja->save();

        $antes = $this->max_id_movimiento_caja();

        $payload = $this->payload_de_pago($cliente, $credit_account, [
            [
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 50000,
                'moneda_id'                        => 1,
                'cotizacion'                       => 1200,
                'amount_cotizado'                  => 41.67,
                'caja_id'                          => $caja->id,
            ],
        ]);

        $response = $this->postJson('api/current-acount/pago', $payload);

        $response->assertStatus(201);

        $pago_id = $this->extraer_id_de_respuesta(
            $response,
            'api/current-acount/pago',
            null,
            ['current_acount', 'model']
        );

        $this->cobros_cc_creados_por_escenarios[] = $pago_id;
        $this->registrar_movimientos_caja_nuevos($antes);

        $this->assertNotNull(
            MovimientoCaja::where('caja_id', $caja->id)->where('id', '>', $antes)->first(),
            'Una caja cerrada con apertura previa dejo de registrar el movimiento.'
        );
    }

    /**
     * Un metodo de pago sin caja destino no impacta en ninguna caja (sigue siendo valido: un cheque
     * no toca caja), pero NO puede arrastrar al resto: el metodo que si tiene caja impacta igual.
     *
     * @group current-acount
     * @test
     */
    public function un_metodo_sin_caja_no_impide_que_el_otro_impacte()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $credit_account = $this->credit_account_en_dolares($cliente);

        $antes = $this->max_id_movimiento_caja();

        $payload = $this->payload_de_pago($cliente, $credit_account, [
            [
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 100,
                'moneda_id'                        => 2,
                'caja_id'                          => $this->caja_usd->id,
            ],
            [
                // Sin caja destino (0 es lo que manda el formulario cuando no hay caja elegida).
                'current_acount_payment_method_id' => $metodo_efectivo->id,
                'amount'                           => 120000,
                'moneda_id'                        => 1,
                'cotizacion'                       => 1200,
                'amount_cotizado'                  => 100,
                'caja_id'                          => 0,
            ],
        ]);

        $response = $this->postJson('api/current-acount/pago', $payload);

        $response->assertStatus(201);

        $pago_id = $this->extraer_id_de_respuesta(
            $response,
            'api/current-acount/pago',
            null,
            ['current_acount', 'model']
        );

        $this->cobros_cc_creados_por_escenarios[] = $pago_id;
        $this->registrar_movimientos_caja_nuevos($antes);

        $movimiento_usd = MovimientoCaja::where('caja_id', $this->caja_usd->id)
                                        ->where('id', '>', $antes)
                                        ->first();

        $this->assertNotNull(
            $movimiento_usd,
            'El metodo sin caja se llevo puesto al que si tenia caja.'
        );

        $this->assertEquals(100, (float) $movimiento_usd->ingreso);
    }
}
