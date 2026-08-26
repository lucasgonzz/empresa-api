<?php

namespace Tests\Feature\ProduccionV2;

/**
 * La ruta declara EN QUE ESTADO la unidad queda terminada, y eso le gana al ultimo estado de la
 * cuenta.
 *
 * Es la mitad del pedido de Quino que hoy no se podia armar: en una fabrica de sillas, la pata
 * queda terminada en "Lijado" y ahi entra a stock como parte, mucho antes de que exista una
 * silla. Hasta esta mision el producto solo entraba a stock en el estado de mayor position de
 * TODA la cuenta, o sea uno solo para todos los productos.
 *
 * El test verifica las dos mitades, que es lo que importa: que entre en el estado correcto Y que
 * NO entre en el global.
 */
class Estado_final_por_ruta_Test extends ProduccionV2TestCase
{
    /**
     * @group produccion_v2
     * @test
     */
    public function el_producto_entra_a_stock_en_el_estado_final_de_la_ruta_y_no_en_el_global()
    {
        $corte     = $this->crear_estado('Corte', 1);
        $soldado   = $this->crear_estado('Soldado', 2);
        $global    = $this->crear_estado('Terminado global', 3);

        $cano  = $this->crear_articulo('Cano ruta test', 100);
        $silla = $this->crear_articulo('Pata silla test', 0);

        $receta = $this->crear_receta($silla);

        /* La ruta declara que la unidad queda terminada en `Soldado`, no en el ultimo global. */
        $ruta = $this->crear_ruta($receta, [
            [
                'article'                       => $cano,
                'amount'                        => 2,
                'order_production_status_id'    => $corte->id,
            ],
        ], [
            'end_order_production_status_id' => $soldado->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 20);

        $tipo = $this->crear_tipo_de_movimiento('Avance', 'advance');

        /*
         * ── Hacia `Soldado`, el estado final DE LA RUTA ───────────────────────────────────────
         */
        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $soldado->id,
            'amount'                            => 7,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(7, $this->stock_de($silla));

        /* El concepto 17 es `Produccion`. */
        $this->assertDatabaseHas('stock_movements', [
            'article_id'                    => $silla->id,
            'amount'                        => 7,
            'concepto_stock_movement_id'    => 17,
        ]);

        /* Y el hecho quedo registrado en el movimiento, no se recalcula despues. */
        $this->assertDatabaseHas('production_batch_movements', [
            'id'                    => $respuesta->json('model.id'),
            'output_stock_applied'  => 1,
        ]);

        /*
         * ── Hacia `Terminado global`, que es el de mayor position de la cuenta ────────────────
         * La ruta ya declaro su estado final, asi que el global NO tiene que sumar nada.
         */
        $respuesta_global = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'from_order_production_status_id'   => $soldado->id,
            'to_order_production_status_id'     => $global->id,
            'amount'                            => 7,
        ]);

        $respuesta_global->assertStatus(201);

        /* Sigue en 7: el segundo movimiento no sumo otra vez. */
        $this->assertEquals(7, $this->stock_de($silla));

        $this->assertDatabaseHas('production_batch_movements', [
            'id'                    => $respuesta_global->json('model.id'),
            'output_stock_applied'  => 0,
        ]);

        /* El estado de corte nunca se uso en este lote. */
        $this->assertEquals(0, $lote->production_batch_movements()->where('to_order_production_status_id', $corte->id)->count());
    }
}
