<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\ProductionBatchMovement;

/**
 * 🔴 EL ESCENARIO QUE MOTIVO TODA LA MISION: DOS LOTES ENCADENADOS.
 *
 * Hasta esta mision, cada receta era un nivel solo y el estado final era siempre el de mayor
 * position de TODA la cuenta. Con eso, un taller que fabrica partes y despues las ensambla no
 * podia trabajar: el lote de las patas nunca llegaba al estado final global, asi que las patas
 * no entraban a stock, y el lote de la estructura no tenia de donde consumirlas.
 *
 * Lo que este test recorre, de punta a punta y contra los endpoints reales:
 *
 *   1. Lote de PATAS. La ruta tiene grupo "Patas" y NO declara estado final propio: el estado
 *      final sale de la rama (b) de la cascada —el de mayor position DENTRO del grupo—, que es
 *      "Patas listas" y no el ultimo de la cuenta. Consume cano y da de alta patas.
 *
 *   2. Lote de ESTRUCTURA. La ruta declara estado final propio (rama (a) de la cascada). Un
 *      primer movimiento CONSUME LAS PATAS que dejo el lote anterior, en el estado de
 *      ensamblado, y no da de alta nada porque todavia no es el estado final. Un segundo
 *      movimiento llega al estado final de ESA ruta y recien ahi la estructura entra a stock.
 *
 * Los dos lotes existen a la vez, que es lo que ProduccionV2TestCase::crear_lote() no dejaba
 * hacer: pedia un `production_batch_status` nuevo cada vez y el slug tiene UNIQUE global, asi
 * que la segunda llamada reventaba. Ese builder se arreglo junto con este test.
 *
 * Todo se lee de la BASE despues de pegarle al endpoint, nunca del valor de retorno de un
 * helper.
 */
class Multinivel_de_punta_a_punta_Test extends ProduccionV2TestCase
{
    /**
     * @group produccion_v2
     * @test
     */
    public function las_patas_entran_a_stock_en_su_estado_final_y_la_estructura_las_consume()
    {
        /* ── Los dos grupos de estados, cada uno con su propia secuencia ───────────────────── */

        $grupo_patas      = $this->crear_grupo('Patas multinivel', 1);
        $grupo_estructura = $this->crear_grupo('Estructura multinivel', 2);

        $corte_patas  = $this->crear_estado('Corte patas multinivel', 1, $grupo_patas->id);
        $patas_listas = $this->crear_estado('Patas listas multinivel', 2, $grupo_patas->id);

        $ensamblado      = $this->crear_estado('Ensamblado multinivel', 3, $grupo_estructura->id);
        $estructura_ok   = $this->crear_estado('Estructura lista multinivel', 4, $grupo_estructura->id);

        /* ── Los tres articulos: materia prima, parte fabricada y producto de ensamble ─────── */

        $cano       = $this->crear_articulo('Cano multinivel test', 100);
        $pata       = $this->crear_articulo('Pata multinivel test', 0);
        $estructura = $this->crear_articulo('Estructura multinivel test', 0);

        $tipo = $this->crear_tipo_de_movimiento('Avance multinivel', 'advance_multinivel');

        /* ── 1. El lote de PATAS ───────────────────────────────────────────────────────────── */

        $receta_patas = $this->crear_receta($pata);

        /*
         * La ruta NO declara end_order_production_status_id: el estado final sale del GRUPO.
         * Es la rama (b), y es la que hace que "Patas listas" gane sobre "Estructura lista
         * multinivel", que es el de mayor position de toda la cuenta.
         */
        $ruta_patas = $this->crear_ruta($receta_patas, [
            [
                'article'                       => $cano,
                'amount'                        => 1,
                'order_production_status_id'    => $patas_listas->id,
            ],
        ], [
            'order_production_status_group_id' => $grupo_patas->id,
        ]);

        $lote_patas = $this->crear_lote($pata, $receta_patas, $ruta_patas, 40);

        $mov_patas = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote_patas->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $patas_listas->id,
            'amount'                            => 40,
        ]);

        $mov_patas->assertStatus(201);

        /* Las 40 patas entraron a stock aunque "Patas listas" NO es el ultimo estado de la cuenta. */
        $this->assertEquals(40, $this->stock_de($pata));

        /* Y se consumieron 40 canos: 100 - (1 x 40). */
        $this->assertEquals(60, $this->stock_de($cano));

        /* Queda registrado que este movimiento dio de alta el producto. */
        $this->assertEquals(1, (int) ProductionBatchMovement::find($mov_patas->json('model.id'))->output_stock_applied);

        /* ── 2. El lote de ESTRUCTURA, que consume esas patas ──────────────────────────────── */

        $receta_estructura = $this->crear_receta($estructura);

        /* Esta ruta SI declara su estado final: es la rama (a) de la cascada. */
        $ruta_estructura = $this->crear_ruta($receta_estructura, [
            [
                'article'                       => $pata,
                'amount'                        => 4,
                'order_production_status_id'    => $ensamblado->id,
            ],
        ], [
            'order_production_status_group_id'  => $grupo_estructura->id,
            'end_order_production_status_id'    => $estructura_ok->id,
        ]);

        /* 🔴 El segundo crear_lote() de la misma corrida: es lo que antes reventaba. */
        $lote_estructura = $this->crear_lote($estructura, $receta_estructura, $ruta_estructura, 10);

        /* 2.a El ensamblado consume las patas y NO da de alta nada: no es el estado final. */
        $mov_ensamblado = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote_estructura->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $ensamblado->id,
            'amount'                            => 10,
        ]);

        $mov_ensamblado->assertStatus(201);

        /* 40 - (4 x 10) = 0. Las patas que fabrico el lote anterior son el insumo de este. */
        $this->assertEquals(0, $this->stock_de($pata));

        /* Todavia no hay estructuras: el ensamblado no es el estado final de esta ruta. */
        $this->assertEquals(0, $this->stock_de($estructura));

        $this->assertEquals(0, (int) ProductionBatchMovement::find($mov_ensamblado->json('model.id'))->output_stock_applied);

        /* 2.b Y recien el estado final de ESTA ruta da de alta la estructura. */
        $mov_final = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote_estructura->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $estructura_ok->id,
            'amount'                            => 10,
        ]);

        $mov_final->assertStatus(201);

        $this->assertEquals(10, $this->stock_de($estructura));

        /* Y no volvio a tocar ni las patas ni el cano: ese estado no tiene insumos cargados. */
        $this->assertEquals(0, $this->stock_de($pata));
        $this->assertEquals(60, $this->stock_de($cano));

        $this->assertEquals(1, (int) ProductionBatchMovement::find($mov_final->json('model.id'))->output_stock_applied);

        /* ── La cadena completa, leida de stock_movements ──────────────────────────────────── */

        $this->assertDatabaseHas('stock_movements', ['article_id' => $cano->id,       'amount' => -40]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $pata->id,       'amount' => 40]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $pata->id,       'amount' => -40]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $estructura->id, 'amount' => 10]);

        /* El estado de corte no participo de ningun movimiento: existe solo para que el grupo
           tenga mas de un estado y la rama (b) tenga algo que elegir. */
        $this->assertEquals(0, ProductionBatchMovement::where('to_order_production_status_id', $corte_patas->id)->count());
    }
}
