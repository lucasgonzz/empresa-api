<?php

namespace Tests\Feature\ProduccionV2;

/**
 * LA RED DEL MOTOR DE STOCK DE PRODUCCION V2.
 *
 * Este test fija el comportamiento que el motor tiene HOY, con la configuracion de hoy: sin
 * estado final por ruta y sin grupos de estados. O sea, la cascada (c) del plan —"el producto
 * entra a stock cuando el movimiento llega al estado de mayor `position` de toda la cuenta"—,
 * que es lo unico que existe hasta esta mision y con lo que ya vienen trabajando los clientes.
 *
 * 🔴 SE ESCRIBIO ANTES DE TOCAR EL MOTOR, A PROPOSITO. Tiene que quedar verde antes y despues
 * del cambio, SIN QUE SE LE TOQUE UNA SOLA ASERCION. Si despues del cambio se pone rojo, el
 * problema esta en el codigo nuevo: la cascada rompio la compatibilidad hacia atras y hay que
 * frenar. Ajustar una asercion de este archivo para que pase es exactamente lo que este archivo
 * existe para impedir.
 */
class Estado_final_global_actual_Test extends ProduccionV2TestCase
{
    /**
     * El producto entra a stock SOLO cuando el movimiento llega al ultimo estado de la cuenta,
     * y borrar ese movimiento lo saca.
     *
     * @group produccion_v2
     * @test
     */
    public function el_producto_entra_a_stock_solo_en_el_ultimo_estado()
    {
        /*
         * Tres estados sin grupo. `Terminado` es el de mayor position, o sea el estado final que
         * resuelve la cascada global.
         */
        $corte     = $this->crear_estado('Corte', 1);
        $soldado   = $this->crear_estado('Soldado', 2);
        $terminado = $this->crear_estado('Terminado', 3);

        /* Insumo y producto, los dos SIN depositos y con stock explicito (ver el TestCase). */
        $cano  = $this->crear_articulo('Cano test', 100);
        $silla = $this->crear_articulo('Silla test', 0);

        $receta = $this->crear_receta($silla);

        /* El insumo se consume en `Corte`: 2 canos por unidad. */
        $ruta = $this->crear_ruta($receta, [
            [
                'article'                       => $cano,
                'amount'                        => 2,
                'order_production_status_id'    => $corte->id,
            ],
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo_inicio = $this->crear_tipo_de_movimiento('Inicio', 'start');

        /*
         * ── Movimiento 1: hacia `Corte`, que NO es el estado final ────────────────────────────
         * Se consumen los insumos, pero el producto no entra a stock.
         */
        $respuesta_corte = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo_inicio->id,
            'to_order_production_status_id'     => $corte->id,
            'amount'                            => 5,
        ]);

        $respuesta_corte->assertStatus(201);

        /* 100 - (2 x 5) = 90 */
        $this->assertEquals(90, $this->stock_de($cano));

        /* El destino no es el ultimo estado: el producto NO entra a stock. */
        $this->assertEquals(0, $this->stock_de($silla));

        $this->assertDatabaseHas('stock_movements', [
            'article_id'    => $cano->id,
            'amount'        => -10,
        ]);

        /*
         * ── Movimiento 2: hacia `Terminado`, que SI es el estado final ────────────────────────
         */
        $respuesta_terminado = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo_inicio->id,
            'from_order_production_status_id'   => $corte->id,
            'to_order_production_status_id'     => $terminado->id,
            'amount'                            => 5,
        ]);

        $respuesta_terminado->assertStatus(201);

        $this->assertEquals(5, $this->stock_de($silla));

        /* El concepto 17 es `Produccion` (catalogo global, sin user_id). */
        $this->assertDatabaseHas('stock_movements', [
            'article_id'                    => $silla->id,
            'amount'                        => 5,
            'concepto_stock_movement_id'    => 17,
        ]);

        /*
         * ── Borrar el movimiento 2 revierte el alta del producto ──────────────────────────────
         */
        $movimiento_id = $respuesta_terminado->json('model.id');

        $this->assertNotNull($movimiento_id);

        $respuesta_borrado = $this->delete('api/production-batch-movement/'.$movimiento_id);

        $respuesta_borrado->assertStatus(204);

        $this->assertEquals(0, $this->stock_de($silla));

        /*
         * El insumo no se toca al borrar este movimiento: el estado `Terminado` no tiene insumos
         * cargados en la ruta, asi que el movimiento 2 nunca tuvo inputs que devolver. El estado
         * intermedio `Soldado` (position 2) tampoco participo de ningun movimiento.
         */
        $this->assertEquals(90, $this->stock_de($cano));

        $this->assertEquals(0, $lote->production_batch_movements()->where('to_order_production_status_id', $soldado->id)->count());
    }
}
