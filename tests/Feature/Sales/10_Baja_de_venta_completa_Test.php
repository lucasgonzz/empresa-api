<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Caracteriza lo que hace `SaleController@destroy` de punta a punta.
 *
 * 🔴 **Este test es el gate de una refactorización, y ese es su motivo de existir.** La misión del
 * estado del pedido por select necesita que cancelar un pedido borre su venta haciendo *todo* lo que
 * hace `destroy()`, así que el cuerpo de `destroy()` se extrae a `DeleteSaleHelper::eliminar_venta()`
 * para que los dos caminos compartan un solo lugar. Una extracción así solo es segura si hay algo
 * que mida el comportamiento ANTES y DESPUÉS.
 *
 * ⚠️ El test preexistente `4_Eliminar_venta_Test.php` NO servía para eso: ya estaba en rojo en
 * `develop` antes de esta misión, porque resuelve el cliente por el nombre 'Lucas Gonzalez', que el
 * fixture actual (`TestingFerreteriaSeeder`) no siembra — `$client` viene null y revienta en
 * `$client->id`. Es un test viejo de otro fixture. No se tocó (queda como hallazgo fuera de alcance),
 * pero tampoco se lo podía usar de red de seguridad.
 *
 * La venta se arma por el camino real: se confirma un pedido online, que es el mismo camino que
 * después va a tener que revertirse al cancelarlo.
 */
class Baja_de_venta_completa_Test extends EmpresaTestCase
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
     * Borrar una venta por el endpoint real revierte las cuatro cosas que tiene que revertir:
     * la venta queda dada de baja, vuelve el stock, se va el movimiento de cuenta corriente y el
     * pedido deja de tener venta asociada.
     *
     * @group sales
     * @test
     */
    public function borrar_una_venta_revierte_stock_y_cuenta_corriente()
    {
        $cliente = $this->cliente_cc();

        $pedido = $this->crear_pedido($cliente->id);

        $stock_antes_de_confirmar = $this->stock_actual();

        // Se confirma el pedido: nace la venta, se descuenta stock y entra a cuenta corriente.
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))
             ->assertStatus(200);

        $venta = Sale::where('order_id', $pedido->id)->first();

        $this->assertNotNull($venta, 'No se pudo armar el escenario: confirmar el pedido no creó la venta.');

        // Precondiciones: el escenario tiene que estar realmente "cargado" antes de medir la baja.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_antes_de_confirmar[$renglon['article']->id] - $renglon['amount'],
                (float) Article::find($renglon['article']->id)->stock,
                'Precondicion fallida: el stock de "'.$renglon['article']->name.'" no se descontó al confirmar.'
            );
        }

        $this->assertTrue(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'Precondicion fallida: la venta no genero movimiento de cuenta corriente.'
        );

        // La baja, por el endpoint real.
        $this->delete('api/sale/'.$venta->id)->assertStatus(200);

        // 1. La venta queda dada de baja.
        $this->assertNull(
            Sale::find($venta->id),
            'La venta sigue viva despues de borrarla.'
        );

        // 2. Vuelve el stock, exactamente al valor previo a confirmar.
        foreach ($this->renglones() as $renglon) {
            $this->assertEquals(
                $stock_antes_de_confirmar[$renglon['article']->id],
                (float) Article::find($renglon['article']->id)->stock,
                'El stock de "'.$renglon['article']->name.'" no volvio al valor previo al borrar la venta.'
            );
        }

        // 3. Se va el movimiento de cuenta corriente.
        $this->assertFalse(
            CurrentAcount::where('sale_id', $venta->id)->exists(),
            'Quedo el movimiento de cuenta corriente de una venta borrada.'
        );

        // 4. El pedido deja de tener venta asociada: es el candado que despues permite reconfirmar.
        $this->assertNull(
            Sale::where('order_id', $pedido->id)->first(),
            'El pedido sigue teniendo una venta asociada despues de borrarla.'
        );
    }
}
