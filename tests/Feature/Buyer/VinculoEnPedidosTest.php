<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Order;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión vincular-comprador-desde-pedidos (24/9/2026): lo que la tabla de Pedidos necesita del
 * vínculo, y lo que el vínculo cambia en los pedidos que se confirman después.
 *
 * Dos cosas:
 *
 *  1. EL PAYLOAD. `Order::scopeWithAll` pasa a cargar `buyer.comercio_city_client`, y de ahí decide
 *     la SPA si dibuja el badge "Sin vincular": el cliente completo si el comprador está vinculado,
 *     `null` si no lo está Y TAMBIÉN `null` si el cliente fue borrado (`SoftDeletes`), que es lo que
 *     ya asume `CreateSaleOrderHelper::get_client_id()`. Se prueba en los cuatro caminos que arman
 *     pedidos con `withAll()`: el listado, los sin confirmar, el pedido suelto y el buscador genérico.
 *  2. LA RAZÓN DE SER, DE PUNTA A PUNTA. Un pedido de un comprador sin vincular se confirma y su
 *     venta nace con `client_id` null y sin cuenta corriente (el hueco que el badge deja a la
 *     vista). Se vincula el comprador por el endpoint nuevo y el pedido que se confirma DESPUÉS
 *     nace con el cliente y su movimiento de cuenta corriente.
 *
 * Usa el fixture de la ferretería (`PedidosDePrueba`) y el usuario que autentica `EmpresaTestCase`.
 * PHP 7.4.
 */
class VinculoEnPedidosTest extends EmpresaTestCase
{
    use PedidosDePrueba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_estados_de_pedido();
    }

    // -------------------------------------------------------------------------------------------
    //  Ayudas
    // -------------------------------------------------------------------------------------------

    /**
     * Crea otro pedido "Sin confirmar" del MISMO comprador. `PedidosDePrueba::crear_pedido()` arma
     * un comprador nuevo en cada llamada, y para probar "el pedido que se confirma después de
     * vincular" hace falta el mismo comprador. Mismos renglones y mismo depósito que ese fixture.
     *
     * @param  \App\Models\Buyer  $comprador
     * @return \App\Models\Order
     */
    protected function crear_otro_pedido_del_comprador($comprador)
    {
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
     * El bloque `buyer` de cada pedido de una lista, indexado por id de pedido.
     *
     * @param  array  $pedidos  `models` de la respuesta.
     * @return array
     */
    protected function compradores_por_pedido($pedidos)
    {
        $compradores = [];

        foreach ($pedidos as $pedido) {
            $compradores[$pedido['id']] = $pedido['buyer'];
        }

        return $compradores;
    }

    /**
     * Tres pedidos "Sin confirmar", uno por caso que la SPA tiene que distinguir: comprador
     * vinculado, comprador sin vincular y vínculo colgante (cliente borrado).
     *
     * @return array<string,\App\Models\Order>  Por clave: `vinculado`, `sin_vincular`, `colgante`.
     */
    protected function pedidos_de_los_tres_casos()
    {
        $vinculado = $this->crear_pedido($this->cliente_cc()->id);

        $sin_vincular = $this->crear_pedido(null);

        $cliente_que_se_borra = Client::create(['name' => 'Cliente que se borra', 'user_id' => $this->user_id()]);
        $colgante = $this->crear_pedido($cliente_que_se_borra->id);
        $cliente_que_se_borra->delete();

        return [
            'vinculado'    => $vinculado,
            'sin_vincular' => $sin_vincular,
            'colgante'     => $colgante,
        ];
    }

    /**
     * Afirma, sobre el `buyer` de los tres pedidos de `pedidos_de_los_tres_casos()`, lo que la SPA
     * usa para decidir el badge.
     *
     * @param  array  $compradores  Salida de `compradores_por_pedido()`.
     * @param  array<string,\App\Models\Order>  $pedidos
     * @param  string  $camino  Para el mensaje de error: qué endpoint se está mirando.
     * @return void
     */
    protected function afirmar_los_tres_casos($compradores, $pedidos, $camino)
    {
        $cliente = $this->cliente_cc();

        // Vinculado: el cliente ENTERO (`num` y `price_type_id` los lee ProcessArchivoDeIntercambioPedidos).
        $vinculado = $compradores[$pedidos['vinculado']->id];

        $this->assertArrayHasKey('comercio_city_client', $vinculado, $camino.': el comprador vinculado no trae el cliente.');
        $this->assertIsArray($vinculado['comercio_city_client'], $camino);
        $this->assertSame($cliente->id, $vinculado['comercio_city_client']['id'], $camino);
        $this->assertSame($cliente->id, $vinculado['comercio_city_client_id'], $camino);
        $this->assertSame('Cliente Cuenta Corriente', $vinculado['comercio_city_client']['name'], $camino);
        $this->assertArrayHasKey('num', $vinculado['comercio_city_client'], $camino.': la relación tiene que venir completa.');
        $this->assertArrayHasKey('price_type_id', $vinculado['comercio_city_client'], $camino.': la relación tiene que venir completa.');

        // Sin vincular: la clave está y es null (no ausente: ausente es "API vieja" para la SPA).
        $sin_vincular = $compradores[$pedidos['sin_vincular']->id];

        $this->assertArrayHasKey('comercio_city_client', $sin_vincular, $camino.': la clave tiene que estar, con null.');
        $this->assertNull($sin_vincular['comercio_city_client'], $camino);
        $this->assertNull($sin_vincular['comercio_city_client_id'], $camino);

        // Vínculo colgante: el id sigue puesto pero el cliente está borrado, así que llega null.
        $colgante = $compradores[$pedidos['colgante']->id];

        $this->assertArrayHasKey('comercio_city_client', $colgante, $camino);
        $this->assertNull($colgante['comercio_city_client'], $camino.': un cliente borrado tiene que llegar como null.');
        $this->assertNotNull($colgante['comercio_city_client_id'], $camino.': el id del vínculo colgante sigue en el comprador.');
    }

    // -------------------------------------------------------------------------------------------
    //  El payload de Pedidos
    // -------------------------------------------------------------------------------------------

    /**
     * El listado de pedidos (`GET api/order`) y el de sin confirmar (`GET api/order/unconfirmed/models`)
     * traen el cliente del comprador: objeto si está vinculado, `null` si no lo está y `null` si el
     * cliente fue borrado.
     *
     * @test
     * @return void
     */
    public function el_listado_de_pedidos_trae_el_cliente_del_comprador()
    {
        $pedidos = $this->pedidos_de_los_tres_casos();

        $listado = $this->getJson('api/order');
        $listado->assertStatus(200);

        $this->afirmar_los_tres_casos($this->compradores_por_pedido($listado->json()['models']), $pedidos, 'GET api/order');

        $sin_confirmar = $this->getJson('api/order/unconfirmed/models');
        $sin_confirmar->assertStatus(200);

        $this->afirmar_los_tres_casos($this->compradores_por_pedido($sin_confirmar->json()['models']), $pedidos, 'GET api/order/unconfirmed/models');
    }

    /**
     * El pedido suelto (`GET api/order/{id}`, el que abre el modal) trae lo mismo.
     *
     * @test
     * @return void
     */
    public function el_pedido_suelto_trae_el_cliente_del_comprador()
    {
        $pedidos = $this->pedidos_de_los_tres_casos();

        $compradores = [];

        foreach ($pedidos as $pedido) {

            $respuesta = $this->getJson('api/order/'.$pedido->id);
            $respuesta->assertStatus(200);

            $compradores[$pedido->id] = $respuesta->json()['model']['buyer'];
        }

        $this->afirmar_los_tres_casos($compradores, $pedidos, 'GET api/order/{id}');
    }

    /**
     * El buscador genérico de la tabla (`POST api/search/order`) también arma los pedidos con
     * `withAll()`: filtrar o paginar la tabla de Pedidos no puede perder el vínculo del comprador.
     *
     * @test
     * @return void
     */
    public function el_buscador_generico_de_pedidos_trae_el_cliente_del_comprador()
    {
        $pedidos = $this->pedidos_de_los_tres_casos();

        $respuesta = $this->postJson('api/search/order', ['filters' => []]);
        $respuesta->assertStatus(200);

        $this->afirmar_los_tres_casos($this->compradores_por_pedido($respuesta->json()['models']), $pedidos, 'POST api/search/order');
    }

    /**
     * Cambiar el estado de un pedido (`PUT api/order/{id}`) devuelve el pedido completo, y la fila de
     * la tabla se refresca con esa respuesta: tiene que traer el cliente del comprador o el badge
     * "Sin vincular" aparecería de golpe en un pedido vinculado.
     *
     * @test
     * @return void
     */
    public function la_respuesta_de_cambiar_el_estado_trae_el_cliente_del_comprador()
    {
        $vinculado = $this->crear_pedido($this->cliente_cc()->id);

        $respuesta = $this->putJson('api/order/'.$vinculado->id, $this->payload_de_estado('Confirmado'));
        $respuesta->assertStatus(200);

        $this->assertSame(
            $this->cliente_cc()->id,
            $respuesta->json()['model']['buyer']['comercio_city_client']['id']
        );
    }

    /**
     * El cliente del comprador se carga con UNA consulta para todo el listado, no una por pedido:
     * el badge no puede volver a pesar más el listado cuantos más pedidos haya.
     *
     * @test
     * @return void
     */
    public function el_cliente_del_comprador_se_carga_con_una_sola_consulta()
    {
        $cliente = $this->cliente_cc();

        for ($i = 0; $i < 6; $i++) {
            $this->crear_pedido($cliente->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('api/order')->assertStatus(200);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $de_clientes = 0;

        foreach ($consultas as $consulta) {

            if (preg_match('/^select \* from `clients`/i', $consulta['query'])) {
                $de_clientes++;
            }
        }

        $this->assertSame(1, $de_clientes, 'El cliente del comprador se tiene que traer con un solo eager load, no uno por pedido.');
    }

    // -------------------------------------------------------------------------------------------
    //  De punta a punta
    // -------------------------------------------------------------------------------------------

    /**
     * 🔴 EL TEST QUE IMPORTA. Es para lo que existe el badge.
     *
     * Un pedido "Sin confirmar" de un comprador sin vincular se confirma: la venta nace con
     * `client_id` null y SIN movimiento de cuenta corriente. Se vincula el comprador por el endpoint
     * nuevo y se confirma OTRO pedido del mismo comprador: esa venta nace con el cliente y con su
     * movimiento de cuenta corriente. La venta anterior no se toca (reasignar las ya creadas queda
     * fuera del alcance de la misión).
     *
     * @test
     * @return void
     */
    public function el_pedido_que_se_confirma_despues_de_vincular_nace_con_el_cliente()
    {
        $cliente = $this->cliente_cc();

        // 1. Un pedido sin confirmar de un comprador sin vincular.
        $primero = $this->crear_pedido(null);
        $comprador = Buyer::find($primero->buyer_id);

        $this->assertNull($comprador->comercio_city_client_id);

        // 2. Se confirma: la venta nace sin cliente y sin cuenta corriente.
        $this->putJson('api/order/'.$primero->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $venta_sin_cliente = Sale::where('order_id', $primero->id)->first();

        $this->assertNotNull($venta_sin_cliente, 'El pedido confirmado no generó su venta.');
        $this->assertNull($venta_sin_cliente->client_id, 'Un comprador sin vincular tiene que dar una venta sin cliente.');
        $this->assertFalse(
            CurrentAcount::where('sale_id', $venta_sin_cliente->id)->exists(),
            'Una venta sin cliente no puede tener movimiento de cuenta corriente.'
        );

        // 3. Otro pedido del MISMO comprador, todavía sin confirmar. La tabla lo muestra sin vincular.
        $segundo = $this->crear_otro_pedido_del_comprador($comprador);

        $antes = $this->compradores_por_pedido($this->getJson('api/order')->json()['models']);

        $this->assertArrayHasKey('comercio_city_client', $antes[$segundo->id]);
        $this->assertNull($antes[$segundo->id]['comercio_city_client'], 'Antes de vincular el pedido tiene que salir "Sin vincular".');

        // 4. Se vincula con el endpoint nuevo.
        $this->postJson('api/buyer/'.$comprador->id.'/vincular-cliente', ['client_id' => $cliente->id])
             ->assertStatus(200);

        // 5. La tabla de Pedidos ya no lo muestra sin vincular: los DOS pedidos del comprador traen el cliente.
        $despues = $this->compradores_por_pedido($this->getJson('api/order')->json()['models']);

        $this->assertSame($cliente->id, $despues[$primero->id]['comercio_city_client']['id']);
        $this->assertSame($cliente->id, $despues[$segundo->id]['comercio_city_client']['id']);

        // 6. Se confirma el segundo: la venta nace con el cliente y su cuenta corriente.
        $this->putJson('api/order/'.$segundo->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $venta_con_cliente = Sale::where('order_id', $segundo->id)->first();

        $this->assertNotNull($venta_con_cliente, 'El segundo pedido confirmado no generó su venta.');
        $this->assertEquals($cliente->id, $venta_con_cliente->client_id, 'Después de vincular, la venta del pedido tiene que nacer con el cliente.');
        $this->assertTrue(
            CurrentAcount::where('sale_id', $venta_con_cliente->id)->exists(),
            'La venta con cliente tiene que dejar su movimiento en la cuenta corriente.'
        );

        // 7. La venta anterior no se reasigna sola.
        $this->assertNull(
            Sale::find($venta_sin_cliente->id)->client_id,
            'Vincular no reasigna las ventas que ya se crearon (fuera del alcance de la misión).'
        );
    }

    /**
     * Un vínculo colgante —el cliente al que apuntaba se borró— se puede reparar desde el mismo
     * modal: vincular sin `reemplazar` y el pedido que se confirma después nace con el cliente
     * nuevo. Es lo que el badge le promete a quien lo ve sobre un vínculo colgante.
     *
     * @test
     * @return void
     */
    public function un_vinculo_colgante_se_repara_y_el_pedido_siguiente_nace_con_el_cliente()
    {
        $cliente = $this->cliente_cc();

        $cliente_que_se_borra = Client::create(['name' => 'Cliente que se borra', 'user_id' => $this->user_id()]);

        $pedido = $this->crear_pedido($cliente_que_se_borra->id);
        $comprador = Buyer::find($pedido->buyer_id);

        $cliente_que_se_borra->delete();

        $this->postJson('api/buyer/'.$comprador->id.'/vincular-cliente', ['client_id' => $cliente->id])
             ->assertStatus(200);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta);
        $this->assertEquals($cliente->id, $venta->client_id);
    }
}
