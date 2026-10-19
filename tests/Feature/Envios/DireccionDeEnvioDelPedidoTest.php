<?php

namespace Tests\Feature\Envios;

use App\Models\Buyer;
use App\Models\BuyerAddress;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión formas-envio-direcciones-maps (19/10/2026), parte de modelos de `empresa-api`: la
 * dirección de entrega del comprador.
 *
 * Lo que fija este archivo:
 *
 * 1. El pedido trae `envio_direccion` (la copia de la dirección elegida, con coordenadas) como
 *    objeto en `GET order/{id}`: es lo que lee el modal de envío para la dirección clickeable y
 *    el mapa. Un pedido de una tienda vieja la trae en null.
 * 2. `BuyerAddress` devuelve `lat`/`lng` como número (la columna es decimal y MySQL la devuelve
 *    como string) e `is_default` como booleano; `Buyer::buyer_addresses()` las trae y NO entra
 *    en el `withAll` del comprador.
 * 3. El carrito lee `envio_direccion` como array, igual que el pedido.
 *
 * Las filas las escribe `tienda-api` en producción; acá se crean directo en la base, con la
 * forma del contrato del plan.
 *
 * @group envios
 */
class DireccionDeEnvioDelPedidoTest extends EmpresaTestCase
{
    use PedidosDePrueba;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    /**
     * La copia de la dirección tal como la deja `tienda-api` en el pedido (claves del contrato).
     *
     * @param int|null $buyer_address_id
     * @return array<string, mixed>
     */
    protected function direccion($buyer_address_id = null)
    {
        return [
            'buyer_address_id'  => $buyer_address_id,
            'formatted_address' => 'Av. Colón 123, X5000 Córdoba, Argentina',
            'street'            => 'Av. Colón',
            'street_number'     => '123',
            'apartment'         => '4 B',
            'city'              => 'Córdoba',
            'province'          => 'Córdoba',
            'zip_code'          => '5000',
            'reference'         => 'portón negro',
            'lat'               => -31.4135,
            'lng'               => -64.1811,
            'place_id'          => 'ChIJ-prueba-123',
        ];
    }

    /**
     * 1. `GET order/{id}` trae `envio_direccion` como objeto, con las coordenadas como número, y
     *    `buyer_address_id` plano. Un pedido sin dirección (tienda vieja, o retiro) la trae null.
     *
     * @return void
     */
    public function test_el_pedido_trae_la_direccion_de_envio_como_objeto()
    {
        $pedido = $this->crear_pedido(null);

        // `buyer_address_id` es clave de la copia del pedido, no columna de buyer_addresses.
        $base = $this->direccion();
        unset($base['buyer_address_id']);

        $guardada = BuyerAddress::create(array_merge($base, [
            'user_id'    => $this->user_id(),
            'buyer_id'   => $pedido->buyer_id,
            'is_default' => 1,
        ]));

        $pedido->deliver = 1;
        $pedido->buyer_address_id = $guardada->id;
        $pedido->envio_direccion = $this->direccion($guardada->id);
        $pedido->save();

        $respuesta = $this->getJson('api/order/' . $pedido->id);

        $respuesta->assertStatus(200);

        $direccion = $respuesta->json('model.envio_direccion');

        $this->assertIsArray($direccion, 'envio_direccion no se serializa como json (falta el cast en Order).');
        $this->assertSame('Av. Colón 123, X5000 Córdoba, Argentina', $direccion['formatted_address']);
        $this->assertSame('4 B', $direccion['apartment']);
        $this->assertSame('portón negro', $direccion['reference']);
        $this->assertSame(-31.4135, $direccion['lat']);
        $this->assertSame(-64.1811, $direccion['lng']);
        $this->assertSame($guardada->id, $direccion['buyer_address_id']);
        $this->assertSame($guardada->id, (int) $respuesta->json('model.buyer_address_id'));

        // En la base queda como json, no como un "Array" convertido a string.
        $crudo = DB::table('orders')->where('id', $pedido->id)->value('envio_direccion');
        $this->assertSame('Córdoba', json_decode($crudo, true)['city']);

        // Un pedido de una tienda vieja (o con retiro) no la tiene.
        $viejo = $this->crear_pedido(null);

        $this->getJson('api/order/' . $viejo->id)
             ->assertStatus(200)
             ->assertJsonPath('model.envio_direccion', null);
    }

    /**
     * 2. `BuyerAddress`: coordenadas como número e `is_default` como booleano; el comprador las
     *    trae por `buyer_addresses()`, y no por su `withAll`.
     *
     * @return void
     */
    public function test_las_direcciones_del_comprador()
    {
        $comprador = $this->crear_comprador(null);

        $base = $this->direccion();
        unset($base['buyer_address_id']);

        $principal = BuyerAddress::create(array_merge($base, [
            'user_id'    => $this->user_id(),
            'buyer_id'   => $comprador->id,
            'lat'        => '-31.4100000',
            'lng'        => '-64.1800000',
            'is_default' => 1,
        ]));

        $otra = BuyerAddress::create(array_merge($base, [
            'user_id'           => $this->user_id(),
            'buyer_id'          => $comprador->id,
            'formatted_address' => 'San Martín 50, Villa Allende, Córdoba',
            'lat'               => null,
            'lng'               => null,
            'is_default'        => 0,
        ]));

        $principal = $principal->fresh();

        $this->assertSame(-31.41, $principal->lat, 'lat tiene que salir como número, no como el string del decimal.');
        $this->assertSame(-64.18, $principal->lng);
        $this->assertTrue($principal->is_default);
        $this->assertFalse($otra->fresh()->is_default);
        $this->assertNull($otra->fresh()->lat, 'Una dirección cargada a mano (sin Google) no tiene coordenadas.');

        $this->assertSame(-31.41, $principal->toArray()['lat'], 'En el JSON la coordenada también tiene que ser número.');

        $this->assertSame(
            [$principal->id, $otra->id],
            Buyer::find($comprador->id)->buyer_addresses()->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame($comprador->id, (int) $principal->buyer->id);

        $con_todo = Buyer::where('id', $comprador->id)->withAll()->first();

        $this->assertFalse($con_todo->relationLoaded('buyer_addresses'), 'buyer_addresses no tiene que ir en el withAll del comprador.');
    }

    /**
     * 3. El carrito lee `envio_direccion` como array, igual que el pedido (de ahí la copia
     *    `tienda-api` al crear el pedido).
     *
     * @return void
     */
    public function test_el_carrito_lee_la_direccion_como_array()
    {
        $cart = Cart::create([
            'user_id'         => $this->user_id(),
            'buyer_id'        => $this->crear_comprador(null)->id,
            'deliver'         => 1,
            'envio_direccion' => $this->direccion(),
        ]);

        $direccion = $cart->fresh()->envio_direccion;

        $this->assertIsArray($direccion, 'envio_direccion del carrito no se lee como array (falta el cast en Cart).');
        $this->assertSame('Córdoba', $direccion['city']);
        $this->assertSame(-31.4135, $direccion['lat']);

        $this->assertNull(Cart::create(['user_id' => $this->user_id()])->fresh()->envio_direccion);
    }
}
