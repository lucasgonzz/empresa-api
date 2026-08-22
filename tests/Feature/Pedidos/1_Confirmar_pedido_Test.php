<?php

namespace Tests\Feature\Pedidos;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Route;
use Tests\EmpresaTestCase;

/**
 * Prompt 608 — confirmar un pedido desde el boton de la fila.
 *
 * `BtnStatus.vue` pegaba contra `PUT order/update-status/{id}`, una ruta que seguia viva pero
 * apuntaba a `OrderController@updateStatus`, comentado entero desde el 14/5/2026. La llamada caia
 * en el `__call` de Laravel y volvia 500: el pedido no se confirmaba, la venta no nacia y el modal
 * quedaba abierto. Estaba asi tambien en `master`, o sea en produccion.
 *
 * El arreglo apunta el boton a `update()` (donde vive la logica) y borra la ruta muerta. Lo que
 * estos tests cuidan no es solo que el camino ande, sino las dos trampas que tiene:
 *
 *  1. `update()` asignaba `address_id` y re-adjuntaba `articles` de forma incondicional, y
 *     `GeneralHelper::attachModels()` arranca con un `detach()`. Un payload que solo manda
 *     `order_status_id` —que es exactamente lo que manda el boton— dejaba el pedido SIN renglones
 *     y sin deposito, y la venta nacia vacia. De ahi el test `no_borra_los_renglones`.
 *  2. Confirmar no es solo cambiar un estado: nace una venta que descuenta stock y entra a la
 *     cuenta corriente del cliente. El candado de idempotencia es `sales.order_id`.
 *
 * Se usa `EmpresaTestCase` (DatabaseTransactions + guards de entorno + fixture de la ferreteria).
 */
class Confirmar_pedido_Test extends EmpresaTestCase
{
    /**
     * Estados del pedido, en el orden en que el boton los recorre.
     *
     * @var array<int,string>
     */
    const ESTADOS = ['Sin confirmar', 'Confirmado', 'Terminado', 'Entregado', 'Cancelado'];

    /**
     * setUp: ademas de los guards de `EmpresaTestCase`, garantiza los `order_statuses`.
     *
     * El fixture de testing (`TestingFerreteriaSeeder`) no llama a `OrderStatusSeeder`: la tabla
     * llega vacia. Se siembra aca con `firstOrCreate` para que sea idempotente y para no tocar el
     * seeder compartido, que otras suites ya dan por conocido.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ESTADOS as $nombre) {
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
     * que es donde produccion llama a `CreditAccountHelper::crear_credit_accounts()`. Sin esa fila,
     * `CurrentAcountFromSaleHelper` (que hace `$this->credit_account->id` sin guarda) tira un 500 al
     * crear el movimiento de cuenta corriente de la venta.
     *
     * Se garantiza aca con el MISMO helper que usa produccion —no con un `CreditAccount::create()`
     * a mano— para no inventarse una forma distinta de armar el dato, y no se toca el seeder
     * compartido, que otras suites ya dan por conocido. `crear_credit_accounts()` es idempotente.
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
     * Deposito del fixture, resuelto por nombre.
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
     * `CreateSaleOrderHelper::attach_sale_properties()` lee para armar los items de la venta.
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
     * Payload que manda el BOTON: unicamente el estado nuevo.
     *
     * @param  string  $nombre_estado
     * @return array<string,mixed>
     */
    protected function payload_del_boton($nombre_estado)
    {
        return ['order_status_id' => $this->estado($nombre_estado)->id];
    }

    /**
     * Payload que manda el FORMULARIO (el camino del select): el modelo entero, con renglones y
     * deposito.
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
     * Snapshot del stock de los articulos del pedido, por id.
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

    /**
     * 1. Confirmar desde el boton crea la venta, descuenta stock y deja el movimiento de cuenta
     *    corriente del cliente.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_desde_el_boton_crea_la_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $response = $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'));

        $response->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido desde el boton no creo la venta.');

        $this->assertEquals($cliente->id, $venta->client_id, 'La venta no quedo atada al cliente del comprador.');

        $this->assertEquals(
            $this->total_esperado(),
            (float) $venta->total,
            'La venta no arrastro el total del pedido.'
        );

        // Stock: cada renglon tiene que haber descontado su `amount`.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" no se descontó al confirmar el pedido.'
            );
        }

        $this->assertTrue(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'La venta del pedido no genero movimiento en la cuenta corriente del cliente.'
        );
    }

    /**
     * 2. La regresion que el arreglo evita: el payload del boton (solo `order_status_id`) NO puede
     *    dejar el pedido sin renglones ni sin deposito.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_desde_el_boton_no_borra_los_renglones()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $address_id_previo = $pedido->address_id;

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'))
             ->assertStatus(200);

        $pedido = $pedido->fresh();
        $pedido->load('articles');

        $this->assertCount(
            count($this->renglones()),
            $pedido->articles,
            'Confirmar desde el boton borro los renglones del pedido.'
        );

        $this->assertEquals(
            $address_id_previo,
            $pedido->address_id,
            'Confirmar desde el boton dejo el pedido sin deposito.'
        );

        $this->assertEquals(
            $this->total_esperado(),
            (float) $pedido->total,
            'Confirmar desde el boton pisó el total del pedido.'
        );

        // Y la venta que nacio de ese pedido tiene que tener los mismos renglones.
        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);

        $this->assertCount(
            count($this->renglones()),
            $venta->articles,
            'La venta nacio sin los renglones del pedido.'
        );
    }

    /**
     * 3. El candado de idempotencia: confirmar dos veces no crea dos ventas.
     *
     * Se ejercitan las dos formas en que el usuario puede repetir el gesto: apretar el boton otra
     * vez sobre el mismo estado, y seguir avanzando de estado (Confirmado -> Terminado), que es lo
     * que el boton hace en el segundo click.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_dos_veces_no_crea_dos_ventas()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'))
             ->assertStatus(200);

        $this->assertEquals(1, Sale::where('order_id', $pedido->id)->count());

        // Mismo estado de nuevo (doble click).
        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'))
             ->assertStatus(200);

        // Y el paso siguiente del boton.
        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Terminado'))
             ->assertStatus(200);

        $this->assertEquals(
            1,
            Sale::where('order_id', $pedido->id)->count(),
            'Volver a confirmar el pedido creo una segunda venta: el candado de order_id no cerro.'
        );
    }

    /**
     * 4. No regresion del camino que hoy si funciona: confirmar desde el select del formulario,
     *    que manda el modelo entero.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_desde_el_select_sigue_creando_la_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_select($pedido, 'Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar desde el select dejo de crear la venta.');

        $pedido = $pedido->fresh();
        $pedido->load('articles');

        $this->assertCount(count($this->renglones()), $pedido->articles);

        $this->assertEquals($this->total_esperado(), (float) $pedido->total);

        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" no se descontó por el camino del select.'
            );
        }
    }

    /**
     * 5. Un comprador sin cliente del ERP asociado no rompe: la venta nace sin `client_id` y sin
     *    movimiento de cuenta corriente.
     *
     * @group pedidos
     * @test
     */
    public function comprador_sin_cliente_asociado_no_rompe()
    {
        $pedido = $this->crear_pedido(null);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Un pedido de comprador sin cliente no genero venta.');

        $this->assertNull($venta->client_id, 'La venta de un comprador sin cliente quedo con client_id.');

        $this->assertFalse(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'Se genero movimiento de cuenta corriente para una venta sin cliente.'
        );

        // El stock se descuenta igual: no depender del cliente es justamente el punto.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                (float) Article::find($renglon['article']->id)->stock
            );
        }
    }

    /**
     * 6. La ruta muerta ya no existe: ni en la tabla de rutas, ni respondiendo 200.
     *
     * Se chequean las dos cosas porque son distintas, y el barrido de rutas es el que cierra el
     * caso. Un `assertNotEquals(200)` solo dice que hoy no anda: pasaria igual con un 500, o con
     * un 404 que manana otra ruta nueva se coma. Lo que prueba que la ruta se fue es que NINGUNA
     * ruta declarada tenga `order/update-status` en su URI.
     *
     * @group pedidos
     * @test
     */
    public function la_ruta_update_status_ya_no_existe()
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'order/update-status') !== false) {
                $rutas[] = implode('|', $ruta->methods()).' '.$ruta->uri();
            }
        }

        $this->assertEmpty(
            $rutas,
            'Sigue habiendo rutas apuntando a order/update-status: '.implode(', ', $rutas)
        );

        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $response = $this->putJson(
            'api/order/update-status/'.$pedido->id,
            $this->payload_del_boton('Confirmado')
        );

        $this->assertNotEquals(200, $response->getStatusCode(), 'La ruta muerta sigue respondiendo 200.');

        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'La ruta muerta llego a crear una venta.'
        );
    }

    /**
     * 7. Confirmar desde el boton NO recalcula el total del pedido.
     *
     * Los otros tests son ciegos a esto: siembran `total` = suma exacta de los renglones, que es
     * justamente el unico caso donde "recalcular" y "no tocar" dan el mismo numero. Aca el pedido
     * arranca con un total DISTINTO de la suma de sus renglones —que es lo que pasa cuando el
     * total lo puso otro (el pedido nace del lado de la tienda)— y se verifica que confirmar por
     * el boton lo deje intacto, y que la venta arrastre ese mismo numero.
     *
     * Sin este test, sacar el recalculo de adentro del `if` no rompe ninguna asercion.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_desde_el_boton_no_recalcula_el_total()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        /** Total puesto a mano, distinto de la suma de los renglones. */
        $total_ajeno = $this->total_esperado() - 37.5;

        $pedido->total = $total_ajeno;
        $pedido->save();

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_boton('Confirmado'))
             ->assertStatus(200);

        $this->assertEquals(
            $total_ajeno,
            (float) $pedido->fresh()->total,
            'Confirmar desde el boton recalculo el total del pedido y piso el valor que traia.'
        );

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);

        $this->assertEquals(
            $total_ajeno,
            (float) $venta->total,
            'La venta no arrastro el total que traia el pedido.'
        );
    }

    /**
     * 8. Un payload que no manda `order_status_id` no rompe.
     *
     * `orders.order_status_id` es NOT NULL: con la asignacion incondicional de antes, un PUT
     * parcial que no lo trajera terminaba en un QueryException 500. El estado tiene que quedar
     * como estaba y no tiene que nacer ninguna venta.
     *
     * @group pedidos
     * @test
     */
    public function un_payload_sin_estado_no_rompe()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $estado_previo = $pedido->order_status_id;

        $this->putJson('api/order/'.$pedido->id, ['address_id' => $pedido->address_id])
             ->assertStatus(200);

        $this->assertEquals(
            $estado_previo,
            $pedido->fresh()->order_status_id,
            'Un payload sin order_status_id cambio el estado del pedido.'
        );

        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'Un payload sin order_status_id llego a crear una venta.'
        );
    }
}
