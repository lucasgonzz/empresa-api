<?php

namespace Tests\Feature\LimiteCredito;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Límite de crédito por cliente, avisado al vender (misión 160).
 *
 * Protege `LimiteCreditoHelper::validar_venta_nueva()` y su punto de invocación en
 * `SaleController::store()`: que una venta que haría superar el límite de la cuenta corriente
 * del cliente vuelva 422 con los tres números (saldo actual, total de la venta, límite), que el
 * chequeo se saltee para ventas de mostrador / `to_check` / `omitir_en_cuenta_corriente`, que el
 * límite sea por moneda, y que un cliente SIN límite se comporte exactamente igual que antes de
 * esta misión.
 *
 * Los saldos de partida se leen con `CurrentAcountHelper::getSaldo()` (el mismo que usa el
 * helper bajo prueba) en vez de asumir 0: la base del slot puede traer movimientos previos de
 * `CLIENTE_CC` de otras suites, y las aserciones tienen que valer sin importar ese punto de
 * partida.
 */
class Limite_de_credito_en_venta_Test extends EmpresaTestCase
{
    /**
     * Snapshots de `limite_credito` originales por `credit_account_id`, para restaurarlos en
     * `tearDown()`. Red de seguridad extra, además de `DatabaseTransactions`.
     *
     * @var array<int,array{id:int, limite_credito:float|null}>
     */
    protected $limites_a_restaurar = [];

    /**
     * Extensión "guardad_cuenta_corriente_despues_de_facturar" enganchada por el test 6, para
     * desengancharla en tearDown() si el test la agregó.
     *
     * @var \App\Models\ExtencionEmpresa|null
     */
    protected $extencion_facturar_primero = null;

    /**
     * Devuelve el reloj y el limite_credito de cada credit_account tocada a su valor original.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        foreach ($this->limites_a_restaurar as $item) {
            CreditAccount::where('id', $item['id'])->update(['limite_credito' => $item['limite_credito']]);
        }
        $this->limites_a_restaurar = [];

        if (!is_null($this->extencion_facturar_primero)) {
            $this->usuario_de_testing()->extencions()->detach($this->extencion_facturar_primero->id);
            $this->extencion_facturar_primero = null;
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers privados del fixture
    // ---------------------------------------------------------------------

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * @param string $nombre
     * @return \App\Models\Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::where('name', $nombre)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->first();

        if (is_null($cliente)) {
            $this->fail('No existe el cliente "'.$nombre.'" en el fixture de testing.');
        }

        return $cliente;
    }

    /**
     * @param \App\Models\Client $client
     * @param int $moneda_id
     * @return \App\Models\CreditAccount
     */
    protected function credit_account($client, $moneda_id)
    {
        $cuenta = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', $moneda_id)
            ->first();

        if (is_null($cuenta)) {
            CreditAccountHelper::crear_credit_accounts('client', $client->id);

            $cuenta = CreditAccount::where('model_name', 'client')
                ->where('model_id', $client->id)
                ->where('moneda_id', $moneda_id)
                ->first();
        }

        if (is_null($cuenta)) {
            $this->fail('No se pudo resolver/crear la credit_account de moneda '.$moneda_id.' del cliente "'.$client->name.'".');
        }

        return $cuenta;
    }

    /**
     * Fija el `limite_credito` de la credit_account (client, $moneda_id) y guarda el valor
     * original para restaurarlo en tearDown().
     *
     * @param \App\Models\Client $client
     * @param int $moneda_id
     * @param float|null $limite
     * @return \App\Models\CreditAccount La credit_account, refrescada.
     */
    protected function fijar_limite($client, $moneda_id, $limite)
    {
        $cuenta = $this->credit_account($client, $moneda_id);

        if (!isset($this->limites_a_restaurar[$cuenta->id])) {
            $this->limites_a_restaurar[$cuenta->id] = [
                'id'             => $cuenta->id,
                'limite_credito' => $cuenta->limite_credito,
            ];
        }

        $cuenta->limite_credito = $limite;
        $cuenta->save();

        return $cuenta->fresh();
    }

    /**
     * Saldo vigente de la credit_account, calculado con el mismo helper que usa
     * LimiteCreditoHelper (no el denormalizado credit_accounts.saldo).
     *
     * @param \App\Models\CreditAccount $cuenta
     * @return float
     */
    protected function saldo_actual($cuenta)
    {
        return (float) CurrentAcountHelper::getSaldo($cuenta->id);
    }

    /**
     * Arma el payload de POST api/sale para una venta de un solo artículo del fixture (el
     * centinela), con price_vender * amount = $total.
     *
     * @param int|null $client_id
     * @param float $total
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function payload_venta($client_id, $total, $overrides = [])
    {
        $articulo = Article::where('name', TestingFerreteriaSeeder::ARTICULO_CENTINELA)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->first();

        if (is_null($articulo)) {
            $this->fail('No existe el articulo centinela "'.TestingFerreteriaSeeder::ARTICULO_CENTINELA.'" en el fixture.');
        }

        $payload = [
            'client_id'                  => $client_id,
            'address_id'                 => null,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'employee_id'                => null,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'seller_id'                  => null,
            'cantidad_cuotas'            => null,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'caja_id'                    => null,
            'afip_tipo_comprobante_id'   => null,
            'descuento'                  => null,
            'moneda_id'                  => 1,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => $total,
                    'amount'       => 1,
                ],
            ],
        ];

        return array_merge($payload, $overrides);
    }

    /**
     * @param array<string,mixed> $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function postear_venta($payload)
    {
        return $this->postJson('api/sale', $payload);
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * TEST MÁS IMPORTANTE: es el de no regresión. Un cliente sin límite (limite_credito null)
     * tiene que poder vender cualquier monto, exactamente igual que antes de esta misión: la
     * venta se guarda (201) y genera su movimiento de cuenta corriente normal.
     *
     * @group limite_credito
     * @test
     */
    public function cliente_sin_limite_la_venta_se_guarda_igual_que_hoy()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->fijar_limite($client, 1, null);

        $total = 999999;

        $response = $this->postear_venta($this->payload_venta($client->id, $total));

        $response->assertStatus(201);

        $body = json_decode($response->getContent(), true);
        $sale_id = $body['model']['id'];

        $current_acount = CurrentAcount::where('sale_id', $sale_id)->first();

        $this->assertNotNull(
            $current_acount,
            'Sin límite, la venta tiene que generar su movimiento de cuenta corriente, igual que antes de esta misión.'
        );
        $this->assertEqualsWithDelta($total, (float) $current_acount->debe, 0.01);
    }

    /**
     * Con límite fijado pero sin superarlo, la venta se guarda igual (201).
     *
     * @group limite_credito
     * @test
     */
    public function cliente_con_limite_venta_que_no_lo_supera_se_guarda()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);

        $baseline = $this->saldo_actual($cuenta);

        // Límite cómodo por encima del saldo de partida: la venta de 50.000 no lo alcanza.
        $this->fijar_limite($client, 1, round($baseline + 100000, 2));

        $response = $this->postear_venta($this->payload_venta($client->id, 50000));

        $response->assertStatus(201);
    }

    /**
     * Superar el límite devuelve 422 con los tres números exactos, y no crea ninguna Sale nueva.
     *
     * @group limite_credito
     * @test
     */
    public function cliente_con_limite_venta_que_lo_supera_devuelve_422_con_los_tres_numeros()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);

        $baseline = $this->saldo_actual($cuenta);
        $limite = round($baseline + 100000, 2);

        $this->fijar_limite($client, 1, $limite);

        // Primera venta: no supera el límite (baseline + 80.000 < baseline + 100.000).
        $primera = $this->postear_venta($this->payload_venta($client->id, 80000));
        $primera->assertStatus(201);

        // El guard anti-duplicado de SaleController descarta una venta igual creada hace menos
        // de 5 segundos: hay que mover el reloj antes de la segunda.
        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        $cantidad_ventas_antes = Sale::where('client_id', $client->id)->count();

        $saldo_esperado_tras_primera = round($baseline + 80000, 2);

        // Segunda venta: baseline + 80.000 + 50.000 = baseline + 130.000 > límite (baseline + 100.000).
        $segunda = $this->postear_venta($this->payload_venta($client->id, 50000));

        $segunda->assertStatus(422);

        $body = json_decode($segunda->getContent(), true);

        $this->assertTrue($body['error_limite_credito']);
        $this->assertEqualsWithDelta($saldo_esperado_tras_primera, $body['limite_credito']['saldo_actual'], 0.01);
        $this->assertEqualsWithDelta(50000, $body['limite_credito']['total_venta'], 0.01);
        $this->assertEqualsWithDelta($limite, $body['limite_credito']['limite_credito'], 0.01);
        $this->assertEqualsWithDelta($saldo_esperado_tras_primera + 50000, $body['limite_credito']['saldo_resultante'], 0.01);

        $this->assertStringContainsString(\App\Http\Controllers\Helpers\LimiteCreditoHelper::formato($saldo_esperado_tras_primera), $body['message']);
        $this->assertStringContainsString(\App\Http\Controllers\Helpers\LimiteCreditoHelper::formato(50000), $body['message']);
        $this->assertStringContainsString(\App\Http\Controllers\Helpers\LimiteCreditoHelper::formato($limite), $body['message']);

        $cantidad_ventas_despues = Sale::where('client_id', $client->id)->count();

        $this->assertEquals(
            $cantidad_ventas_antes,
            $cantidad_ventas_despues,
            'El rechazo por límite de crédito no tiene que crear ninguna Sale nueva.'
        );
    }

    /**
     * Una venta de mostrador (omitir_en_cuenta_corriente=1 + save_current_acount=0, o directamente
     * sin cliente) nunca dispara el chequeo del límite, aunque el cliente ya esté pasado de límite.
     *
     * @group limite_credito
     * @test
     */
    public function venta_de_mostrador_no_dispara_el_limite()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);

        $baseline = $this->saldo_actual($cuenta);

        // Sin límite todavía: esta venta entra normal y deja al cliente con saldo baseline + 100.000.
        $this->fijar_limite($client, 1, null);

        $normal = $this->postear_venta($this->payload_venta($client->id, 100000));
        $normal->assertStatus(201);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        // Ahora se fija un límite POR DEBAJO del saldo real: el cliente queda "pasado de límite"
        // sin necesidad de que ninguna venta lo haya empujado ahí (esa venta se hubiera rechazado).
        $this->fijar_limite($client, 1, round($baseline + 10000, 2));

        // Venta con omitir_en_cuenta_corriente + save_current_acount=0: no va a la cuenta corriente,
        // así que el chequeo corta antes de llegar a comparar contra el límite.
        $mostrador = $this->postear_venta($this->payload_venta($client->id, 50000, [
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
        ]));

        $mostrador->assertStatus(201);

        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        // Venta sin cliente: tampoco hay cuenta corriente que pueda excederse.
        $sin_cliente = $this->postear_venta($this->payload_venta(null, 50000));

        $sin_cliente->assertStatus(201);
    }

    /**
     * El límite de una moneda no afecta ventas en la otra: cada credit_account (una por moneda)
     * tiene su propio límite, y comparar contra el equivocado sería mezclar pesos con dólares.
     *
     * @group limite_credito
     * @test
     */
    public function el_limite_de_una_moneda_no_afecta_ventas_de_la_otra()
    {
        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        // Límite muy bajo en pesos (moneda 1); sin límite en dólares (moneda 2).
        $this->fijar_limite($client, 1, 100);
        $this->fijar_limite($client, 2, null);

        $venta_dolares = $this->postear_venta($this->payload_venta($client->id, 500000, ['moneda_id' => 2]));

        $venta_dolares->assertStatus(201, 'El límite de pesos no tiene que tocar una venta en dólares.');

        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        // Espejo: sin límite en pesos; límite muy bajo en dólares.
        $this->fijar_limite($client, 1, null);
        $this->fijar_limite($client, 2, 10);

        $venta_pesos = $this->postear_venta($this->payload_venta($client->id, 500000, ['moneda_id' => 1]));

        $venta_pesos->assertStatus(201, 'El límite de dólares no tiene que tocar una venta en pesos.');
    }

    /**
     * Con la extensión "guardad_cuenta_corriente_despues_de_facturar" activa, y el cliente SIN la
     * bandera "pasar a la C/C sin esperar a facturar", la venta no entra a la cuenta corriente al
     * guardarse (entra recién al facturar). El límite no tiene que bloquear acá: no chequear al
     * facturar es una decisión (plan §4.4 punto 2), no un olvido — bloquear en ese momento dejaría
     * un comprobante ya vendido sin forma de "quitar ítems".
     *
     * @group limite_credito
     * @test
     */
    public function con_la_extencion_de_facturar_primero_el_limite_no_bloquea_al_guardar()
    {
        $extencion = ExtencionEmpresa::where('slug', 'guardad_cuenta_corriente_despues_de_facturar')->first();

        if (is_null($extencion)) {
            $this->markTestSkipped(
                'Falta la extensión "guardad_cuenta_corriente_despues_de_facturar" en la base de '.
                'testing. Correr ExtencionSeeder antes de esta suite.'
            );
            return;
        }

        $user = $this->usuario_de_testing();
        $user->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->extencion_facturar_primero = $extencion;

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $client->pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar = 0;
        $client->save();

        // Límite mínimo: si el chequeo llegara a correr iguial, cualquier venta lo superaría.
        $this->fijar_limite($client, 1, 1);

        $response = $this->postear_venta($this->payload_venta($client->id, 500000));

        $response->assertStatus(
            201,
            'Con la extensión activa y el cliente sin la bandera, la venta no entra a la C/C al '.
            'guardarse, así que el límite no se tiene que disparar.'
        );
    }
}
