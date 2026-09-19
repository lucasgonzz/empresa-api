<?php

namespace Tests\Feature\Precios;

use App\Models\Client;
use App\Models\PriceType;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Tanda 2 de la misión vender-lista-obligatoria (18/9/2026), ítem A2: `clients.price_type_id`
 * nunca queda en 0. El 0 del placeholder del form genérico de clientes (`src/models/client.js`)
 * es "ninguna lista", se guarda como null, y el pedido de la tienda que copia la lista del
 * cliente no copia un 0.
 *
 * ES LA RAÍZ DEL 0 que la tanda 1 tuvo que tolerar en la venta y el presupuesto: Trama tiene
 * 9.440 de 12.194 clientes con `price_type_id = 0`, golonorte 527 de 733, y de ahí salían las 309
 * ventas al mes con `sales.price_type_id = 0` de golonorte (el rescate de la lista del cliente
 * preguntaba `!is_null` y copiaba el 0). Los tres escritores —`ClientController::store()` y
 * `update()`, el job del archivo de intercambio y `CreateSaleOrderHelper::createSale()`— pasan
 * ahora por `PriceTypeHelper::normalizar_price_type_id()`, y la migración
 * `2026_09_18_120000_normalizar_price_type_id_cero_en_clients` limpia lo ya guardado.
 *
 * Lo que fijan estos tests: el POST y el PUT de `api/client` con 0, con '' y con una lista real;
 * el pedido de la tienda confirmado con un cliente en 0 (la venta nace con null) y con un cliente
 * con lista (la venta nace con esa lista); y que la migración deja la tabla sin ceros y es
 * idempotente.
 *
 * `EmpresaTestCase` + `PedidosDePrueba` (DatabaseTransactions, fixture de la ferretería) por el
 * camino del pedido, que es el mismo de tests/Feature/Pedidos. El cliente del ABM se crea con
 * prefijo `zz` adentro de la transacción; el cliente del fixture se pone en 0 adentro de la
 * transacción (la migración ya corrió sobre la base del slot, así que hay que escribir el 0 a
 * mano para reproducir el estado viejo).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Cliente_nunca_guarda_lista_cero_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** @var \App\Models\PriceType Una lista real del dueño, creada por el test. */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (cliente nunca guarda cero)',
            'user_id'  => $this->user_id(),
            'position' => 5,
        ]);
    }

    /**
     * El payload de POST/PUT api/client tal como lo manda el form genérico: `price_type_id` con
     * lo que tenga el select (0 al nacer).
     *
     * @param  mixed  $price_type_id
     * @param  array  $overrides
     * @return array
     */
    protected function payload_cliente($price_type_id, $overrides = [])
    {
        return array_merge([
            'name'                     => 'zz Cliente nunca guarda cero '.uniqid(),
            'email'                    => null,
            'phone'                    => null,
            'address'                  => null,
            'cuil'                     => null,
            'cuit'                     => null,
            'dni'                      => null,
            'razon_social'             => null,
            'iva_condition_id'         => null,
            'price_type_id'            => $price_type_id,
            'location_id'              => null,
            'provincia_id'             => null,
            'description'              => null,
            'saldo'                    => null,
            'moneda_id'                => 1,
            'pais_exportacion_id'      => null,
            'comercio_city_user_id'    => null,
            'seller_id'                => null,
            'link_google_maps'         => null,
            'client_reputation_id'     => null,
            'pasar_ventas_a_la_cuenta_corriente_sin_esperar_a_facturar' => 0,
            'address_id'               => null,
        ], $overrides);
    }

    /**
     * 🔴 EL CASO DEL BUG: el alta desde el form genérico con el select en 0. Antes se guardaba el 0
     * pelado; ahora queda null.
     *
     * @group precios
     * @test
     */
    public function el_alta_con_cero_guarda_null()
    {
        $response = $this->postJson('api/client', $this->payload_cliente(0));

        $response->assertStatus(201);

        $client = Client::find($response->json('model.id'));

        $this->assertNotNull($client);
        $this->assertNull($client->price_type_id, 'El 0 del placeholder no es una lista: se guarda null.');
    }

    /**
     * Lo mismo con '' (el select vacío) y con null explícito.
     *
     * @group precios
     * @test
     */
    public function el_alta_con_vacio_o_null_guarda_null()
    {
        $con_vacio = Client::find($this->postJson('api/client', $this->payload_cliente(''))->assertStatus(201)->json('model.id'));

        $this->assertNull($con_vacio->price_type_id);

        $con_null = Client::find($this->postJson('api/client', $this->payload_cliente(null))->assertStatus(201)->json('model.id'));

        $this->assertNull($con_null->price_type_id);
    }

    /**
     * Una lista real se guarda: la normalización no puede volver el campo de solo lectura.
     *
     * @group precios
     * @test
     */
    public function el_alta_con_una_lista_real_la_guarda()
    {
        $response = $this->postJson('api/client', $this->payload_cliente($this->lista->id));

        $response->assertStatus(201);

        $this->assertEquals($this->lista->id, (int) Client::find($response->json('model.id'))->price_type_id);
    }

    /**
     * La edición: un cliente con lista al que el form le manda 0 (el vendedor eligió "ninguna")
     * queda en null, no en 0; y al revés, de null a una lista real.
     *
     * @group precios
     * @test
     */
    public function la_edicion_con_cero_guarda_null_y_con_una_lista_real_la_guarda()
    {
        $client = Client::create([
            'name'          => 'zz Cliente edicion nunca guarda cero '.uniqid(),
            'user_id'       => $this->user_id(),
            'price_type_id' => $this->lista->id,
        ]);

        $this->putJson('api/client/'.$client->id, $this->payload_cliente(0, ['name' => $client->name]))->assertStatus(200);

        $this->assertNull(Client::find($client->id)->price_type_id, 'El 0 en la edición no es una lista: se guarda null.');

        $this->putJson('api/client/'.$client->id, $this->payload_cliente($this->lista->id, ['name' => $client->name]))->assertStatus(200);

        $this->assertEquals($this->lista->id, (int) Client::find($client->id)->price_type_id);
    }

    /**
     * 🔴 EL PEDIDO DE LA TIENDA con un cliente en 0: la venta que nace al confirmarlo queda con
     * `price_type_id` null, no 0. `CreateSaleOrderHelper::createSale()` preguntaba `!is_null` sobre
     * la lista del cliente y copiaba el 0.
     *
     * @group precios
     * @test
     */
    public function el_pedido_de_un_cliente_con_lista_en_cero_crea_la_venta_sin_lista()
    {
        $cliente = $this->cliente_cc();

        // La migración ya limpió la base del slot: el 0 se escribe a mano para reproducir el estado viejo.
        DB::table('clients')->where('id', $cliente->id)->update(['price_type_id' => 0]);

        $this->assertSame(0, (int) Client::find($cliente->id)->price_type_id, 'El fixture tiene que arrancar con el 0 viejo.');

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido tiene que haber creado la venta.');
        $this->assertNull($venta->price_type_id, 'La venta del pedido no puede nacer con price_type_id = 0.');
    }

    /**
     * Y con un cliente con lista real, la venta del pedido se la lleva: el rescate sigue
     * funcionando para lo que sí es una lista.
     *
     * @group precios
     * @test
     */
    public function el_pedido_de_un_cliente_con_lista_real_crea_la_venta_con_esa_lista()
    {
        $cliente = $this->cliente_cc();

        DB::table('clients')->where('id', $cliente->id)->update(['price_type_id' => $this->lista->id]);

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);
        $this->assertEquals($this->lista->id, (int) $venta->price_type_id, 'La venta del pedido se lleva la lista del cliente.');
    }

    /**
     * La migración de datos: deja la tabla sin ceros, no toca las listas reales ni los null, y
     * correrla dos veces no cambia nada. Se ejercita el `up()` de la clase directamente sobre
     * filas creadas adentro de la transacción.
     *
     * @group precios
     * @test
     */
    public function la_migracion_pasa_los_ceros_a_null_y_es_idempotente()
    {
        $en_cero = Client::create(['name' => 'zz Migracion en cero '.uniqid(), 'user_id' => $this->user_id(), 'price_type_id' => 0]);
        $con_lista = Client::create(['name' => 'zz Migracion con lista '.uniqid(), 'user_id' => $this->user_id(), 'price_type_id' => $this->lista->id]);
        $en_null = Client::create(['name' => 'zz Migracion en null '.uniqid(), 'user_id' => $this->user_id(), 'price_type_id' => null]);

        require_once base_path('database/migrations/2026_09_18_120000_normalizar_price_type_id_cero_en_clients.php');

        $migracion = new \NormalizarPriceTypeIdCeroEnClients();

        $migracion->up();

        $this->assertNull(Client::find($en_cero->id)->price_type_id, 'El 0 tiene que pasar a null.');
        $this->assertEquals($this->lista->id, (int) Client::find($con_lista->id)->price_type_id, 'Una lista real no se toca.');
        $this->assertNull(Client::find($en_null->id)->price_type_id);
        $this->assertSame(0, Client::where('price_type_id', 0)->count(), 'No puede quedar ningún cliente en 0.');

        $migracion->up();

        $this->assertEquals($this->lista->id, (int) Client::find($con_lista->id)->price_type_id, 'La segunda corrida no cambia nada.');
    }
}
