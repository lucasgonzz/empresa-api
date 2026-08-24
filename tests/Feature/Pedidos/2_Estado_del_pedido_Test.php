<?php

namespace Tests\Feature\Pedidos;

use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\OrderStatus;
use App\Models\Sale;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * La máquina de estados del pedido online (decisión de Lucas, 22/8/2026).
 *
 * Desde esta misión el estado se maneja ÚNICAMENTE desde el select del formulario: se sacaron del
 * modal los botones "Confirmar pedido" y "Cancelar pedido". El select ofrece todas las filas de
 * `order_statuses`, así que la regla tiene que vivir en el backend y no en el componente:
 *
 *  - El estado solo puede AVANZAR (salteando si hace falta) o cancelarse.
 *  - "Entregado" y "Cancelado" son terminales.
 *  - Quedarse en el mismo estado siempre se permite (el formulario reenvía el modelo entero).
 *  - Confirmar por primera vez crea la venta; cancelar la borra con el camino completo de
 *    `SaleController@destroy`.
 */
class Estado_del_pedido_Test extends EmpresaTestCase
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
     * Lleva un pedido a un estado, sin pasar por las guardas: se escribe la columna directo.
     *
     * Sirve para ARMAR el escenario de un test, nunca para ejercitar el camino que se mide. Si el
     * escenario se armara con PUTs, un test de "no se puede volver para atrás" dependería de que
     * el avance funcione, y un solo bug haría fallar los dos.
     *
     * @param  \App\Models\Order  $pedido
     * @param  string  $nombre_estado
     * @return \App\Models\Order
     */
    protected function forzar_estado($pedido, $nombre_estado)
    {
        $pedido->order_status_id = $this->estado($nombre_estado)->id;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * 1. Confirmar crea la venta. Es el camino normal del select.
     *
     * @group pedidos
     * @test
     */
    public function confirmar_desde_el_select_crea_la_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_del_select($pedido, 'Confirmado'))
             ->assertStatus(200);

        $this->assertNotNull(
            Sale::where('order_id', $pedido->id)->first(),
            'Confirmar desde el select no creo la venta.'
        );
    }

    /**
     * 2. Saltear hacia adelante está permitido, y confirma igual: de "Sin confirmar" derecho a
     *    "Entregado" tiene que nacer la venta lo mismo.
     *
     * Es el caso del mostrador que entrega en el acto.
     *
     * @group pedidos
     * @test
     */
    public function saltear_estados_hacia_adelante_confirma_igual()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Entregado'))
             ->assertStatus(200);

        $this->assertEquals(
            $this->estado('Entregado')->id,
            $pedido->fresh()->order_status_id,
            'El pedido no quedo en Entregado.'
        );

        $this->assertNotNull(
            Sale::where('order_id', $pedido->id)->first(),
            'Saltear de Sin confirmar a Entregado no creo la venta.'
        );

        // Y la venta descontó stock igual que por el camino de a un paso.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id] - $renglon['amount'],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" no se descontó al saltear estados.'
            );
        }
    }

    /**
     * 3. No se puede volver para atrás, y el rechazo no deja nada escrito.
     *
     * @group pedidos
     * @test
     */
    public function no_se_puede_volver_para_atras()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'No se pudo armar el escenario: el pedido no se confirmó.');

        $response = $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Sin confirmar'));

        $response->assertStatus(422);

        $response->assertJson(['error_transicion_de_estado' => true]);

        $this->assertEquals(
            $this->estado('Confirmado')->id,
            $pedido->fresh()->order_status_id,
            'El rechazo igual cambio el estado del pedido.'
        );

        $this->assertNotNull(
            Sale::find($venta->id),
            'El rechazo se llevo puesta la venta.'
        );
    }

    /**
     * 4. Cancelar un pedido confirmado borra la venta con el camino completo: vuelve el stock y se
     *    va el movimiento de cuenta corriente.
     *
     * @group pedidos
     * @test
     */
    public function cancelar_un_pedido_confirmado_borra_la_venta()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_antes_de_confirmar = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'No se pudo armar el escenario: el pedido no se confirmó.');

        $this->assertTrue(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'No se pudo armar el escenario: la venta no genero movimiento de cuenta corriente.'
        );

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))
             ->assertStatus(200);

        $this->assertEquals(
            $this->estado('Cancelado')->id,
            $pedido->fresh()->order_status_id,
            'El pedido no quedo cancelado.'
        );

        $this->assertNull(
            Sale::find($venta->id),
            'Cancelar el pedido no borro su venta.'
        );

        // El stock vuelve exactamente al valor previo a confirmar: ni una unidad de mas.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_antes_de_confirmar[$renglon['article']->id],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" no volvio al cancelar el pedido.'
            );
        }

        $this->assertFalse(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'Quedo el movimiento de cuenta corriente de la venta de un pedido cancelado.'
        );
    }

    /**
     * 5. 🔴 Cancelar un pedido SIN confirmar no toca el stock.
     *
     * Es la regresión del bug que tenía el `cancel()` que esta misión borró: llamaba a
     * `OrderHelper::restartArticleStock()`, que devolvía stock que el pedido nunca había
     * descontado — el pedido de la tienda no toca stock, el único que descuenta es la venta. Cada
     * cancelación de un pedido sin confirmar inflaba el inventario con unidades inexistentes.
     *
     * @group pedidos
     * @test
     */
    public function cancelar_un_pedido_sin_confirmar_no_infla_el_stock()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_previo = $this->stock_actual();

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))
             ->assertStatus(200);

        $this->assertEquals(
            $this->estado('Cancelado')->id,
            $pedido->fresh()->order_status_id,
            'El pedido no quedo cancelado.'
        );

        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_previo[$renglon['article']->id],
                (float) Article::find($renglon['article']->id)->stock,
                'Cancelar un pedido sin confirmar movio el stock de "'.$renglon['article']->name.'".'
            );
        }

        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'Cancelar un pedido sin confirmar creo una venta.'
        );
    }

    /**
     * 6. Una venta ya facturada en AFIP y sin nota de crédito frena la cancelación, y no deja nada
     *    a medias.
     *
     * @group pedidos
     * @test
     */
    public function no_se_cancela_un_pedido_con_la_venta_facturada()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'No se pudo armar el escenario: el pedido no se confirmó.');

        /*
            Comprobante fiscal emitido para esta venta, sin nota de credito.

            La distincion la hace la COLUMNA, no el tipo: `afip_tickets()` cuelga de `sale_id` y
            `nota_credito_afip_tickets()` de `sale_nota_credito_id` (ver App\Models\Sale). Una fila
            con `sale_id` y sin `sale_nota_credito_id` es exactamente "facturada y sin nota de
            credito", que es el caso que tiene que frenar la cancelacion.
        */
        AfipTicket::create([
            'sale_id'        => $venta->id,
            'cae'            => '71234567890123',
            'cbte_letra'     => 'B',
            'cbte_numero'    => '1',
            'punto_venta'    => '1',
            'importe_total'  => $venta->total,
            'resultado'      => 'A',
        ]);

        $stock_con_la_venta_viva = $this->stock_actual();

        $response = $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'));

        $response->assertStatus(422);

        $response->assertJson(['error_venta_facturada' => true]);

        $this->assertNotNull(
            Sale::find($venta->id),
            'Se borro una venta que ya estaba facturada.'
        );

        $this->assertEquals(
            $this->estado('Confirmado')->id,
            $pedido->fresh()->order_status_id,
            'El pedido quedo cancelado a pesar de que la cancelacion fue rechazada.'
        );

        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_con_la_venta_viva[$renglon['article']->id],
                (float) Article::find($renglon['article']->id)->stock,
                'El rechazo igual movio el stock de "'.$renglon['article']->name.'".'
            );
        }
    }

    /**
     * 7. Desde "Cancelado" no se sale a ningún lado.
     *
     * @group pedidos
     * @test
     */
    public function desde_cancelado_no_se_puede_mover()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->forzar_estado($this->crear_pedido($cliente->id), 'Cancelado');

        foreach (['Sin confirmar', 'Confirmado', 'Terminado', 'Entregado'] as $destino) {

            $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado($destino))
                 ->assertStatus(422);

            $this->assertEquals(
                $this->estado('Cancelado')->id,
                $pedido->fresh()->order_status_id,
                'Un pedido cancelado se pudo mover a "'.$destino.'".'
            );
        }

        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'Mover un pedido cancelado llego a crear una venta.'
        );
    }

    /**
     * 8. "Entregado" es terminal: ni avanza ni se cancela.
     *
     * @group pedidos
     * @test
     */
    public function desde_entregado_no_se_puede_mover_ni_cancelar()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->forzar_estado($this->crear_pedido($cliente->id), 'Entregado');

        foreach (['Sin confirmar', 'Confirmado', 'Terminado', 'Cancelado'] as $destino) {

            $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado($destino))
                 ->assertStatus(422);

            $this->assertEquals(
                $this->estado('Entregado')->id,
                $pedido->fresh()->order_status_id,
                'Un pedido entregado se pudo mover a "'.$destino.'".'
            );
        }
    }

    /**
     * 9. 🔴 Guardar el formulario SIN cambiar el estado siempre se permite, incluso desde un estado
     *    terminal.
     *
     * No es un detalle: el formulario genérico manda el modelo entero, así que editar el depósito o
     * una nota de un pedido ya entregado reenvía su `order_status_id` sin cambiarlo. Si eso diera
     * 422, el pedido quedaría imposible de editar para siempre.
     *
     * @group pedidos
     * @test
     */
    public function guardar_sin_cambiar_el_estado_siempre_se_permite()
    {
        $cliente = $this->cliente_cc();

        foreach (['Sin confirmar', 'Confirmado', 'Terminado', 'Entregado', 'Cancelado'] as $nombre_estado) {

            $pedido = $this->forzar_estado($this->crear_pedido($cliente->id), $nombre_estado);

            /**
             * Se usa el payload del FORMULARIO (modelo entero, con renglones y deposito) y no el
             * minimo, porque este es justamente el caso que el docblock describe: editar el
             * deposito o una nota de un pedido ya entregado. El formulario reenvia el estado
             * aunque el usuario no lo haya tocado.
             */
            $this->putJson('api/order/'.$pedido->id, $this->payload_del_select($pedido, $nombre_estado))
                 ->assertStatus(200);

            $this->assertEquals(
                $this->estado($nombre_estado)->id,
                $pedido->fresh()->order_status_id,
                'Guardar sin cambiar el estado movio un pedido en "'.$nombre_estado.'".'
            );

            /**
             * 🔴 Reenviar el mismo estado NO crea venta, y "Sin confirmar" entra en la lista.
             *
             * Es el unico estado donde `develop` SI creaba la venta al reenviarlo: la condicion
             * vieja solo miraba que el estado nuevo no fuera "Cancelado", asi que un guardado del
             * formulario sobre un pedido sin confirmar —cambiarle el deposito, por ejemplo— lo
             * confirmaba de callado. `es_la_confirmacion()` ahora exige que el estado CAMBIE.
             */
            $this->assertNull(
                Sale::where('order_id', $pedido->id)->first(),
                'Reenviar el estado "'.$nombre_estado.'" creo una venta.'
            );
        }
    }

    /**
     * 11. La otra mitad de la condicion de AFIP: una venta facturada que YA tiene su nota de
     *     crédito sí se puede cancelar.
     *
     * El bloqueo es "hay comprobante y todavía no se revirtió", no "hubo comprobante alguna vez".
     * Sin este test, cambiar la condición a un bloqueo absoluto no rompería nada y el pedido
     * quedaría imposible de cancelar para siempre.
     *
     * @group pedidos
     * @test
     */
    public function una_venta_facturada_con_nota_de_credito_si_se_puede_cancelar()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'No se pudo armar el escenario: el pedido no se confirmó.');

        // La factura...
        AfipTicket::create([
            'sale_id'        => $venta->id,
            'cae'            => '71234567890123',
            'cbte_letra'     => 'B',
            'cbte_numero'    => '1',
            'punto_venta'    => '1',
            'importe_total'  => $venta->total,
            'resultado'      => 'A',
        ]);

        /*
            ...y su nota de credito. La distincion es la COLUMNA: `nota_credito_afip_tickets()`
            cuelga de `sale_nota_credito_id`, no de `sale_id`.
        */
        AfipTicket::create([
            'sale_nota_credito_id' => $venta->id,
            'cae'                  => '71234567890124',
            'cbte_letra'           => 'B',
            'cbte_numero'          => '2',
            'punto_venta'          => '1',
            'importe_total'        => $venta->total,
            'resultado'            => 'A',
        ]);

        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))
             ->assertStatus(200);

        $this->assertEquals(
            $this->estado('Cancelado')->id,
            $pedido->fresh()->order_status_id,
            'El pedido no quedo cancelado teniendo la nota de credito hecha.'
        );

        $this->assertNull(
            Sale::find($venta->id),
            'Con la nota de credito hecha, cancelar el pedido igual no borro la venta.'
        );
    }

    /**
     * 12. Un `order_status_id` que no existe se rechaza y no se escribe.
     *
     * `orders.order_status_id` no tiene foreign key, asi que un id basura se escribia igual y
     * dejaba el pedido en un estado inexistente — y desde ahi `$prev_status` es null y CUALQUIER
     * transicion pasa. El candado se abria por el mismo agujero que dice cerrar.
     *
     * @group pedidos
     * @test
     */
    public function un_estado_inexistente_se_rechaza()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $estado_previo = $pedido->order_status_id;

        /** Id que seguro no existe en `order_statuses`. */
        $id_inexistente = ((int) OrderStatus::max('id')) + 1000;

        $response = $this->putJson('api/order/'.$pedido->id, ['order_status_id' => $id_inexistente]);

        $response->assertStatus(422);

        $response->assertJson(['error_transicion_de_estado' => true]);

        $this->assertEquals(
            $estado_previo,
            $pedido->fresh()->order_status_id,
            'Se escribio un order_status_id que no existe.'
        );
    }

    /**
     * 10. La ruta `order/cancel` ya no existe: cancelar es una transición más del select.
     *
     * Se mira la tabla de rutas y no el código de respuesta, por el mismo motivo que el test de
     * `order/update-status`: un no-200 puede ser casualidad de otro match, y mañana una ruta nueva
     * podría comerse la URI.
     *
     * @group pedidos
     * @test
     */
    public function la_ruta_order_cancel_ya_no_existe()
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'order/cancel') !== false) {
                $rutas[] = implode('|', $ruta->methods()).' '.$ruta->uri();
            }
        }

        $this->assertEmpty(
            $rutas,
            'Sigue habiendo rutas apuntando a order/cancel: '.implode(', ', $rutas)
        );
    }
}
