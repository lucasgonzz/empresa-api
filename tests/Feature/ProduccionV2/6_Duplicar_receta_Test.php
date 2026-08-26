<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\Article;
use App\Models\Recipe;
use App\Models\RecipeRoute;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Duplicar un modelo completo: el articulo nuevo mas su receta entera, en un paso.
 *
 * Lo que este test cuida, mas alla de que la copia exista, es que NO se herede nada que
 * identifique al producto original: ni el codigo de barras (el lector de la caja levantaria el
 * articulo equivocado), ni la clave del proveedor, ni el stock. Y que la receta original quede
 * intacta.
 */
class Duplicar_receta_Test extends ProduccionV2TestCase
{
    /**
     * @group produccion_v2
     * @test
     */
    public function duplicar_crea_el_articulo_y_la_receta_sin_heredar_la_identidad_del_original()
    {
        $corte   = $this->crear_estado('Corte duplicar', 1);
        $soldado = $this->crear_estado('Soldado duplicar', 2);

        $tipo_ruta = $this->crear_tipo_de_ruta('Interna duplicar');

        /* El original tiene codigo de barras, codigo de proveedor y stock: nada de eso se hereda. */
        $original = $this->crear_articulo('Silla 100 duplicar test', 25, 4321);
        $original->bar_code       = '7791234567890';
        $original->provider_code  = 'PROV-XYZ-1';
        $original->sku            = 'SKU-100';
        $original->plu            = '5510';
        $original->save();

        $cano    = $this->crear_articulo('Cano duplicar test', 900);
        $remache = $this->crear_articulo('Remache duplicar test', 900);

        $receta = $this->crear_receta($original);

        /* Dos rutas, y el cano repetido en dos estados de la primera. */
        $ruta_a = $this->crear_ruta($receta, [
            ['article' => $cano,    'amount' => 2, 'order_production_status_id' => $corte->id],
            ['article' => $cano,    'amount' => 3, 'order_production_status_id' => $soldado->id],
            ['article' => $remache, 'amount' => 8, 'order_production_status_id' => $soldado->id],
        ], [
            'recipe_route_type_id'              => $tipo_ruta->id,
            'end_order_production_status_id'    => $soldado->id,
        ]);

        $ruta_b = $this->crear_ruta($receta, [
            ['article' => $remache, 'amount' => 1, 'order_production_status_id' => $corte->id],
        ]);

        $respuesta = $this->post('api/recipe/'.$receta->id.'/duplicar', [
            'name' => 'Silla 200 duplicar test',
        ]);

        $respuesta->assertStatus(201);

        /* ── El articulo nuevo ──────────────────────────────────────────────────────────────── */

        $nuevo = Article::where('name', 'Silla 200 duplicar test')
                        ->where('user_id', $this->comercio()->id)
                        ->first();

        $this->assertNotNull($nuevo);

        /* Lo que SI se hereda: lo comercial. */
        $this->assertEquals($original->cost, $nuevo->cost);
        $this->assertEquals($original->provider_id, $nuevo->provider_id);
        $this->assertEquals($original->es_insumo, $nuevo->es_insumo);

        /* Lo que NO: identidad fisica del producto y stock. */
        $this->assertNull($nuevo->bar_code);
        $this->assertNull($nuevo->provider_code);
        $this->assertNull($nuevo->sku);
        $this->assertNull($nuevo->plu);
        $this->assertNull($nuevo->stock);
        $this->assertNull($nuevo->num);

        /* Y NO se publica solo en la tienda online (decision reversible del helper). */
        $this->assertEquals(0, (int) $nuevo->needs_sync_with_tn);

        /* El slug se regenera y no se pisa con el del original. */
        $this->assertNotEquals($original->slug, $nuevo->slug);

        /* No se creo ningun movimiento de stock para el articulo nuevo. */
        $this->assertDatabaseMissing('stock_movements', ['article_id' => $nuevo->id]);

        /* ── La receta nueva ────────────────────────────────────────────────────────────────── */

        $nueva_receta = Recipe::where('article_id', $nuevo->id)->first();

        $this->assertNotNull($nueva_receta);
        $this->assertEquals($respuesta->json('model.id'), $nueva_receta->id);

        $rutas_nuevas = RecipeRoute::where('recipe_id', $nueva_receta->id)->orderBy('id', 'ASC')->get();

        $this->assertCount(2, $rutas_nuevas);

        $nueva_a = $rutas_nuevas[0];
        $nueva_b = $rutas_nuevas[1];

        /* La ruta copia su tipo y su estado final propio. */
        $this->assertEquals($ruta_a->recipe_route_type_id, $nueva_a->recipe_route_type_id);
        $this->assertEquals($soldado->id, $nueva_a->end_order_production_status_id);

        /*
         * 🔴 TRES renglones, no dos: el cano aparece en dos estados y un sync() los habria
         * colapsado en uno solo, con lo cual la receta duplicada consumiria menos insumo que la
         * original sin que nadie lo note.
         */
        $this->assertCount(3, $nueva_a->articles);
        $this->assertCount(1, $nueva_b->articles);

        $renglones = [];

        foreach ($nueva_a->articles as $insumo) {
            $renglones[] = [
                'article_id'                    => (int) $insumo->id,
                'amount'                        => (float) $insumo->pivot->amount,
                'order_production_status_id'    => (int) $insumo->pivot->order_production_status_id,
            ];
        }

        $this->assertContains(['article_id' => (int) $cano->id,    'amount' => 2.0, 'order_production_status_id' => (int) $corte->id], $renglones);
        $this->assertContains(['article_id' => (int) $cano->id,    'amount' => 3.0, 'order_production_status_id' => (int) $soldado->id], $renglones);
        $this->assertContains(['article_id' => (int) $remache->id, 'amount' => 8.0, 'order_production_status_id' => (int) $soldado->id], $renglones);

        /* ── La receta original quedo intacta ───────────────────────────────────────────────── */

        $original_recargada = Recipe::find($receta->id);

        $this->assertEquals($original->id, $original_recargada->article_id);
        $this->assertEquals(2, RecipeRoute::where('recipe_id', $receta->id)->count());
        $this->assertCount(3, RecipeRoute::find($ruta_a->id)->articles);
        $this->assertCount(1, RecipeRoute::find($ruta_b->id)->articles);

        /* Y el original no perdio su codigo de barras ni su stock. */
        $original->refresh();
        $this->assertEquals('7791234567890', $original->bar_code);
        $this->assertEquals(25, (float) $original->stock);
    }

    /**
     * 🔴 EL DUPLICAR AVISA QUE LOS INSUMOS FABRICADOS SIGUEN APUNTANDO AL MODELO ORIGINAL.
     *
     * Duplicando "Silla 1" -> "Silla 2", la receta nueva sigue consumiendo *Estructura silla 1*.
     * Para una receta hoja (patas -> cano) esta perfecto; para una de ensamble esta mal, y hasta
     * ahora nada lo avisaba. El sistema no puede re-apuntarlos solo (no sabe cual es la parte
     * equivalente del otro modelo), pero si puede nombrarlos.
     *
     * Solo los que TIENEN RECETA PROPIA: la materia prima no se lista porque ahi no hay nada que
     * revisar.
     *
     * @group produccion_v2
     * @test
     */
    public function duplicar_devuelve_los_insumos_fabricados_que_hay_que_revisar()
    {
        $corte = $this->crear_estado('Corte revisar', 1);

        $silla = $this->crear_articulo('Silla 1 revisar test', 0);

        /* Dos insumos fabricados (tienen receta propia) y uno de materia prima. */
        $estructura = $this->crear_articulo('Estructura silla 1 revisar test', 3);
        $asiento    = $this->crear_articulo('Asiento silla 1 revisar test', 3);
        $tornillo   = $this->crear_articulo('Tornillo revisar test', 900);

        $this->crear_receta($estructura);
        $this->crear_receta($asiento);

        $receta = $this->crear_receta($silla);

        /* La estructura aparece DOS VECES: se avisa una sola. */
        $this->crear_ruta($receta, [
            ['article' => $estructura, 'amount' => 1, 'order_production_status_id' => $corte->id],
            ['article' => $estructura, 'amount' => 1, 'order_production_status_id' => $corte->id],
            ['article' => $asiento,    'amount' => 1, 'order_production_status_id' => $corte->id],
            ['article' => $tornillo,   'amount' => 8, 'order_production_status_id' => $corte->id],
        ]);

        $respuesta = $this->post('api/recipe/'.$receta->id.'/duplicar', [
            'name' => 'Silla 2 revisar test',
        ]);

        $respuesta->assertStatus(201);

        $a_revisar = $respuesta->json('insumos_a_revisar');

        $this->assertNotNull($a_revisar);

        /* Los dos fabricados, sin repetir la estructura y sin el tornillo. */
        $this->assertCount(2, $a_revisar);

        $nombres = [];

        foreach ($a_revisar as $insumo) {
            $nombres[] = $insumo['article_name'];
            $this->assertArrayHasKey('article_id', $insumo);
        }

        $this->assertContains('Estructura silla 1 revisar test', $nombres);
        $this->assertContains('Asiento silla 1 revisar test', $nombres);
        $this->assertNotContains('Tornillo revisar test', $nombres);
    }

    /**
     * Una receta hoja —insumos que son todos materia prima— no tiene nada que revisar: el array
     * viaja vacio y la pantalla no muestra ningun aviso.
     *
     * @group produccion_v2
     * @test
     */
    public function duplicar_una_receta_hoja_no_devuelve_nada_para_revisar()
    {
        $corte = $this->crear_estado('Corte hoja', 1);

        $pata = $this->crear_articulo('Pata hoja revisar test', 0);
        $cano = $this->crear_articulo('Cano hoja revisar test', 900);

        $receta = $this->crear_receta($pata);

        $this->crear_ruta($receta, [
            ['article' => $cano, 'amount' => 1, 'order_production_status_id' => $corte->id],
        ]);

        $respuesta = $this->post('api/recipe/'.$receta->id.'/duplicar', [
            'name' => 'Pata reforzada hoja revisar test',
        ]);

        $respuesta->assertStatus(201);

        $this->assertEquals([], $respuesta->json('insumos_a_revisar'));
    }

    /**
     * 🔴 NO SE PUEDE DUPLICAR LA RECETA DE OTRO COMERCIO.
     *
     * El id llega crudo por la URL. Sin el filtro por user_id, cualquier cuenta se copiaba el
     * articulo entero de otra —con sus costos y sus listas de precio— mas la receta con todas
     * sus rutas, cantidades e insumos, y quedaba persistido.
     *
     * @group produccion_v2
     * @test
     */
    public function no_se_puede_duplicar_la_receta_de_otro_comercio()
    {
        $otro_comercio = User::create([
            'name'      => 'Otro comercio duplicar',
            'email'     => 'duplicar-otro-'.uniqid().'@test.local',
            'password'  => Hash::make('secret'),
        ]);

        $articulo_ajeno = Article::create([
            'name'      => 'Silla ajena duplicar test',
            'stock'     => 5,
            'cost'      => 999,
            'status'    => 'active',
            'user_id'   => $otro_comercio->id,
        ]);

        $receta_ajena = Recipe::create([
            'name'          => $articulo_ajeno->name,
            'article_id'    => $articulo_ajeno->id,
            'user_id'       => $otro_comercio->id,
        ]);

        $recetas_antes = Recipe::where('user_id', $this->comercio()->id)->count();

        /* La sesion es la del comercio de prueba, no la del duenio de esa receta. */
        $this->post('api/recipe/'.$receta_ajena->id.'/duplicar', [
            'name' => 'Silla robada duplicar test',
        ])->assertStatus(404);

        /* Y no quedo nada persistido en la cuenta que lo intento. */
        $this->assertDatabaseMissing('articles', [
            'name'      => 'Silla robada duplicar test',
            'user_id'   => $this->comercio()->id,
        ]);

        $this->assertEquals($recetas_antes, Recipe::where('user_id', $this->comercio()->id)->count());
    }
}
