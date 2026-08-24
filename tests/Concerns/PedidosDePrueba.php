<?php

namespace Tests\Concerns;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderStatus;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Fixture compartido de pedidos online para los tests que los ejercitan.
 *
 * Sacado de `Tests\Feature\Pedidos\Confirmar_pedido_Test`, que era su único dueño hasta que la
 * misión del estado por select necesitó lo mismo en otras dos suites. Vive acá para que las tres
 * armen el pedido de la MISMA forma: si el fixture se arma distinto en cada archivo, dos tests que
 * dicen medir lo mismo terminan midiendo escenarios distintos.
 *
 * Requiere `Tests\EmpresaTestCase` (usa `$this->articulo()` y el usuario autenticado en su setUp).
 */
trait PedidosDePrueba
{
    /**
     * Estados del pedido, en el orden en que se avanza. `Cancelado` va aparte.
     *
     * @var array<int,string>
     */
    public static $ESTADOS_PEDIDO = ['Sin confirmar', 'Confirmado', 'Terminado', 'Entregado', 'Cancelado'];

    /**
     * Siembra los `order_statuses`, que el fixture de testing no trae.
     *
     * `TestingFerreteriaSeeder` no llama a `OrderStatusSeeder`: la tabla llega vacía. Se siembra
     * con `firstOrCreate` para ser idempotente y para no tocar el seeder compartido, que otras
     * suites ya dan por conocido.
     *
     * @return void
     */
    protected function sembrar_estados_de_pedido()
    {
        foreach (self::$ESTADOS_PEDIDO as $nombre) {
            OrderStatus::firstOrCreate(['name' => $nombre]);
        }
    }

    /**
     * Id del usuario autenticado por `EmpresaTestCase` (el dueño del fixture).
     *
     * @return int
     */
    protected function user_id()
    {
        return $this->app['auth']->user()->id;
    }

    /**
     * Devuelve el `OrderStatus` por nombre. Nunca por id hardcodeado.
     *
     * @param  string  $nombre
     * @return \App\Models\OrderStatus
     */
    protected function estado($nombre)
    {
        return OrderStatus::where('name', $nombre)->first();
    }

    /**
     * Cliente de cuenta corriente del fixture, con sus `credit_accounts` garantizadas.
     *
     * ⚠️ Hueco del FIXTURE, no del producto: `TestingFerreteriaSeeder::seed_clientes()` crea los
     * clientes con `firstOrCreate` directo sobre el modelo, sin pasar por `ClientController@store`,
     * que es donde producción llama a `CreditAccountHelper::crear_credit_accounts()`. Sin esa fila,
     * `CurrentAcountFromSaleHelper` (que hace `$this->credit_account->id` sin guarda) tira 500 al
     * crear el movimiento de cuenta corriente de la venta.
     *
     * Se garantiza con el MISMO helper que usa producción, no con un `CreditAccount::create()` a
     * mano. `crear_credit_accounts()` es idempotente.
     *
     * @return \App\Models\Client
     */
    protected function cliente_cc()
    {
        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($cliente, 'El fixture no tiene el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'".');

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id);

        return $cliente;
    }

    /**
     * Depósito del fixture, resuelto por nombre.
     *
     * @return \App\Models\Address
     */
    protected function deposito()
    {
        $deposito = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $this->assertNotNull($deposito, 'El fixture no tiene el deposito "'.TestingFerreteriaSeeder::DEPOSITO.'".');

        return $deposito;
    }

    /**
     * Crea un comprador de la tienda.
     *
     * @param  int|null  $client_id  Cliente del ERP asociado, o null para un comprador suelto.
     * @return \App\Models\Buyer
     */
    protected function crear_comprador($client_id)
    {
        return Buyer::create([
            'name'                    => 'Comprador de prueba 608',
            'phone'                   => '2216000000',
            'email'                   => 'comprador608@testing.local',
            'user_id'                 => $this->user_id(),
            'comercio_city_client_id' => $client_id,
        ]);
    }

    /**
     * Crea un pedido "Sin confirmar" con dos renglones del fixture.
     *
     * Los renglones se adjuntan con `price` y `amount` en el pivote, que es lo que
     * `CreateSaleOrderHelper::attach_sale_properties()` lee para armar los ítems de la venta.
     *
     * @param  int|null  $client_id  Cliente del ERP asociado al comprador.
     * @return \App\Models\Order
     */
    protected function crear_pedido($client_id)
    {
        $comprador = $this->crear_comprador($client_id);

        $pedido = Order::create([
            'status'          => 'unconfirmed',
            'deliver'         => 0,
            'buyer_id'        => $comprador->id,
            'order_status_id' => $this->estado('Sin confirmar')->id,
            'user_id'         => $this->user_id(),
            'address_id'      => $this->deposito()->id,
            'total'           => 0,
        ]);

        foreach ($this->renglones() as $renglon) {
            $pedido->articles()->attach($renglon['article']->id, [
                'price'  => $renglon['price'],
                'amount' => $renglon['amount'],
            ]);
        }

        $pedido->total = $this->total_esperado();
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Los dos renglones del pedido de prueba, resueltos por nombre del fixture.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function renglones()
    {
        return [
            [
                'article' => $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA),
                'price'   => 100,
                'amount'  => 2,
            ],
            [
                'article' => $this->articulo('Pinza'),
                'price'   => 50,
                'amount'  => 3,
            ],
        ];
    }

    /**
     * Total que corresponde a los renglones de `renglones()`.
     *
     * @return float
     */
    protected function total_esperado()
    {
        $total = 0;

        foreach ($this->renglones() as $renglon) {
            $total += $renglon['price'] * $renglon['amount'];
        }

        return $total;
    }

    /**
     * Payload mínimo: solo el estado nuevo.
     *
     * @param  string  $nombre_estado
     * @return array<string,mixed>
     */
    protected function payload_de_estado($nombre_estado)
    {
        return ['order_status_id' => $this->estado($nombre_estado)->id];
    }

    /**
     * Payload que manda el FORMULARIO (el camino del select): el modelo entero, con renglones y
     * depósito.
     *
     * @param  \App\Models\Order  $pedido
     * @param  string  $nombre_estado
     * @return array<string,mixed>
     */
    protected function payload_del_select($pedido, $nombre_estado)
    {
        $articles = [];

        foreach ($pedido->articles as $article) {
            $articles[] = [
                'id'    => $article->id,
                'pivot' => [
                    'price'  => $article->pivot->price,
                    'amount' => $article->pivot->amount,
                ],
            ];
        }

        return [
            'order_status_id' => $this->estado($nombre_estado)->id,
            'address_id'      => $pedido->address_id,
            'articles'        => $articles,
        ];
    }

    /**
     * Snapshot del stock de los artículos del pedido, por id.
     *
     * @return array<int,float>
     */
    protected function stock_actual()
    {
        $stock = [];

        foreach ($this->renglones() as $renglon) {
            $stock[$renglon['article']->id] = (float) Article::find($renglon['article']->id)->stock;
        }

        return $stock;
    }
}
