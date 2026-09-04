<?php

namespace Tests\Feature\LimiteCredito;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Extencion;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Límite de crédito al CONFIRMAR UN PEDIDO de la tienda propia (prompt 610).
 *
 * Hasta este prompt, `LimiteCreditoHelper` sólo se invocaba desde `SaleController::store()` y
 * `::update()`. La venta que nace de confirmar un pedido la crea `CreateSaleOrderHelper` sin
 * pasar por `SaleController`, así que un cliente al tope de su límite no podía comprar en el
 * mostrador y sí desde la tienda.
 *
 * 🔴 A diferencia del mostrador, acá el aviso es SALTEABLE (decisión de Lucas, 22/8/2026): el 422
 * frena la confirmación, pero reintentar con `ignorar_limite_credito` la deja pasar. El test
 * `con_ignorar_limite_credito_el_pedido_se_confirma_igual` es el que fija esa decisión.
 *
 * Igual que en las suites 1 y 2 de esta carpeta, los saldos de partida se leen con
 * `CurrentAcountHelper::getSaldo()` y nunca se asumen en 0: la base del slot puede traer
 * movimientos previos de `CLIENTE_CC` de otras suites.
 *
 * ⚠️ La no regresión sobre el camino del mostrador (`SaleController::store()`/`update()`, que el
 * prompt 610 pide como su quinto test) NO está acá: la cubren las suites `1_` y `2_` de esta misma
 * carpeta, que son las de la misión 160 y siguen verdes. Se corren juntas con
 * `vendor/bin/phpunit tests/Feature/LimiteCredito`.
 *
 * ⚠️ El tercer test del prompt pedía "tope en pesos, venta en dólares". Por este camino es
 * imposible: `CreateSaleOrderHelper::createSale()` hardcodea `moneda_id => 1`, así que un pedido
 * es siempre en pesos. El caso se prueba dado vuelta —tope sólo en la otra moneda, pedido en
 * pesos—, que mide lo mismo: que la cuenta se busca por la moneda de la venta.
 */
class Limite_de_credito_al_confirmar_pedido_Test extends EmpresaTestCase
{
    /**
     * Snapshots de `limite_credito` originales por `credit_account_id`.
     *
     * ⚠️ La restauración de `tearDown()` corre ANTES de `parent::tearDown()`, que es donde
     * `DatabaseTransactions` hace su rollback, así que esas escrituras se revierten con todo lo
     * demás: quien aísla de verdad es la transacción. Se deja igual porque documenta qué muta cada
     * test, pero no es la red de seguridad que parece.
     *
     * @var array<int,array{id:int, limite_credito:float|null}>
     */
    protected $limites_a_restaurar = [];

    /**
     * Snapshot del artículo centinela, para devolverle el stock que los tests le mueven.
     *
     * @var array<string,mixed>|null
     */
    protected $snapshot_centinela = null;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->limites_a_restaurar as $item) {
            CreditAccount::where('id', $item['id'])->update(['limite_credito' => $item['limite_credito']]);
        }
        $this->limites_a_restaurar = [];

        if (!is_null($this->snapshot_centinela)) {
            $this->restaurar_articulo($this->centinela(), $this->snapshot_centinela);
            $this->snapshot_centinela = null;
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Helpers del fixture
    // ---------------------------------------------------------------------

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * @return \App\Models\Article
     */
    protected function centinela()
    {
        return $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
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
     * @param \App\Models\Client $client
     * @param int $moneda_id
     * @param float|null $limite
     * @return \App\Models\CreditAccount
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
     * @param \App\Models\CreditAccount $cuenta
     * @return float
     */
    protected function saldo_actual($cuenta)
    {
        return (float) CurrentAcountHelper::getSaldo($cuenta->id);
    }

    /**
     * `order_statuses` NO la siembra `TestingFerreteriaSeeder` (llama a
     * `ProviderOrderStatusSeeder` pero no a `OrderStatusSeeder`): la tabla viene vacía. Se crean
     * acá, dentro del test, y `DatabaseTransactions` los revierte al terminar. No se toca el
     * seeder compartido.
     *
     * @param string $name
     * @return \App\Models\OrderStatus
     */
    protected function order_status($name)
    {
        return OrderStatus::firstOrCreate(['name' => $name]);
    }

    /**
     * Comprador de la tienda. Con `$client` vinculado, la venta que nazca del pedido va a tener
     * `client_id` y por lo tanto cuenta corriente; sin él, no.
     *
     * @param \App\Models\Client|null $client
     * @return \App\Models\Buyer
     */
    protected function comprador($client = null)
    {
        return Buyer::create([
            'user_id'                 => $this->usuario_de_testing()->id,
            'name'                    => 'Comprador de testing',
            'email'                   => 'comprador.testing.610@example.com',
            'comercio_city_client_id' => !is_null($client) ? $client->id : null,
        ]);
    }

    /**
     * Pedido "Sin confirmar" con un artículo, listo para confirmarse.
     *
     * @param \App\Models\Buyer $buyer
     * @param float $total
     * @return \App\Models\Order
     */
    protected function pedido_sin_confirmar($buyer, $total)
    {
        $centinela = $this->centinela();

        if (is_null($this->snapshot_centinela)) {
            $this->snapshot_centinela = $this->snapshot_articulo($centinela);
        }

        $order = Order::create([
            'user_id'         => $this->usuario_de_testing()->id,
            'buyer_id'        => $buyer->id,
            'order_status_id' => $this->order_status('Sin confirmar')->id,
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'total'           => $total,
            'address_id'      => null,
            'fecha_entrega'   => null,
            'seller_id'       => null,
        ]);

        $order->articles()->attach($centinela->id, [
            'amount' => 1,
            'price'  => $total,
            'cost'   => $centinela->cost,
        ]);

        return $order->fresh();
    }

    /**
     * Confirma el pedido por el mismo endpoint y con el mismo payload que manda `BtnStatus.vue`:
     * `PUT api/order/{id}` con `order_status_id` y nada más. `update()` sólo toca los renglones y
     * el depósito si la request los trae (ver su docblock), justamente para que este payload no
     * los pise.
     *
     * @param \App\Models\Order $order
     * @param array<string,mixed> $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($order, $extra = [])
    {
        $payload = array_merge([
            'order_status_id' => $this->order_status('Confirmado')->id,
        ], $extra);

        return $this->putJson('api/order/'.$order->id, $payload);
    }

    /**
     * El usuario del fixture NO debe tener `check_sales`: con esa extensión la venta nace
     * `to_check` y no toca la cuenta corriente, con lo cual ningún test de límite mediría nada.
     *
     * @return void
     */
    protected function asegurar_sin_check_sales()
    {
        $extencion = Extencion::where('name', 'check_sales')->first();

        // Sin la fila en `extencions` nadie puede tenerla enganchada: el resultado es el mismo que
        // buscamos, aunque por otro motivo.
        if (is_null($extencion)) {
            return;
        }

        $user = $this->usuario_de_testing();

        if ($user->extencions()->where('extencions.id', $extencion->id)->exists()) {
            $this->fail(
                'El usuario del fixture tiene la extensión check_sales activa: las ventas nacerían '.
                'to_check y no tocarían la cuenta corriente, con lo cual esta suite no mediría nada.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * No regresión: es el caso de la enorme mayoría de los clientes (sin límite cargado).
     *
     * @test
     * @return void
     */
    public function pedido_de_cliente_sin_limite_se_confirma_igual_que_hoy()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->fijar_limite($client, 1, null);

        $order = $this->pedido_sin_confirmar($this->comprador($client), 5000);

        $response = $this->confirmar($order);

        $response->assertStatus(200);

        $this->assertSame(
            1,
            Sale::where('order_id', $order->id)->count(),
            'Un cliente sin límite cargado tiene que poder confirmar el pedido igual que antes del prompt 610.'
        );
    }

    /**
     * @test
     * @return void
     */
    public function pedido_que_no_supera_el_limite_se_confirma()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);
        $saldo  = $this->saldo_actual($cuenta);

        $total = 5000;

        // Límite holgado respecto del saldo de partida, sea cual sea.
        $this->fijar_limite($client, 1, $saldo + $total + 10000);

        $order = $this->pedido_sin_confirmar($this->comprador($client), $total);

        $this->confirmar($order)->assertStatus(200);

        $this->assertSame(1, Sale::where('order_id', $order->id)->count());
    }

    /**
     * @test
     * @return void
     */
    public function pedido_que_supera_el_limite_devuelve_422_con_los_numeros()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);
        $saldo  = $this->saldo_actual($cuenta);

        $total  = 50000;
        $limite = $saldo + $total - 1000;

        $this->fijar_limite($client, 1, $limite);

        $order = $this->pedido_sin_confirmar($this->comprador($client), $total);

        $response = $this->confirmar($order);

        $response->assertStatus(422);
        $response->assertJsonPath('error_limite_credito', true);
        $response->assertJsonPath('limite_credito.client_id', $client->id);

        /*
            Los importes se comparan por valor y no con assertJsonPath: json_encode() serializa
            50000.0 como `50000`, y assertJsonPath compara con tipo estricto, así que un float
            redondo nunca coincidiría. El número es el mismo; lo que no coincide es el tipo.
        */
        $numeros = $response->json('limite_credito');

        $this->assertEqualsWithDelta($total, $numeros['total_venta'], 0.01);
        $this->assertEqualsWithDelta($saldo, $numeros['saldo_actual'], 0.01);
        $this->assertEqualsWithDelta($saldo + $total, $numeros['saldo_resultante'], 0.01);
        $this->assertEqualsWithDelta($limite, $numeros['limite_credito'], 0.01);
        $this->assertEqualsWithDelta($saldo + $total - $limite, $numeros['excedente'], 0.01);
    }

    /**
     * El que más importa: un rechazo por límite no puede dejar el pedido confirmado, ni la venta
     * creada, ni el stock movido. `attachArticles()` descuenta stock ANTES de que
     * `create_current_acount()` toque el saldo, así que un chequeo tardío dejaría el stock ido.
     *
     * @test
     * @return void
     */
    public function pedido_rechazado_no_deja_venta_ni_stock_movido_ni_cambia_el_estado()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);
        $saldo  = $this->saldo_actual($cuenta);

        $total = 50000;
        $this->fijar_limite($client, 1, $saldo + $total - 1000);

        $order = $this->pedido_sin_confirmar($this->comprador($client), $total);

        $stock_antes  = (float) $this->centinela()->fresh()->stock;
        $estado_antes = $order->order_status_id;
        $ventas_antes = Sale::count();

        $this->confirmar($order)->assertStatus(422);

        $this->assertSame(
            0,
            Sale::where('order_id', $order->id)->count(),
            'Un pedido rechazado por límite no puede dejar la venta creada.'
        );

        $this->assertSame(
            $ventas_antes,
            Sale::count(),
            'Un pedido rechazado por límite no puede dejar NINGUNA venta creada, ni siquiera huérfana.'
        );

        $this->assertSame(
            $stock_antes,
            (float) $this->centinela()->fresh()->stock,
            'Un pedido rechazado por límite no puede dejar el stock descontado.'
        );

        $this->assertSame(
            $estado_antes,
            $order->fresh()->order_status_id,
            'Un pedido rechazado por límite tiene que seguir "Sin confirmar".'
        );
    }

    /**
     * La decisión de producto del prompt 610, en un test: el aviso es SALTEABLE.
     *
     * @test
     * @return void
     */
    public function con_ignorar_limite_credito_el_pedido_se_confirma_igual()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->credit_account($client, 1);
        $saldo  = $this->saldo_actual($cuenta);

        $total = 50000;
        $this->fijar_limite($client, 1, $saldo + $total - 1000);

        $order = $this->pedido_sin_confirmar($this->comprador($client), $total);

        // Sin la bandera, frena.
        $this->confirmar($order)->assertStatus(422);

        // Con la bandera, pasa.
        $this->confirmar($order, ['ignorar_limite_credito' => true])->assertStatus(200);

        $this->assertSame(
            1,
            Sale::where('order_id', $order->id)->count(),
            'Con ignorar_limite_credito el pedido tiene que confirmarse igual: el aviso es salteable.'
        );

        $this->assertGreaterThan(
            $saldo,
            $this->saldo_actual($cuenta),
            'Al confirmar igual, la venta tiene que haber entrado a la cuenta corriente.'
        );
    }

    /**
     * El límite es POR MONEDA. `CreateSaleOrderHelper::createSale()` hardcodea `moneda_id => 1`,
     * así que un tope cargado sólo en la cuenta de otra moneda no puede frenar el pedido.
     *
     * @test
     * @return void
     */
    public function el_limite_en_otra_moneda_no_frena_el_pedido()
    {
        $this->asegurar_sin_check_sales();

        $client = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        // Sin tope en pesos (la moneda de la venta del pedido)...
        $this->fijar_limite($client, 1, null);

        // ...y con un tope imposible en la otra moneda.
        $cuenta_otra_moneda = CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', '!=', 1)
            ->first();

        if (is_null($cuenta_otra_moneda)) {
            $this->markTestSkipped('El fixture no tiene una credit_account de moneda distinta de 1 para este cliente.');
        }

        $this->limites_a_restaurar[$cuenta_otra_moneda->id] = [
            'id'             => $cuenta_otra_moneda->id,
            'limite_credito' => $cuenta_otra_moneda->limite_credito,
        ];
        $cuenta_otra_moneda->limite_credito = 0;
        $cuenta_otra_moneda->save();

        $order = $this->pedido_sin_confirmar($this->comprador($client), 50000);

        $this->confirmar($order)->assertStatus(200);

        $this->assertSame(1, Sale::where('order_id', $order->id)->count());
    }

    /**
     * Un pedido de un comprador que no está vinculado a ningún cliente del ERP no tiene cuenta
     * corriente ni límite que chequear: tiene que confirmarse sin romper.
     *
     * @test
     * @return void
     */
    public function pedido_de_comprador_sin_cliente_asociado_no_rompe()
    {
        $this->asegurar_sin_check_sales();

        $order = $this->pedido_sin_confirmar($this->comprador(null), 50000);

        $this->confirmar($order)->assertStatus(200);

        $venta = Sale::where('order_id', $order->id)->first();

        $this->assertNotNull($venta, 'El pedido de un comprador sin cliente asociado igual tiene que crear la venta.');
        $this->assertNull($venta->client_id, 'Esa venta no tiene cliente, así que no hay cuenta corriente que chequear.');
    }
}
