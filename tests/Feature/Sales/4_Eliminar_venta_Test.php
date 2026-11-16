<?php

namespace Tests\Feature\Sales;

use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

class Eliminar_venta_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * La venta nace por el endpoint real en este mismo escenario: no depende de clientes de
     * fixtures antiguos ni de la última venta que haya dejado otra corrida.
     *
     * @group sales
     * @test
     */
    public function eliminar_venta()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $this->assertNotNull($articulo, 'El fixture debe traer el artículo para medir su stock.');

        $stock_antes = (float) $articulo->stock;
        $cantidad = 2;
        $precio = 42137;

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $precio * $cantidad,
            ['items' => [[
                'is_article' => true,
                'id' => $articulo->id,
                'price_vender' => $precio,
                'amount' => $cantidad,
            ]]]
        );

        $this->assertNotNull($venta, 'El alta real debe devolver la venta que se va a borrar.');
        $this->assertTrue($venta->articles()->where('articles.id', $articulo->id)->exists());
        $this->assertEquals($stock_antes - $cantidad, (float) $articulo->fresh()->stock, 'La creación descontó el stock antes de medir la baja.');

        $this->delete('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertNull(Sale::find($venta->id), 'La venta propia quedó dada de baja.');
        $this->assertEquals($stock_antes, (float) $articulo->fresh()->stock, 'Borrar la venta devolvió todo su stock.');
    }
}
