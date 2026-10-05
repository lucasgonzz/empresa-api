<?php

namespace Tests\Feature\CuentaCorriente;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * Misión saldo-inicial-cuenta-corriente (5/10/2026) — el saldo inicial de una cuenta corriente.
 *
 * Antes de la misión el botón "Saldo inicial" de la SPA no aparecía nunca (esperaba un
 * `current_acounts_count` que el cliente no trae), y el endpoint al que llamaba estaba roto desde
 * las cuentas por moneda: creaba el movimiento sin `credit_account_id` ni `user_id` y después
 * reventaba en `updateModelSaldo()` → `getSaldo('client', $id)`.
 *
 * Se cubren las dos piezas nuevas por el camino real (HTTP) y se verifica el estado de la base, no
 * la respuesta sola:
 *  - `GET api/credit-account/{id}/tiene-movimientos`, que decide si la SPA ofrece el botón.
 *  - `POST api/current-acount/saldo-inicial`, en el debe y en el haber, para cliente y proveedor,
 *    en pesos y en dólares, y las negativas (cuenta con movimientos, cuenta ajena, monto inválido).
 *
 * @group cuenta-corriente
 */
class Saldo_inicial_Test extends EmpresaTestCase
{
    use ArmaCadenas;

    /** @var int Dueño de la sesión (el usuario del fixture). */
    protected $user_id;

    /** @var \App\Models\Client */
    protected $cliente;

    /** @var \App\Models\CreditAccount Cuenta en pesos del cliente. */
    protected $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = $this->app['auth']->user()->id;

        list($this->cliente, $this->cuenta) = $this->cliente_con_cuenta($this->user_id, 'Saldo inicial');
    }

    /**
     * Payload de `POST api/current-acount/saldo-inicial`, igual al que manda la SPA.
     *
     * @param  CreditAccount  $cuenta
     * @param  mixed          $monto
     * @param  bool           $is_for_debe
     * @return array
     */
    protected function saldo_inicial($cuenta, $monto, $is_for_debe = true)
    {
        return [
            'credit_account_id' => $cuenta->id,
            'model_name'        => $cuenta->model_name,
            'model_id'          => $cuenta->model_id,
            'is_for_debe'       => $is_for_debe,
            'saldo_inicial'     => $monto,
        ];
    }

    /**
     * La cuenta de una moneda de un cliente o proveedor.
     *
     * @param  string  $model_name
     * @param  int     $model_id
     * @param  int     $moneda_id
     * @return CreditAccount
     */
    protected function cuenta_de($model_name, $model_id, $moneda_id)
    {
        return CreditAccount::where('model_name', $model_name)
                            ->where('model_id', $model_id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }

    /**
     * Las filas de una cuenta, provisorias incluidas (lo mismo que cuenta el endpoint).
     *
     * @param  CreditAccount  $cuenta
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos_de($cuenta)
    {
        return CurrentAcount::where('credit_account_id', $cuenta->id)->get();
    }

    /**
     * @test
     */
    public function tiene_movimientos_dice_false_en_una_cuenta_vacia_y_true_con_un_movimiento()
    {
        $this->getJson('api/credit-account/'.$this->cuenta->id.'/tiene-movimientos')
             ->assertStatus(200)
             ->assertExactJson(['tiene_movimientos' => false]);

        $this->movimiento($this->cuenta, ['debe' => 100, 'created_at' => Carbon::now()->subDay()]);

        $this->getJson('api/credit-account/'.$this->cuenta->id.'/tiene-movimientos')
             ->assertStatus(200)
             ->assertExactJson(['tiene_movimientos' => true]);
    }

    /**
     * El saldo inicial va por cuenta, o sea por moneda: la cuenta en dólares sigue vacía aunque la
     * de pesos tenga movimientos. El `withCount` viejo contaba por cliente y no lo distinguía.
     *
     * @test
     */
    public function tiene_movimientos_es_por_moneda()
    {
        $this->movimiento($this->cuenta, ['debe' => 100, 'created_at' => Carbon::now()->subDay()]);

        $cuenta_dolares = $this->cuenta_de('client', $this->cliente->id, 2);

        $this->getJson('api/credit-account/'.$cuenta_dolares->id.'/tiene-movimientos')
             ->assertStatus(200)
             ->assertExactJson(['tiene_movimientos' => false]);
    }

    /**
     * Un movimiento provisorio también cuenta: el listado lo muestra, así que la cuenta no está
     * vacía para quien la mira.
     *
     * @test
     */
    public function tiene_movimientos_cuenta_los_provisorios()
    {
        $this->movimiento($this->cuenta, ['debe' => 100, 'is_provisorio' => 1, 'created_at' => Carbon::now()->subDay()]);

        $this->getJson('api/credit-account/'.$this->cuenta->id.'/tiene-movimientos')
             ->assertStatus(200)
             ->assertExactJson(['tiene_movimientos' => true]);
    }

    /**
     * @test
     */
    public function tiene_movimientos_de_una_cuenta_de_otro_duenio_da_404()
    {
        list($ajeno, $cuenta_ajena) = $this->cliente_con_cuenta($this->user_id + 900000, 'Ajeno');

        $this->getJson('api/credit-account/'.$cuenta_ajena->id.'/tiene-movimientos')
             ->assertStatus(404);
    }

    /**
     * El caso del video T4.9: un cliente nuevo que llega con deuda.
     *
     * @test
     */
    public function saldo_inicial_en_el_debe_de_un_cliente()
    {
        $respuesta = $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, 15000.5))
                          ->assertStatus(201)
                          ->assertJsonPath('current_acount.detalle', 'Saldo inicial')
                          ->assertJsonPath('credit_account.id', $this->cuenta->id);

        // La cuenta que vuelve trae el saldo ya recalculado: con eso la SPA actualiza la franja.
        $this->assertEqualsWithDelta(15000.5, (float) $respuesta->json('credit_account.saldo'), 0.01);

        $movimientos = $this->movimientos_de($this->cuenta);

        $this->assertCount(1, $movimientos);

        $saldo_inicial = $movimientos->first();

        $this->assertEquals('Saldo inicial', $saldo_inicial->detalle);
        $this->assertEquals('sin_pagar', $saldo_inicial->status);
        $this->assertEqualsWithDelta(15000.5, (float) $saldo_inicial->debe, 0.001);
        $this->assertNull($saldo_inicial->haber);
        $this->assertEquals($this->cliente->id, $saldo_inicial->client_id);
        $this->assertNull($saldo_inicial->provider_id);
        $this->assertEquals($this->user_id, $saldo_inicial->user_id);
        $this->assertEquals(1, $saldo_inicial->getAttributes()['moneda_id']);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Saldo inicial en el debe');

        $this->assertEqualsWithDelta(15000.5, $saldo, 0.01);
        $this->assertEqualsWithDelta(15000.5, (float) $this->cuenta->fresh()->saldo, 0.01);
        $this->assertEqualsWithDelta(15000.5, (float) $this->cliente->fresh()->saldo_pesos, 0.01);

        // Y la cuenta deja de estar vacía: la SPA esconde el botón.
        $this->getJson('api/credit-account/'.$this->cuenta->id.'/tiene-movimientos')
             ->assertExactJson(['tiene_movimientos' => true]);
    }

    /**
     * @test
     */
    public function saldo_inicial_en_el_haber_de_un_cliente()
    {
        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, 2500, false))
             ->assertStatus(201);

        $saldo_inicial = $this->movimientos_de($this->cuenta)->first();

        $this->assertEquals('pago_from_client', $saldo_inicial->status);
        $this->assertNull($saldo_inicial->debe);
        $this->assertEqualsWithDelta(2500, (float) $saldo_inicial->haber, 0.001);

        $saldo = $this->assert_cadena_cierra($this->cuenta->id, 'Saldo inicial en el haber');

        $this->assertEqualsWithDelta(-2500, $saldo, 0.01);
        $this->assertEqualsWithDelta(-2500, (float) $this->cliente->fresh()->saldo_pesos, 0.01);
    }

    /**
     * Mismo Nav, mismo endpoint: la cuenta de un proveedor.
     *
     * @test
     */
    public function saldo_inicial_de_un_proveedor()
    {
        $proveedor = Provider::create([
            'num'       => (int) Provider::where('user_id', $this->user_id)->max('num') + 1,
            'name'      => 'zz Proveedor saldo inicial '.uniqid(),
            'user_id'   => $this->user_id,
        ]);

        CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $this->user_id);

        $cuenta = $this->cuenta_de('provider', $proveedor->id, 1);

        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($cuenta, 8000))
             ->assertStatus(201);

        $saldo_inicial = $this->movimientos_de($cuenta)->first();

        $this->assertEquals($proveedor->id, $saldo_inicial->provider_id);
        $this->assertNull($saldo_inicial->client_id);

        $this->assert_cadena_cierra($cuenta->id, 'Saldo inicial de un proveedor');

        $this->assertEqualsWithDelta(8000, (float) $proveedor->fresh()->saldo_pesos, 0.01);
    }

    /**
     * La cuenta en dólares: el saldo va a esa cuenta y a `saldo_dolares`, no a pesos.
     *
     * @test
     */
    public function saldo_inicial_en_la_cuenta_en_dolares()
    {
        $cuenta_dolares = $this->cuenta_de('client', $this->cliente->id, 2);

        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($cuenta_dolares, 300))
             ->assertStatus(201);

        $this->assertCount(1, $this->movimientos_de($cuenta_dolares));
        $this->assertCount(0, $this->movimientos_de($this->cuenta));

        $this->assertEquals(2, $this->movimientos_de($cuenta_dolares)->first()->getAttributes()['moneda_id']);
        $this->assertEqualsWithDelta(300, (float) $this->cliente->fresh()->saldo_dolares, 0.01);
    }

    /**
     * El doble clic: el segundo saldo inicial se niega y la deuda no se duplica.
     *
     * @test
     */
    public function un_segundo_saldo_inicial_da_422_y_no_duplica()
    {
        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, 1000))
             ->assertStatus(201);

        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, 1000))
             ->assertStatus(422)
             ->assertJsonStructure(['message']);

        $this->assertCount(1, $this->movimientos_de($this->cuenta));
        $this->assertEqualsWithDelta(1000, (float) $this->cliente->fresh()->saldo_pesos, 0.01);
    }

    /**
     * Una cuenta con movimientos se ajusta con nota de crédito o de débito, no con saldo inicial.
     *
     * @test
     */
    public function saldo_inicial_en_una_cuenta_con_movimientos_da_422()
    {
        $this->movimiento($this->cuenta, ['debe' => 100, 'saldo' => 100, 'created_at' => Carbon::now()->subDay()]);

        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, 5000))
             ->assertStatus(422);

        $this->assertCount(1, $this->movimientos_de($this->cuenta));
    }

    /**
     * Tenencia: un `credit_account_id` de otro comercio no escribe nada.
     *
     * @test
     */
    public function saldo_inicial_en_una_cuenta_de_otro_duenio_da_422_y_no_escribe()
    {
        list($ajeno, $cuenta_ajena) = $this->cliente_con_cuenta($this->user_id + 900000, 'Ajeno');

        $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($cuenta_ajena, 5000))
             ->assertStatus(422);

        $this->assertCount(0, $this->movimientos_de($cuenta_ajena));
    }

    /**
     * El monto va siempre positivo (el lado lo elige el radio). 0, negativo, vacío o texto: 422.
     *
     * @test
     */
    public function saldo_inicial_con_monto_invalido_da_422()
    {
        foreach ([0, -500, '', 'abc', null] as $monto) {

            $this->postJson('api/current-acount/saldo-inicial', $this->saldo_inicial($this->cuenta, $monto))
                 ->assertStatus(422);
        }

        $this->assertCount(0, $this->movimientos_de($this->cuenta));
    }

    /**
     * Una SPA anterior a la misión no manda `credit_account_id`: el saldo va a la cuenta en pesos.
     * Y ya no queda la fila huérfana sin cuenta que dejaba el endpoint viejo.
     *
     * @test
     */
    public function sin_credit_account_id_va_a_la_cuenta_en_pesos()
    {
        $payload = $this->saldo_inicial($this->cuenta, 700);

        unset($payload['credit_account_id']);

        $this->postJson('api/current-acount/saldo-inicial', $payload)
             ->assertStatus(201);

        $this->assertCount(1, $this->movimientos_de($this->cuenta));

        $huerfanas = CurrentAcount::where('client_id', $this->cliente->id)
                                    ->whereNull('credit_account_id')
                                    ->count();

        $this->assertEquals(0, $huerfanas);
    }
}
