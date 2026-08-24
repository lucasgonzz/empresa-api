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
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Prompt 608 — confirmar un pedido desde el boton de la fila.
 *
 * El boton "Confirmar pedido" del modal (`BtnStatus.vue`, borrado el 22/8/2026 cuando el estado
 * paso a manejarse solo desde el select) pegaba contra `PUT order/update-status/{id}`, una ruta que seguia viva pero
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
     * 1. Confirmar con payload minimo crea la venta, descuenta stock y deja el movimiento de cuenta
     *    corriente del cliente.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_con_payload_minimo_crea_la_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $response = $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'));

        $response->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'Confirmar el pedido con payload minimo no creo la venta.');

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
    public function confirmar_con_payload_minimo_no_borra_los_renglones()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $address_id_previo = $pedido->address_id;

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $pedido = $pedido->fresh();
        $pedido->load('articles');

        $this->assertCount(
            count($this->renglones()),
            $pedido->articles,
            'Confirmar con payload minimo borro los renglones del pedido.'
        );

        $this->assertEquals(
            $address_id_previo,
            $pedido->address_id,
            'Confirmar con payload minimo dejo el pedido sin deposito.'
        );

        $this->assertEquals(
            $this->total_esperado(),
            (float) $pedido->total,
            'Confirmar con payload minimo pisó el total del pedido.'
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

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $this->assertEquals(1, Sale::where('order_id', $pedido->id)->count());

        // Mismo estado de nuevo (doble click).
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        // Y el paso siguiente del boton.
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Terminado'))
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

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
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
            $this->payload_de_estado('Confirmado')
        );

        $this->assertNotEquals(200, $response->getStatusCode(), 'La ruta muerta sigue respondiendo 200.');

        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'La ruta muerta llego a crear una venta.'
        );
    }

    /**
     * 7. Confirmar con payload minimo NO recalcula el total del pedido.
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
    public function confirmar_con_payload_minimo_no_recalcula_el_total()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        /** Total puesto a mano, distinto de la suma de los renglones. */
        $total_ajeno = $this->total_esperado() - 37.5;

        $pedido->total = $total_ajeno;
        $pedido->save();

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $this->assertEquals(
            $total_ajeno,
            (float) $pedido->fresh()->total,
            'Confirmar con payload minimo recalculo el total del pedido y piso el valor que traia.'
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
     * Se prueban los DOS payloads que la columna no acepta: la clave ausente y la clave presente
     * con valor null. No son el mismo caso — `$request->has()` da true para el segundo, asi que
     * una guarda escrita con `has()` pasa el primero y rompe con el segundo.
     *
     * @group pedidos
     * @test
     */
    public function un_payload_sin_estado_no_rompe()
    {
        foreach ([[], ['order_status_id' => null]] as $extra) {

            $cliente = $this->cliente_cc();

            $pedido = $this->crear_pedido($cliente->id);

            $estado_previo = $pedido->order_status_id;

            $payload = array_merge(['address_id' => $pedido->address_id], $extra);

            $this->putJson('api/order/'.$pedido->id, $payload)
                 ->assertStatus(200);

            $this->assertEquals(
                $estado_previo,
                $pedido->fresh()->order_status_id,
                'Un payload sin order_status_id usable cambio el estado del pedido.'
            );

            $this->assertNull(
                Sale::where('order_id', $pedido->id)->first(),
                'Un payload sin order_status_id usable llego a crear una venta.'
            );
        }
    }
}
