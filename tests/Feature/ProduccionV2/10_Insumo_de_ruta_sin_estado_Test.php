<?php

namespace Tests\Feature\ProduccionV2;

use App\Http\Controllers\Helpers\ProductionBatchMovementHelper;
use App\Models\ProductionBatchMovement;
use App\Models\ProductionBatchMovementInput;
use App\Models\ProductionBatchMovementType;
use App\Models\RecipeRoute;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Un insumo sin "Estado" y un movimiento sin "Hacia estado" ya no se guardan a medias
 * (mision produccion-v2-defectos, 10/10/2026).
 *
 * 🔴 EL DEFECTO. El formulario de la ruta y el del movimiento tienen un select con la opcion
 * "Seleccione..." cuyo valor es 0. Si el usuario no elegia nada:
 *
 *   - el insumo se guardaba en `article_recipe_route` con `order_production_status_id` = 0 (o
 *     null), o sea un insumo que no se consume en NINGUN estado: la ruta se ve completa, el
 *     movimiento responde 201 y el stock del insumo no baja. Sin error y sin log.
 *   - el movimiento se guardaba con `to_order_production_status_id` = 0, que ningun estado real
 *     matchea: no consumia nada, no daba de alta el producto y el lote quedaba con una cantidad
 *     "en el estado 0" que no existe. Medido en Quino2 (el primer cliente que uso el modulo).
 *
 * Las decisiones de Lucas (10/10/2026): "Hacia estado" es obligatorio en los SEIS tipos de
 * movimiento, y un insumo sin estado se RECHAZA con un aviso que lo nombra (no se guarda como
 * null). Los dos avisos son 422 con `errors` como MAPA y `message`: es la forma que la SPA ya
 * muestra como toast aun sin sesion (ver la memoria spa-como-muestra-un-error-de-la-api-segun-su-forma).
 * Un `errors` que saliera como lista dejaria al usuario sin ningun aviso.
 *
 * Todo se verifica leyendo la BASE despues de pegarle al endpoint real.
 */
class Insumo_de_ruta_sin_estado_Test extends ProduccionV2TestCase
{
    // -------------------------------------------------------------------------------------------
    // Helpers del archivo
    // -------------------------------------------------------------------------------------------

    /**
     * Un insumo tal como lo manda la SPA: el articulo con su `pivot`.
     *
     * @param  \App\Models\Article  $articulo
     * @param  mixed                $estado_id  Un id, 0 o null. La clave 'order_production_status_id'
     *                                          se omite del pivot si se pasa la palabra 'sin_clave'.
     * @return array
     */
    private function insumo($articulo, $estado_id)
    {
        $pivot = ['amount' => 2, 'notes' => null, 'address_id' => null];

        if ($estado_id !== 'sin_clave') {
            $pivot['order_production_status_id'] = $estado_id;
        }

        return [
            'id'    => $articulo->id,
            'name'  => $articulo->name,
            'pivot' => $pivot,
        ];
    }

    /**
     * El errors de una respuesta 422, como lo ve el SPA: tiene que ser un OBJETO JSON.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return \stdClass
     */
    private function errors_como_objeto($respuesta)
    {
        $cuerpo = json_decode($respuesta->getContent());

        $this->assertTrue(is_object($cuerpo->errors), 'errors tiene que salir como objeto JSON (mapa campo -> textos). Cuerpo: '.$respuesta->getContent());
        $this->assertTrue(isset($cuerpo->message) && $cuerpo->message !== '', 'Falta el message del 422. Cuerpo: '.$respuesta->getContent());

        return $cuerpo->errors;
    }

    /**
     * El tipo de movimiento con ese slug, creandolo solo si la base no lo tiene.
     *
     * @param  string  $slug
     * @return \App\Models\ProductionBatchMovementType
     */
    private function tipo_con_slug($slug)
    {
        $tipo = ProductionBatchMovementType::where('slug', $slug)->first();

        if (!is_null($tipo)) {
            return $tipo;
        }

        return $this->crear_tipo_de_movimiento('Tipo '.$slug, $slug);
    }

    /**
     * Un lote con un insumo que se consume en `Corte`, el fixture de los tests de movimientos.
     *
     * @return array [lote, cano, silla, corte, fin]
     */
    private function lote_con_insumo()
    {
        $corte = $this->crear_estado('Corte hacia estado test', 1);
        $fin   = $this->crear_estado('Fin hacia estado test', 2);

        $cano  = $this->crear_articulo('Cano hacia estado test', 100);
        $silla = $this->crear_articulo('Silla hacia estado test', 0);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => $corte->id],
        ], [
            'end_order_production_status_id' => $fin->id,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);

        return [$lote, $cano, $silla, $corte, $fin];
    }

    // -------------------------------------------------------------------------------------------
    // El insumo de la ruta sin "Estado"
    // -------------------------------------------------------------------------------------------

    /**
     * @group produccion_v2
     * @test
     */
    public function crear_una_ruta_con_un_insumo_sin_estado_da_422_y_no_crea_la_ruta()
    {
        $estado = $this->crear_estado('Corte ruta sin estado', 1);
        $cano   = $this->crear_articulo('Cano ruta sin estado test', 100);
        $tornillo = $this->crear_articulo('Tornillo ruta sin estado test', 100);
        $silla  = $this->crear_articulo('Silla ruta sin estado test', 0);
        $receta = $this->crear_receta($silla);

        /* El 0 es literalmente lo que manda el select cuando el usuario deja "Seleccione...". */
        $respuesta = $this->postJson('api/recipe-route', [
            'model_id'  => $receta->id,
            'articles'  => [
                $this->insumo($tornillo, $estado->id),
                $this->insumo($cano, 0),
            ],
        ]);

        $respuesta->assertStatus(422);

        $errors = $this->errors_como_objeto($respuesta);

        /* El aviso nombra el insumo que falta, no el que esta bien. */
        $this->assertCount(1, $errors->articles);
        $this->assertStringContainsString('"Cano ruta sin estado test"', $errors->articles[0]);
        $this->assertStringNotContainsString('Tornillo ruta sin estado test', $errors->articles[0]);

        /* Nada a medias: ni la ruta ni sus insumos. */
        $this->assertSame(0, RecipeRoute::where('recipe_id', $receta->id)->count());
    }

    /**
     * El estado puede faltar de tres maneras --0, null o la clave entera-- y las tres se rechazan.
     *
     * @group produccion_v2
     * @test
     */
    public function el_insumo_sin_estado_se_rechaza_venga_como_0_como_null_o_sin_la_clave()
    {
        $cano   = $this->crear_articulo('Cano tres formas test', 100);
        $silla  = $this->crear_articulo('Silla tres formas test', 0);
        $receta = $this->crear_receta($silla);

        foreach ([0, null, 'sin_clave'] as $estado_faltante) {

            $respuesta = $this->postJson('api/recipe-route', [
                'model_id'  => $receta->id,
                'articles'  => [$this->insumo($cano, $estado_faltante)],
            ]);

            $respuesta->assertStatus(422);

            $errors = $this->errors_como_objeto($respuesta);

            $this->assertStringContainsString('"Cano tres formas test"', $errors->articles[0], 'Estado faltante: '.var_export($estado_faltante, true));
        }

        $this->assertSame(0, RecipeRoute::where('recipe_id', $receta->id)->count());
    }

    /**
     * Si hay varios insumos sin estado, el aviso los nombra a todos de una vez: el usuario no
     * tiene que descubrirlos de a uno, guardando y fallando.
     *
     * @group produccion_v2
     * @test
     */
    public function el_aviso_nombra_todos_los_insumos_sin_estado()
    {
        $cano     = $this->crear_articulo('Cano varios sin estado test', 100);
        $tornillo = $this->crear_articulo('Tornillo varios sin estado test', 100);
        $silla    = $this->crear_articulo('Silla varios sin estado test', 0);
        $receta   = $this->crear_receta($silla);

        $respuesta = $this->postJson('api/recipe-route', [
            'model_id'  => $receta->id,
            'articles'  => [
                $this->insumo($cano, 0),
                $this->insumo($tornillo, null),
            ],
        ]);

        $respuesta->assertStatus(422);

        $errors = $this->errors_como_objeto($respuesta);

        $this->assertCount(2, $errors->articles);
        $this->assertStringContainsString('"Cano varios sin estado test"', $errors->articles[0]);
        $this->assertStringContainsString('"Tornillo varios sin estado test"', $errors->articles[1]);
    }

    /**
     * 🔴 UN 422 NO PUEDE DEJAR LA RUTA SIN INSUMOS.
     *
     * `GeneralHelper::attachModels()` hace `detach()` de TODOS los insumos antes de re-adjuntar.
     * Si la validacion corriera despues, un PUT con un insumo sin estado dejaria la ruta vacia y
     * el aviso llegaria tarde: el usuario perderia los insumos que ya tenia bien cargados. La
     * validacion va antes de tocar nada, incluidos los campos de la propia ruta.
     *
     * @group produccion_v2
     * @test
     */
    public function editar_una_ruta_con_un_insumo_sin_estado_da_422_y_conserva_todo_lo_de_antes()
    {
        $estado   = $this->crear_estado('Corte editar sin estado', 1);
        $cano     = $this->crear_articulo('Cano editar sin estado test', 100);
        $tornillo = $this->crear_articulo('Tornillo editar sin estado test', 100);
        $silla    = $this->crear_articulo('Silla editar sin estado test', 0);
        $receta   = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano,     'amount' => 2, 'order_production_status_id' => $estado->id],
            ['article' => $tornillo, 'amount' => 3, 'order_production_status_id' => $estado->id],
        ]);

        DB::table('recipe_routes')->where('id', $ruta->id)->update(['notes' => 'Original']);

        $respuesta = $this->putJson('api/recipe-route/'.$ruta->id, [
            'notes'     => 'Cambiada',
            'articles'  => [
                $this->insumo($cano, $estado->id),
                $this->insumo($tornillo, 0),
            ],
        ]);

        $respuesta->assertStatus(422);

        $errors = $this->errors_como_objeto($respuesta);

        $this->assertStringContainsString('"Tornillo editar sin estado test"', $errors->articles[0]);

        /* Los dos insumos de antes siguen ahi, con su estado y su cantidad. */
        $this->assertSame(2, DB::table('article_recipe_route')->where('recipe_route_id', $ruta->id)->count());

        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id'               => $ruta->id,
            'article_id'                    => $cano->id,
            'amount'                        => 2,
            'order_production_status_id'    => $estado->id,
        ]);

        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id'               => $ruta->id,
            'article_id'                    => $tornillo->id,
            'amount'                        => 3,
            'order_production_status_id'    => $estado->id,
        ]);

        /* Y la ruta misma no se toco: el 422 es antes de cualquier escritura. */
        $this->assertDatabaseHas('recipe_routes', ['id' => $ruta->id, 'notes' => 'Original']);
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function crear_y_editar_una_ruta_con_estados_reales_guarda_el_estado_de_cada_insumo()
    {
        $corte    = $this->crear_estado('Corte estados reales', 1);
        $armado   = $this->crear_estado('Armado estados reales', 2);
        $cano     = $this->crear_articulo('Cano estados reales test', 100);
        $tornillo = $this->crear_articulo('Tornillo estados reales test', 100);
        $silla    = $this->crear_articulo('Silla estados reales test', 0);
        $receta   = $this->crear_receta($silla);

        $respuesta = $this->postJson('api/recipe-route', [
            'model_id'  => $receta->id,
            'articles'  => [
                $this->insumo($cano, $corte->id),
                $this->insumo($tornillo, $armado->id),
            ],
        ]);

        $respuesta->assertStatus(201);

        $ruta_id = $respuesta->json('model.id');

        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id' => $ruta_id, 'article_id' => $cano->id, 'order_production_status_id' => $corte->id,
        ]);
        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id' => $ruta_id, 'article_id' => $tornillo->id, 'order_production_status_id' => $armado->id,
        ]);

        /* Editar: el tornillo pasa a Corte. */
        $respuesta = $this->putJson('api/recipe-route/'.$ruta_id, [
            'articles'  => [
                $this->insumo($cano, $corte->id),
                $this->insumo($tornillo, $corte->id),
            ],
        ]);

        $respuesta->assertStatus(200);

        $this->assertSame(2, DB::table('article_recipe_route')->where('recipe_route_id', $ruta_id)->count());

        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id' => $ruta_id, 'article_id' => $tornillo->id, 'order_production_status_id' => $corte->id,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // El "Hacia estado" del movimiento
    // -------------------------------------------------------------------------------------------

    /**
     * Los seis tipos del seeder (`ProductionBatchMovementTypeSeeder`): el "Hacia estado" es
     * obligatorio en todos, no solo en los que parecen moverse de un estado a otro.
     *
     * @return array
     */
    private function los_seis_tipos()
    {
        return ['start', 'advance', 'send_to_provider', 'receive_from_provider', 'reject', 'adjust'];
    }

    /**
     * @group produccion_v2
     * @test
     */
    public function un_movimiento_sin_hacia_estado_da_422_en_los_seis_tipos_y_no_toca_nada()
    {
        list($lote, $cano, $silla, $corte, $fin) = $this->lote_con_insumo();

        foreach ($this->los_seis_tipos() as $slug) {

            $tipo = $this->tipo_con_slug($slug);

            /* Sin la clave, con 0 (el "Seleccione..." de la SPA) y con null. */
            $variantes = [
                'sin la clave'  => [],
                'con 0'         => ['to_order_production_status_id' => 0],
                'con null'      => ['to_order_production_status_id' => null],
            ];

            foreach ($variantes as $nombre_variante => $extra) {

                $respuesta = $this->postJson('api/production-batch-movement', array_merge([
                    'production_batch_id'               => $lote->id,
                    'production_batch_movement_type_id' => $tipo->id,
                    'amount'                            => 5,
                ], $extra));

                $contexto = 'Tipo '.$slug.', '.$nombre_variante.'. Cuerpo: '.$respuesta->getContent();

                $this->assertSame(422, $respuesta->getStatusCode(), $contexto);

                $errors = $this->errors_como_objeto($respuesta);

                $this->assertTrue(isset($errors->to_order_production_status_id), $contexto);
                $this->assertStringContainsString('Hacia estado', $errors->to_order_production_status_id[0], $contexto);
            }
        }

        /* Ninguno de los 18 intentos dejo rastro: ni movimiento, ni insumos, ni stock. */
        $this->assertSame(0, ProductionBatchMovement::where('production_batch_id', $lote->id)->count());
        $this->assertSame(0, ProductionBatchMovementInput::where('article_id', $cano->id)->count());
        $this->assertEquals(100, $this->stock_de($cano));
        $this->assertEquals(0, $this->stock_de($silla));
    }

    /**
     * El preview es la tablita que la SPA pide ANTES de guardar: tiene que avisar lo mismo, o el
     * usuario ve los insumos de "nada" y recien se entera al guardar.
     *
     * @group produccion_v2
     * @test
     */
    public function la_vista_previa_sin_hacia_estado_da_422_en_los_seis_tipos()
    {
        list($lote, $cano, $silla, $corte, $fin) = $this->lote_con_insumo();

        foreach ($this->los_seis_tipos() as $slug) {

            $tipo = $this->tipo_con_slug($slug);

            foreach ([[], ['to_order_production_status_id' => 0]] as $extra) {

                $respuesta = $this->postJson('api/production-batch-movement/preview', array_merge([
                    'production_batch_id'               => $lote->id,
                    'production_batch_movement_type_id' => $tipo->id,
                    'amount'                            => 5,
                ], $extra));

                $contexto = 'Tipo '.$slug.'. Cuerpo: '.$respuesta->getContent();

                $this->assertSame(422, $respuesta->getStatusCode(), $contexto);

                $errors = $this->errors_como_objeto($respuesta);

                $this->assertStringContainsString('Hacia estado', $errors->to_order_production_status_id[0], $contexto);
            }
        }
    }

    /**
     * El lado de adentro de la puerta: con un estado real los seis tipos siguen guardando.
     *
     * @group produccion_v2
     * @test
     */
    public function con_hacia_estado_real_los_seis_tipos_guardan_y_la_vista_previa_responde()
    {
        list($lote, $cano, $silla, $corte, $fin) = $this->lote_con_insumo();

        foreach ($this->los_seis_tipos() as $slug) {

            $tipo = $this->tipo_con_slug($slug);

            $datos = [
                'production_batch_id'               => $lote->id,
                'production_batch_movement_type_id' => $tipo->id,
                'to_order_production_status_id'     => $corte->id,
                'amount'                            => 1,
            ];

            $this->postJson('api/production-batch-movement/preview', $datos)->assertStatus(200);

            $this->postJson('api/production-batch-movement', $datos)->assertStatus(201);
        }

        $this->assertSame(6, ProductionBatchMovement::where('production_batch_id', $lote->id)->count());

        /* 6 movimientos x 1 unidad x 2 de caño por unidad. */
        $this->assertEquals(88, $this->stock_de($cano));
    }

    // -------------------------------------------------------------------------------------------
    // Los datos que ya existen (Quino2)
    // -------------------------------------------------------------------------------------------

    /**
     * 🔴 EL CASO QUINO2: UN INSUMO CUYO PIVOT QUEDO EN 0 NO SE CONSUME.
     *
     * La ruta se arma como estan las de produccion: el insumo insertado directo con el estado en
     * 0. A un movimiento hacia un estado real, ese insumo no le corresponde. Los otros insumos de
     * la misma ruta, con estado real, si se consumen: el test no puede pasar por no consumir nada.
     *
     * Ojo con lo que prueba: es una GUARDA de comportamiento. Pasaba igual con el codigo anterior,
     * porque un pivot en 0 nunca coincidio con un movimiento hacia un estado real. Lo que
     * discrimina el arreglo de calculate_planned_inputs es el test de reflexion de mas abajo
     * (el_helper_no_planifica...), que llega al caso "destino 0" que el endpoint ya no deja pasar.
     *
     * @group produccion_v2
     * @test
     */
    public function un_insumo_con_el_estado_en_0_no_se_consume_y_los_demas_si()
    {
        $corte = $this->crear_estado('Corte quino2', 1);
        $fin   = $this->crear_estado('Fin quino2', 2);

        $cano     = $this->crear_articulo('Cano quino2 test', 100);
        $tornillo = $this->crear_articulo('Tornillo quino2 test', 100);
        $silla    = $this->crear_articulo('Silla quino2 test', 0);

        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano,     'amount' => 2, 'order_production_status_id' => 0],
            ['article' => $tornillo, 'amount' => 3, 'order_production_status_id' => $corte->id],
        ], [
            'end_order_production_status_id' => $fin->id,
        ]);

        $this->assertDatabaseHas('article_recipe_route', [
            'recipe_route_id' => $ruta->id, 'article_id' => $cano->id, 'order_production_status_id' => 0,
        ]);

        $lote = $this->crear_lote($silla, $receta, $ruta, 10);
        $tipo = $this->tipo_con_slug('start');

        $respuesta = $this->postJson('api/production-batch-movement', [
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => $corte->id,
            'amount'                            => 5,
        ]);

        $respuesta->assertStatus(201);

        /* El de estado real baja: 100 - (3 x 5). El de estado 0 no se toca. */
        $this->assertEquals(85, $this->stock_de($tornillo));
        $this->assertEquals(100, $this->stock_de($cano));

        $movimiento_id = $respuesta->json('model.id');

        $this->assertSame(1, ProductionBatchMovementInput::where('production_batch_movement_id', $movimiento_id)->count());
        $this->assertSame(0, ProductionBatchMovementInput::where('production_batch_movement_id', $movimiento_id)->where('article_id', $cano->id)->count());
    }

    /**
     * La defensa de adentro: `calculate_planned_inputs()` ignora el insumo con estado 0 aun si
     * el estado destino que le llega es 0 tambien.
     *
     * ⚠️ Este es el unico test del archivo que llama a un metodo del helper (privado, por
     * reflexion) en vez de pegarle al endpoint, y es a proposito: por el endpoint no hay forma de
     * llegar --el 422 de arriba corta antes de que el helper vea un destino 0--, asi que no hay
     * comportamiento observable por la API. Lo que protege es la linea de abajo: si alguien
     * saca la guarda del 422, un movimiento hacia "0" volveria a consumir, EN SILENCIO, todos los
     * insumos que quedaron con el estado en 0 en las rutas viejas.
     *
     * @group produccion_v2
     * @test
     */
    public function el_helper_no_planifica_un_insumo_con_estado_0_aunque_el_destino_sea_0()
    {
        $corte  = $this->crear_estado('Corte helper 0', 1);
        $cano   = $this->crear_articulo('Cano helper 0 test', 100);
        $silla  = $this->crear_articulo('Silla helper 0 test', 0);
        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 2, 'order_production_status_id' => 0],
        ]);

        $metodo = new ReflectionMethod(ProductionBatchMovementHelper::class, 'calculate_planned_inputs');
        $metodo->setAccessible(true);

        $this->assertSame([], $metodo->invoke(null, RecipeRoute::with('articles')->find($ruta->id), 0, 5));
        $this->assertSame([], $metodo->invoke(null, RecipeRoute::with('articles')->find($ruta->id), $corte->id, 5));
    }

    /**
     * 🔴 BORRAR SIGUE FUNCIONANDO PARA LOS MOVIMIENTOS VIEJOS CON "HACIA ESTADO" EN 0.
     *
     * La puerta nueva esta en el alta, no en el borrado: en produccion hay movimientos guardados
     * con `to_order_production_status_id` = 0 y el usuario tiene que poder borrarlos (y que se le
     * devuelvan los insumos). Un `delete_movement` que empezara a validar los dejaria
     * imborrables para siempre.
     *
     * @group produccion_v2
     * @test
     */
    public function borrar_un_movimiento_viejo_con_hacia_estado_0_devuelve_los_insumos()
    {
        $fin = $this->crear_estado('Fin movimiento viejo', 1);

        /* El caño ya descontado: 100 menos los 3 que este movimiento consumio. */
        $cano   = $this->crear_articulo('Cano movimiento viejo test', 97);
        $silla  = $this->crear_articulo('Silla movimiento viejo test', 0);
        $receta = $this->crear_receta($silla);

        $ruta = $this->crear_ruta($receta, [], ['end_order_production_status_id' => $fin->id]);
        $lote = $this->crear_lote($silla, $receta, $ruta, 10);
        $tipo = $this->tipo_con_slug('advance');

        $movimiento = ProductionBatchMovement::create([
            'production_batch_id'               => $lote->id,
            'production_batch_movement_type_id' => $tipo->id,
            'to_order_production_status_id'     => 0,
            'amount'                            => 5,
        ]);

        ProductionBatchMovementInput::create([
            'production_batch_movement_id'  => $movimiento->id,
            'article_id'                    => $cano->id,
            'planned_amount'                => 3,
            'actual_amount'                 => 3,
        ]);

        $this->delete('api/production-batch-movement/'.$movimiento->id)->assertStatus(204);

        $this->assertDatabaseMissing('production_batch_movements', ['id' => $movimiento->id]);
        $this->assertEquals(100, $this->stock_de($cano));
        $this->assertEquals(0, $this->stock_de($silla));
    }
}
