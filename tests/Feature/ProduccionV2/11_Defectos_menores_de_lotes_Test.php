<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\Article;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMovement;
use App\Models\ProductionBatchMovementInput;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Los defectos menores de los lotes (mision produccion-v2-defectos, 10/10/2026).
 *
 *  - `PUT production-batch-movement/{id}` respondia `production_batch` con el MOVIMIENTO adentro y
 *    la SPA lee `model`: el usuario editaba las notas de un movimiento y la pantalla no se
 *    enteraba. Sin `inputs` en el request ademas reventaba (foreach sobre null).
 *  - `POST production-batch` sin `recipe_id` hacia `Recipe::find(null)->article_id`: 500, y con la
 *    receta de OTRO comercio fabricaba un lote con el articulo ajeno.
 *  - `DELETE production-batch/{id}` borraba el lote y dejaba sus movimientos huerfanos, con el
 *    stock de los insumos ya descontado y el del producto ya sumado: nada lo revertia. Ahora
 *    revierte todo (decision de Lucas), por el mismo camino que el boton de borrar movimiento, y
 *    solo borra lotes del dueño.
 *
 * Todo se verifica leyendo la BASE despues de pegarle al endpoint real.
 */
class Defectos_menores_de_lotes_Test extends ProduccionV2TestCase
{
    // -------------------------------------------------------------------------------------------
    // Helpers del archivo
    // -------------------------------------------------------------------------------------------

    /**
     * Un lote con DOS movimientos, cada uno por el endpoint real:
     *   1. hacia `Corte` (10 unidades): consume 2 de caño por unidad = 20.
     *   2. hacia `Fin` (10 unidades): consume 1 de tornillo por unidad = 10 y da de alta 10 sillas.
     *
     * @return array [lote, cano, tornillo, silla, id_del_movimiento_1, id_del_movimiento_2]
     */
    private function lote_con_dos_movimientos()
    {
        $corte = $this->crear_estado('Corte borrar lote', 1);
        $fin   = $this->crear_estado('Fin borrar lote', 2);

        $cano     = $this->crear_articulo('Cano borrar lote test', 100);
        $tornillo = $this->crear_articulo('Tornillo borrar lote test', 50);
        $silla    = $this->crear_articulo('Silla borrar lote test', 0);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano,     'amount' => 2, 'order_production_status_id' => $corte->id],
            ['article' => $tornillo, 'amount' => 1, 'order_production_status_id' => $fin->id],
        ], [
            'end_order_production_status_id' => $fin->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        $tipo = $this->crear_tipo_de_movimiento('Avance borrar lote', 'advance_borrar_lote');

        $primero = $this->postJson('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $corte->id,
            'amount'                            => 10,
        ]);

        $primero->assertStatus(201);

        $segundo = $this->postJson('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $fin->id,
            'amount'                            => 10,
        ]);

        $segundo->assertStatus(201);

        /* El punto de partida de lo que hay que revertir. */
        $this->assertEquals(80, $this->stock_de($cano));
        $this->assertEquals(40, $this->stock_de($tornillo));
        $this->assertEquals(10, $this->stock_de($silla));

        return [$lote, $cano, $tornillo, $silla, $primero->json('model.id'), $segundo->json('model.id')];
    }

    /**
     * Otro comercio, con su articulo, su receta y su lote: para probar que un dueño no toca lo
     * del otro. La base compartida de produccion (u767360347_empresa) tiene 51 comercios.
     *
     * @return array [User, Article, Recipe, ProductionBatch]
     */
    private function comercio_ajeno()
    {
        $otro = User::create([
            'name'     => 'Otro comercio lotes',
            'email'    => 'otro-lotes-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $articulo = Article::create([
            'name'      => 'Silla ajena lotes test',
            'stock'     => 7,
            'cost'      => 100,
            'status'    => 'active',
            'user_id'   => $otro->id,
        ]);

        $receta = Recipe::create([
            'name'          => $articulo->name,
            'article_id'    => $articulo->id,
            'user_id'       => $otro->id,
        ]);

        $lote = ProductionBatch::create([
            'article_id'                    => $articulo->id,
            'recipe_id'                     => $receta->id,
            'production_batch_status_id'    => $this->estado_de_lote_en_proceso()->id,
            'planned_amount'                => 5,
            'user_id'                       => $otro->id,
        ]);

        return [$otro, $articulo, $receta, $lote];
    }

    // -------------------------------------------------------------------------------------------
    // PUT production-batch-movement/{id}
    // -------------------------------------------------------------------------------------------

    /**
     * La SPA lee `model`; `production_batch` se conserva por compatibilidad hacia atras (los
     * dos frentes del VPS pueden servir versiones distintas del SPA un release o dos).
     *
     * @group produccion_v2
     * @test
     */
    public function editar_un_movimiento_responde_model_y_conserva_production_batch()
    {
        list($lote, $cano, $tornillo, $silla, $mov_1, $mov_2) = $this->lote_con_dos_movimientos();

        $input = ProductionBatchMovementInput::where('production_batch_movement_id', $mov_1)->first();

        /* Edita las notas y el consumo real del caño: 20 previstos, 25 reales. */
        $respuesta = $this->putJson('api/production-batch-movement/'.$mov_1, [
            'notes'     => 'Se gasto de mas en el corte',
            'inputs'    => [
                ['id' => $input->id, 'actual_amount' => 25],
            ],
        ]);

        $respuesta->assertStatus(200);

        $this->assertSame($mov_1, $respuesta->json('model.id'));
        $this->assertSame($mov_1, $respuesta->json('production_batch.id'));
        $this->assertSame('Se gasto de mas en el corte', $respuesta->json('model.notes'));

        /* La base: las notas, el consumo real y el delta de stock (80 - 5). */
        $this->assertDatabaseHas('production_batch_movements', ['id' => $mov_1, 'notes' => 'Se gasto de mas en el corte']);
        $this->assertEquals(25, (float) ProductionBatchMovementInput::find($input->id)->actual_amount);
        $this->assertEquals(75, $this->stock_de($cano));
    }

    /**
     * Cambiar solo las notas no manda `inputs`, y eso no puede ser un 500.
     *
     * @group produccion_v2
     * @test
     */
    public function editar_solo_las_notas_de_un_movimiento_sin_mandar_insumos_funciona()
    {
        list($lote, $cano, $tornillo, $silla, $mov_1, $mov_2) = $this->lote_con_dos_movimientos();

        $respuesta = $this->putJson('api/production-batch-movement/'.$mov_1, [
            'notes' => 'Solo la nota',
        ]);

        $respuesta->assertStatus(200);

        $this->assertSame($mov_1, $respuesta->json('model.id'));

        $this->assertDatabaseHas('production_batch_movements', ['id' => $mov_1, 'notes' => 'Solo la nota']);

        /* Y el stock no se movio. */
        $this->assertEquals(80, $this->stock_de($cano));
    }

    // -------------------------------------------------------------------------------------------
    // POST production-batch
    // -------------------------------------------------------------------------------------------

    /**
     * @group produccion_v2
     * @test
     */
    public function crear_un_lote_con_receta_propia_toma_el_articulo_de_la_receta()
    {
        $silla  = $this->crear_articulo('Silla alta de lote test', 0);
        $receta = $this->crear_receta($silla);
        $estado = $this->estado_de_lote_en_proceso();

        $respuesta = $this->postJson('api/production-batch', [
            'production_batch_status_id'    => $estado->id,
            'recipe_id'                     => $receta->id,
            'planned_amount'                => 12,
        ]);

        $respuesta->assertStatus(201);

        $this->assertDatabaseHas('production_batches', [
            'id'                => $respuesta->json('model.id'),
            'article_id'        => $silla->id,
            'recipe_id'         => $receta->id,
            'planned_amount'    => 12,
            'user_id'           => $this->comercio()->id,
        ]);
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function crear_un_lote_sin_receta_da_422_y_no_500()
    {
        $estado = $this->estado_de_lote_en_proceso();

        $antes = ProductionBatch::count();

        $respuesta = $this->postJson('api/production-batch', [
            'production_batch_status_id'    => $estado->id,
            'planned_amount'                => 12,
        ]);

        $respuesta->assertStatus(422);

        $cuerpo = json_decode($respuesta->getContent());

        $this->assertTrue(is_object($cuerpo->errors), 'errors tiene que salir como objeto JSON. Cuerpo: '.$respuesta->getContent());
        $this->assertTrue(isset($cuerpo->errors->recipe_id), 'Falta el error de recipe_id. Cuerpo: '.$respuesta->getContent());
        $this->assertTrue(isset($cuerpo->message) && $cuerpo->message !== '');

        $this->assertSame($antes, ProductionBatch::count());
    }

    /**
     * 🔴 La receta de otro comercio no fabrica un lote en este: el `Recipe::find()` de antes no
     * miraba de quien era, y el lote salia con el articulo del otro dueño.
     *
     * @group produccion_v2
     * @test
     */
    public function crear_un_lote_con_la_receta_de_otro_comercio_da_422()
    {
        list($otro, $articulo_ajeno, $receta_ajena, $lote_ajeno) = $this->comercio_ajeno();

        $estado = $this->estado_de_lote_en_proceso();

        $antes = ProductionBatch::where('user_id', $this->comercio()->id)->count();

        $respuesta = $this->postJson('api/production-batch', [
            'production_batch_status_id'    => $estado->id,
            'recipe_id'                     => $receta_ajena->id,
            'planned_amount'                => 12,
        ]);

        $respuesta->assertStatus(422);

        $cuerpo = json_decode($respuesta->getContent());

        $this->assertTrue(is_object($cuerpo->errors), 'errors tiene que salir como objeto JSON. Cuerpo: '.$respuesta->getContent());
        $this->assertTrue(isset($cuerpo->errors->recipe_id), 'Falta el error de recipe_id. Cuerpo: '.$respuesta->getContent());

        $this->assertSame($antes, ProductionBatch::where('user_id', $this->comercio()->id)->count());
    }

    // -------------------------------------------------------------------------------------------
    // DELETE production-batch/{id}
    // -------------------------------------------------------------------------------------------

    /**
     * 🔴 ELIMINAR UN LOTE CON MOVIMIENTOS REVIERTE TODO.
     *
     * Antes el lote se borraba y los movimientos quedaban huerfanos con el stock ya tocado:
     * el caño seguia descontado y las sillas seguian sumadas, de un lote que ya no existe.
     *
     * @group produccion_v2
     * @test
     */
    public function eliminar_un_lote_con_movimientos_devuelve_los_insumos_saca_el_producto_y_borra_todo()
    {
        list($lote, $cano, $tornillo, $silla, $mov_1, $mov_2) = $this->lote_con_dos_movimientos();

        $this->delete('api/production-batch/'.$lote->id)->assertStatus(204);

        /* El stock vuelve al inicial: los insumos devueltos, el producto sacado. */
        $this->assertEquals(100, $this->stock_de($cano));
        $this->assertEquals(50, $this->stock_de($tornillo));
        $this->assertEquals(0, $this->stock_de($silla));

        /* Y no queda nada del lote: ni el lote, ni sus movimientos, ni las filas de insumos. */
        $this->assertDatabaseMissing('production_batches', ['id' => $lote->id]);
        $this->assertSame(0, ProductionBatchMovement::where('production_batch_id', $lote->id)->count());
        $this->assertSame(0, ProductionBatchMovementInput::whereIn('production_batch_movement_id', [$mov_1, $mov_2])->count());
    }

    /**
     * Se revierte del movimiento mas nuevo al mas viejo, el mismo orden en que un usuario los
     * borraria a mano con el boton de cada movimiento. Se ve en el orden de los movimientos de
     * stock de la devolucion: el tornillo (movimiento 2) vuelve ANTES que el caño (movimiento 1).
     *
     * @group produccion_v2
     * @test
     */
    public function eliminar_un_lote_revierte_del_movimiento_mas_nuevo_al_mas_viejo()
    {
        list($lote, $cano, $tornillo, $silla, $mov_1, $mov_2) = $this->lote_con_dos_movimientos();

        $this->delete('api/production-batch/'.$lote->id)->assertStatus(204);

        /* Las devoluciones son los unicos movimientos de stock POSITIVOS de estos dos insumos. */
        $devolucion_tornillo = StockMovement::where('article_id', $tornillo->id)->where('amount', 10)->value('id');
        $devolucion_cano     = StockMovement::where('article_id', $cano->id)->where('amount', 20)->value('id');

        $this->assertNotNull($devolucion_tornillo);
        $this->assertNotNull($devolucion_cano);
        $this->assertLessThan($devolucion_cano, $devolucion_tornillo);

        /* Y el producto: el alta de las 10 sillas se saca (-10) al revertir el movimiento 2. */
        $this->assertDatabaseHas('stock_movements', ['article_id' => $silla->id, 'amount' => -10]);
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function eliminar_un_lote_sin_movimientos_responde_204()
    {
        $silla  = $this->crear_articulo('Silla lote vacio test', 0);
        $receta = $this->crear_receta($silla);
        $ruta   = $this->crear_ruta($receta, []);
        $lote   = $this->crear_lote($silla, $receta, $ruta, 10);

        $this->delete('api/production-batch/'.$lote->id)->assertStatus(204);

        $this->assertDatabaseMissing('production_batches', ['id' => $lote->id]);
        $this->assertEquals(0, $this->stock_de($silla));
    }

    /**
     * 🔴 UN LOTE DE OTRO DUEÑO NO SE BORRA NI SE REVIERTE: 404 y nada tocado.
     *
     * El `findOrFail($id)` de antes no miraba de quien era el lote. En la base compartida un id
     * ajeno se llevaba el lote de otro comercio.
     *
     * @group produccion_v2
     * @test
     */
    public function eliminar_un_lote_de_otro_comercio_da_404_y_no_toca_nada()
    {
        list($otro, $articulo_ajeno, $receta_ajena, $lote_ajeno) = $this->comercio_ajeno();

        $movimiento_ajeno = ProductionBatchMovement::create([
            'production_batch_id'               => $lote_ajeno->id,
            'production_batch_movement_type_id' => $this->crear_tipo_de_movimiento('Avance ajeno lotes', 'advance_ajeno_lotes')->id,
            'to_order_production_status_id'     => $this->crear_estado('Estado ajeno lotes', 1)->id,
            'amount'                            => 3,
        ]);

        $respuesta = $this->deleteJson('api/production-batch/'.$lote_ajeno->id);

        $this->assertSame(404, $respuesta->getStatusCode(), 'Cuerpo: '.$respuesta->getContent());

        $this->assertDatabaseHas('production_batches', ['id' => $lote_ajeno->id]);
        $this->assertDatabaseHas('production_batch_movements', ['id' => $movimiento_ajeno->id]);
        $this->assertEquals(7, (float) $articulo_ajeno->fresh()->stock);
    }

    /**
     * 🔴 Y SI ALGO FALLA A MITAD, NO QUEDA UN LOTE A MEDIO REVERTIR.
     *
     * El borrado va en una transaccion. El fallo se simula con un evento `deleting` que revienta
     * al borrar el movimiento 1 (el mas viejo, o sea el ULTIMO en revertirse): para ese momento
     * el movimiento 2 ya devolvio su tornillo y saco sus sillas, y el movimiento 1 ya devolvio
     * su caño. Todo eso tiene que deshacerse.
     *
     * @group produccion_v2
     * @test
     */
    public function si_falla_la_reversion_de_un_movimiento_no_queda_nada_a_medias()
    {
        list($lote, $cano, $tornillo, $silla, $mov_1, $mov_2) = $this->lote_con_dos_movimientos();

        $evento = 'eloquent.deleting: '.ProductionBatchMovement::class;

        ProductionBatchMovement::deleting(function ($movimiento) use ($mov_1) {
            if ((int) $movimiento->id === (int) $mov_1) {
                throw new \RuntimeException('falla simulada al borrar el movimiento mas viejo');
            }
        });

        $mensaje = null;

        try {
            $this->withoutExceptionHandling();
            $this->delete('api/production-batch/'.$lote->id);
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();
        } finally {
            ProductionBatchMovement::getEventDispatcher()->forget($evento);
        }

        $this->assertSame('falla simulada al borrar el movimiento mas viejo', $mensaje);

        /* El lote y sus dos movimientos siguen ahi, con sus insumos... */
        $this->assertDatabaseHas('production_batches', ['id' => $lote->id]);
        $this->assertDatabaseHas('production_batch_movements', ['id' => $mov_1]);
        $this->assertDatabaseHas('production_batch_movements', ['id' => $mov_2]);
        $this->assertSame(2, ProductionBatchMovementInput::whereIn('production_batch_movement_id', [$mov_1, $mov_2])->count());

        /* ...y el stock quedo como antes de intentar el borrado: nada se revirtio a medias. */
        $this->assertEquals(80, $this->stock_de($cano));
        $this->assertEquals(40, $this->stock_de($tornillo));
        $this->assertEquals(10, $this->stock_de($silla));
    }
}
