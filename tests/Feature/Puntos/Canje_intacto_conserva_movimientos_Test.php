<?php

namespace Tests\Feature\Puntos;

use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

class Canje_intacto_conserva_movimientos_Test extends PuntosTestCase
{
    /** @test */
    public function guardar_un_canje_intacto_conserva_el_movimiento_y_los_consumos_fifo()
    {
        $this->dar_extencion();
        $sistema = $this->crear_programa(['valor_punto' => 100, 'minimo_canje' => 10, 'tope_porcentaje' => 20]);
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $this->sembrar_lote($cliente, $sistema, 8, Carbon::now()->addYear(), Carbon::now()->subDays(30));
        $this->sembrar_lote($cliente, $sistema, 20, Carbon::now()->addYear(), Carbon::now()->subDays(5));
        $items = [[
            'is_article' => true,
            'id' => $this->articulo_de_puntos()->id,
            'price_vender' => 10000,
            'amount' => 1,
        ]];
        $venta = $this->crear_venta($this->payload_venta($cliente->id, 8500, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados' => 15,
            'descuento_puntos' => 1500,
            'items' => $items,
        ]));
        $canje = $this->movimientos_de_la_venta($venta, 'canjeados')->first();
        $this->assertNotNull($canje);
        $antes = $canje->getAttributes();
        $consumos = $this->consumos_de($canje)->map(function ($fila) { return $fila->getAttributes(); })->all();
        $this->assertCount(2, $consumos);

        for ($i = 0; $i < 2; $i++) {
            $this->actualizar_venta($venta, $this->payload_actualizar($venta, 8500, [
                'omitir_en_cuenta_corriente' => 1,
                'puntos_canjeados' => 15,
                'descuento_puntos' => 1500,
                'items' => $items,
                'observations' => 'Se entrega el viernes',
            ]))->assertStatus(200);
            $actual = $this->movimientos_de_la_venta($venta, 'canjeados')->first();
            $this->assertSame($antes, $actual->getAttributes());
            $this->assertSame($consumos, $this->consumos_de($actual)->map(function ($fila) { return $fila->getAttributes(); })->all());
            $this->assertEquals(1500, (float) $venta->fresh()->descuento_puntos);
        }

        // Sacarlo sigue siendo una edición real: devuelve los puntos y elimina el canje.
        $this->actualizar_venta($venta, $this->payload_actualizar($venta, 10000, [
            'omitir_en_cuenta_corriente' => 1, 'items' => $items,
        ]))->assertStatus(200);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'canjeados'));
        $this->assertNull($venta->fresh()->puntos_canjeados);
    }
}
