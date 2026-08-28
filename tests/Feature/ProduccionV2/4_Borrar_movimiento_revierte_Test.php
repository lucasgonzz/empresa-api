<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\RecipeRoute;
use Illuminate\Support\Facades\DB;

/**
 * Borrar un movimiento revierte EXACTAMENTE lo que ese movimiento sumo.
 *
 * El segundo metodo es el que justifica que exista la columna output_stock_applied, asi que si
 * alguien piensa en sacarla, este archivo es la razon por la que no se puede.
 */
class Borrar_movimiento_revierte_Test extends ProduccionV2TestCase
{
    /**
     * Reversion simple: se devuelven los insumos y se saca el producto.
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_el_movimiento_devuelve_los_insumos_y_saca_el_producto()
    {
        $corte     = $this->crear_estado('Corte reversion', 1);
        $terminado = $this->crear_estado('Terminado reversion', 2);

        $cano  = $this->crear_articulo('Cano reversion test', 100);
        $silla = $this->crear_articulo('Silla reversion test', 0);

        $receta = $this->crear_receta($silla);

        /* El insumo se consume en el MISMO estado final, para que el borrado revierta las dos cosas. */
        $ruta = $this->crear_ruta($receta, [
            [
                'article'                       => $cano,
                'amount'                        => 4,
                'order_production_status_id'    => $terminado->id,
            ],
        ], [
            'end_order_production_status_id' => $terminado->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Avance reversion', 'advance_reversion');

        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $terminado->id,
            'amount'                            => 10,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(10, $this->stock_de($silla));

        /* 100 - (4 x 10) = 60 */
        $this->assertEquals(60, $this->stock_de($cano));

        $movimiento_id = $respuesta->json('model.id');

        $this->delete('api/production-batch-movement/'.$movimiento_id)->assertStatus(204);

        $this->assertEquals(0, $this->stock_de($silla));
        $this->assertEquals(100, $this->stock_de($cano));

        /* Los cuatro movimientos de stock existen: alta y baja del producto, baja y devolucion del insumo. */
        $this->assertDatabaseHas('stock_movements', ['article_id' => $silla->id, 'amount' => 10]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $silla->id, 'amount' => -10]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $cano->id, 'amount' => -40]);
        $this->assertDatabaseHas('stock_movements', ['article_id' => $cano->id, 'amount' => 40]);
    }

    /**
     * 🔴 EL TEST QUE JUSTIFICA LA COLUMNA `output_stock_applied`.
     *
     * Entre crear un movimiento y borrarlo pueden pasar semanas, y en el medio el usuario puede
     * reconfigurar los estados. Si el borrado volviera a resolver la cascada en vez de leer lo
     * que se registro al crear, resolveria OTRO estado, la comparacion no matchearia y NUNCA
     * revertiria lo que ese movimiento sumo: el stock quedaria en 10 para siempre, sin error y
     * sin log. Este test reconfigura la ruta a proposito antes de borrar.
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_revierte_igual_aunque_se_haya_cambiado_la_configuracion_de_estados()
    {
        $primero = $this->crear_estado('Primero reconfig', 1);
        $otro    = $this->crear_estado('Otro reconfig', 2);

        $silla = $this->crear_articulo('Silla reconfig test', 0);

        $receta = $this->crear_receta($silla);

        /* La ruta arranca declarando `Primero` como el estado en que la unidad queda terminada. */
        $ruta = $this->crear_ruta($receta, [], [
            'end_order_production_status_id' => $primero->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Avance reconfig', 'advance_reconfig');

        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $primero->id,
            'amount'                            => 10,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(10, $this->stock_de($silla));

        /*
         * El usuario reconfigura: ahora la ruta dice que la unidad queda terminada en OTRO
         * estado. El movimiento que ya existe no cambia, pero la cascada resolveria distinto.
         */
        $ruta_recargada = RecipeRoute::find($ruta->id);
        $ruta_recargada->end_order_production_status_id = $otro->id;
        $ruta_recargada->save();

        $this->assertEquals($otro->id, RecipeRoute::find($ruta->id)->end_order_production_status_id);

        /* Y ahora se borra el movimiento viejo. */
        $this->delete('api/production-batch-movement/'.$respuesta->json('model.id'))->assertStatus(204);

        /* Tiene que volver a 0 igual: manda lo que se registro cuando el hecho ocurrio. */
        $this->assertEquals(0, $this->stock_de($silla));

        $this->assertDatabaseHas('stock_movements', ['article_id' => $silla->id, 'amount' => -10]);
    }

    /**
     * 🔴 EL MOVIMIENTO VIEJO (output_stock_applied EN NULL) SE RESUELVE CON EL CRITERIO VIEJO.
     *
     * La columna en null significa una cosa sola: ese movimiento se creo ANTES de la migracion,
     * cuando el estado final era siempre el de mayor position de toda la cuenta y no existian ni
     * el estado final por ruta ni los grupos. Si el fallback resolviera la cascada ENTERA, un
     * end_order_production_status_id puesto despues del deploy le cambiaria el criterio a un
     * hecho que ya paso: el borrado no matchearia y quedaria stock fantasma, sin error y sin log.
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_un_movimiento_viejo_revierte_con_el_criterio_global_y_no_con_la_ruta()
    {
        $this->crear_estado('Corte legacy', 1);
        $armado    = $this->crear_estado('Armado legacy', 2);
        $terminado = $this->crear_estado('Terminado legacy', 3);

        $silla = $this->crear_articulo('Silla legacy test', 0);

        $receta = $this->crear_receta($silla);

        /* La ruta NO declara estado final: es una ruta como las de antes de la migracion. */
        $ruta = $this->crear_ruta($receta, []);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Avance legacy', 'advance_legacy');

        /* El movimiento llega a Terminado, que es el de mayor position de toda la cuenta. */
        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $terminado->id,
            'amount'                            => 10,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(10, $this->stock_de($silla));

        $movimiento_id = $respuesta->json('model.id');

        /* Lo envejecemos: asi quedan las filas que ya existian cuando corrio la migracion. */
        DB::table('production_batch_movements')
            ->where('id', $movimiento_id)
            ->update(['output_stock_applied' => null]);

        /* Y recien despues el usuario configura el estado final de la ruta. */
        $ruta_recargada = RecipeRoute::find($ruta->id);
        $ruta_recargada->end_order_production_status_id = $armado->id;
        $ruta_recargada->save();

        $this->delete('api/production-batch-movement/'.$movimiento_id)->assertStatus(204);

        /* Tiene que revertir las 10 que sumo. Con la cascada entera quedarian +10 fantasma. */
        $this->assertEquals(0, $this->stock_de($silla));
        $this->assertDatabaseHas('stock_movements', ['article_id' => $silla->id, 'amount' => -10]);
    }

    /**
     * El espejo del anterior: un movimiento viejo que NO sumo nada no puede restar al borrarse.
     *
     * Llego a Armado cuando el estado final era Terminado, asi que no dio de alta el producto. Si
     * el fallback resolviera la cascada entera, el end_order_production_status_id = Armado puesto
     * despues lo haria matchear y el borrado restaria 10 unidades que nunca entraron.
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_un_movimiento_viejo_que_no_sumo_no_resta_nada()
    {
        $this->crear_estado('Corte espejo', 1);
        $armado = $this->crear_estado('Armado espejo', 2);
        $this->crear_estado('Terminado espejo', 3);

        $silla = $this->crear_articulo('Silla espejo test', 0);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, []);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Avance espejo', 'advance_espejo');

        /* Llega a Armado, que NO es el de mayor position: no da de alta el producto. */
        $respuesta = $this->post('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $armado->id,
            'amount'                            => 10,
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals(0, $this->stock_de($silla));

        $movimiento_id = $respuesta->json('model.id');

        DB::table('production_batch_movements')
            ->where('id', $movimiento_id)
            ->update(['output_stock_applied' => null]);

        $ruta_recargada = RecipeRoute::find($ruta->id);
        $ruta_recargada->end_order_production_status_id = $armado->id;
        $ruta_recargada->save();

        $this->delete('api/production-batch-movement/'.$movimiento_id)->assertStatus(204);

        /* Sigue en 0: no habia nada que revertir. */
        $this->assertEquals(0, $this->stock_de($silla));
        $this->assertDatabaseMissing('stock_movements', ['article_id' => $silla->id, 'amount' => -10]);
    }
}
