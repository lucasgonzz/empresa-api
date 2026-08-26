<?php

namespace Tests\Feature\ProduccionV2;

/**
 * La cascada (b): si la ruta no declara estado final pero SI tiene grupo, la unidad queda
 * terminada en el estado de mayor position DENTRO DE ESE GRUPO.
 *
 * Es lo que hace que el multinivel se sostenga sin que el usuario tenga que configurar un estado
 * final ruta por ruta: alcanza con agrupar los estados de cada etapa. El estado suelto con
 * position 99 esta ahi a proposito: si la cascada mirara toda la cuenta en vez del grupo, ganaria
 * el y el grupo no serviria de nada.
 */
class Estado_final_por_grupo_Test extends ProduccionV2TestCase
{
    /**
     * @group produccion_v2
     * @test
     */
    public function el_producto_entra_a_stock_en_el_ultimo_estado_del_grupo_de_la_ruta()
    {
        $grupo = $this->crear_grupo('Partes', 1);

        $corte   = $this->crear_estado('Corte grupo', 1, $grupo->id);
        $doblado = $this->crear_estado('Doblado grupo', 2, $grupo->id);
        $lijado  = $this->crear_estado('Lijado grupo', 3, $grupo->id);

        /* Fuera del grupo, y con la position mas alta de toda la cuenta. */
        $suelto = $this->crear_estado('Terminado suelto', 99);

        $chapa = $this->crear_articulo('Chapa grupo test', 500);
        $pata  = $this->crear_articulo('Pata grupo test', 0);

        $receta = $this->crear_receta($pata);

        /* Ruta SIN estado final propio y CON grupo: tiene que resolver por (b). */
        $ruta = $this->crear_ruta($receta, [
            [
                'article'                       => $chapa,
                'amount'                        => 3,
                'order_production_status_id'    => $corte->id,
            ],
        ], [
            'order_production_status_group_id' => $grupo->id,
        ]);

        $lote = $this->crear_lote($pata, $receta, $ruta, 50);

        $tipo = $this->crear_tipo_de_movimiento('Avance grupo', 'advance_grupo');

        /*
         * ── Hacia `Lijado`, el de mayor position DENTRO del grupo ─────────────────────────────
         */
        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $lijado->id,
            'amount'                            => 12,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(12, $this->stock_de($pata));

        $this->assertDatabaseHas('production_batch_movements', [
            'id'                    => $respuesta->json('model.id'),
            'output_stock_applied'  => 1,
        ]);

        /*
         * ── Hacia el estado suelto de position 99, que esta FUERA del grupo ───────────────────
         * Es el que ganaria si la cascada mirara toda la cuenta.
         */
        $respuesta_suelto = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'from_order_production_status_id'   => $lijado->id,
            'to_order_production_status_id'     => $suelto->id,
            'amount'                            => 12,
        ]);

        $respuesta_suelto->assertStatus(201);

        $this->assertEquals(12, $this->stock_de($pata));

        $this->assertDatabaseHas('production_batch_movements', [
            'id'                    => $respuesta_suelto->json('model.id'),
            'output_stock_applied'  => 0,
        ]);

        /* El estado intermedio del grupo existe pero no participo. */
        $this->assertEquals(0, $lote->production_batch_movements()->where('to_order_production_status_id', $doblado->id)->count());
    }

    /**
     * La solapa "Cantidades en cada Estado" del lote muestra SOLO los estados del grupo de su
     * ruta, y no los 4 de la cuenta.
     *
     * Sin esto, la pantalla de un lote de patas listaria tambien los estados de la estructura y
     * los de la silla armada, todos en cero. El mismo cambio arregla que la query no filtraba
     * por user_id y en una base multi-comercio mostraba los estados de todas las cuentas.
     *
     * @group produccion_v2
     * @test
     */
    public function las_cantidades_por_estado_del_lote_solo_traen_los_estados_del_grupo()
    {
        $grupo = $this->crear_grupo('Partes solapa', 1);

        $corte   = $this->crear_estado('Corte solapa', 1, $grupo->id);
        $doblado = $this->crear_estado('Doblado solapa', 2, $grupo->id);
        $lijado  = $this->crear_estado('Lijado solapa', 3, $grupo->id);

        /* Cuarto estado, fuera del grupo: no tiene que aparecer en la solapa del lote. */
        $this->crear_estado('Terminado solapa suelto', 99);

        $chapa = $this->crear_articulo('Chapa solapa test', 500);
        $pata  = $this->crear_articulo('Pata solapa test', 0);

        $receta = $this->crear_receta($pata);

        $ruta = $this->crear_ruta($receta, [
            [
                'article'                       => $chapa,
                'amount'                        => 1,
                'order_production_status_id'    => $corte->id,
            ],
        ], [
            'order_production_status_group_id' => $grupo->id,
        ]);

        $lote = $this->crear_lote($pata, $receta, $ruta, 10);

        $respuesta = $this->get('api/production-batch/'.$lote->id);

        $respuesta->assertStatus(200);

        $amounts = $respuesta->json('model.amounts_by_status');

        $this->assertCount(3, $amounts);

        $ids = [];

        foreach ($amounts as $fila) {
            $ids[] = (int) $fila['order_production_status_id'];
        }

        sort($ids);

        $esperados = [(int) $corte->id, (int) $doblado->id, (int) $lijado->id];
        sort($esperados);

        $this->assertEquals($esperados, $ids);
    }
}
