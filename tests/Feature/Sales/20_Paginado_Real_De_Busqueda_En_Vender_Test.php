<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Http\Controllers\Helpers\VenderSearchHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mision "vender-search-nombre-paginado-real" (8/9/2026).
 *
 * El buscador de articulos de Vender (buscador-articulos/Index.vue) manda SIEMPRE un `contexto`
 * ('vender' o 'provider_order'), asi que el componente generico de busqueda del frontend nunca usa
 * la ruta vieja (search_nombre): siempre pega a `global-search/article`
 * (SearchController::globalSearch). Los dos endpoints comparten el mismo problema de fondo -- traer
 * TODOS los articulos que matchean el termino con las 27 relaciones de withAllSinAcopio() antes de
 * paginar en PHP -- y el mismo arreglo: separar "decidir que matchea" (barato, VenderSearchHelper::
 * match_descriptors()) de "construir la fila final" (caro, VenderSearchHelper::build_row()), y
 * correr lo caro SOLO sobre los articulos de la pagina pedida.
 *
 * Esta suite prueba tres cosas:
 *   1. Que match_descriptors() + build_row() (el camino nuevo, de dos fases) devuelve EXACTAMENTE
 *      lo mismo que expand_variants() (el camino viejo, de una sola pasada), para escenarios con y
 *      sin variantes -- la guardia de que el refactor no cambio ningun comportamiento.
 *   2. Que globalSearch(contexto=vender) pagina de verdad: con mas articulos que matchean que
 *      per_page, el total es el real, las paginas no se superponen, y la consulta que carga las 27
 *      relaciones toca SOLO los articulos de la pagina pedida (se mide contando los ids en el
 *      binding de esa query especifica, no una suposicion).
 *   3. Que search_nombre() (la ruta vieja, todavia viva para recipe/provider_order) sigue
 *      devolviendo el mismo contrato de respuesta que antes del refactor.
 */
class Paginado_Real_De_Busqueda_En_Vender_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario de prueba fresco, para no compartir ids con ningun otro test del slot.
     *
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio paginado ' . $sufijo,
            'email'    => 'paginado-vender-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Prende una extension para un usuario. Mismo patron que
     * Alertas/2_Recordatorio_de_cobro_Test.php::dar_extension().
     *
     * @param  \App\Models\User $user
     * @param  string           $slug
     * @return void
     */
    private function dar_extension($user, $slug)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => $slug,
                'name' => $slug,
            ]);
        }

        $user->extencions()->attach($extencion->id);
        $user->load('extencions');
    }

    /**
     * Articulo activo minimo, con nombre y precio final controlados.
     *
     * @param  \App\Models\User $user
     * @param  string           $name
     * @param  float|null       $final_price
     * @return \App\Models\Article
     */
    private function articulo($user, $name, $final_price = 100)
    {
        return Article::create([
            'name'         => $name,
            'user_id'      => $user->id,
            'status'       => 'active',
            'final_price'  => $final_price,
        ]);
    }

    /**
     * ------------------------------------------------------------------------------------------
     * 1. Equivalencia: expand_variants() (una sola pasada) vs match_descriptors()+build_row()
     *    (dos fases), sobre la MISMA coleccion completa. Tiene que dar exactamente lo mismo.
     * ------------------------------------------------------------------------------------------
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function match_descriptors_mas_build_row_da_lo_mismo_que_expand_variants_sin_variantes()
    {
        $user = $this->usuario_de_test('eq1');

        $this->articulo($user, 'Tornillo autoperforante', 50);
        $this->articulo($user, 'Tornillo philips', 75);
        $this->articulo($user, 'Clavo comun', 30); // no matchea "tornillo"

        // Misma consulta que search_nombre/globalSearch usarian (status + user_id + LIKE nombre).
        $articles = Article::where('status', 'active')
                        ->where('user_id', $user->id)
                        ->where('name', 'LIKE', '%tornillo%')
                        ->withAllSinAcopio()
                        ->get();

        $this->assertCount(2, $articles, 'La query SQL ya deja afuera al clavo.');

        $viejo = VenderSearchHelper::expand_variants($articles, 'tornillo');

        $descriptores = VenderSearchHelper::match_descriptors($articles, 'tornillo');
        $por_id = $articles->keyBy('id');
        $nuevo = $descriptores->map(function ($d) use ($por_id) {
            return VenderSearchHelper::build_row($por_id->get($d->article_id), null);
        })->values();

        $this->assertCount(2, $viejo);
        $this->assertCount(2, $nuevo);

        $ids_viejo = $viejo->pluck('id')->sort()->values()->all();
        $ids_nuevo = $nuevo->pluck('id')->sort()->values()->all();
        $this->assertEquals($ids_viejo, $ids_nuevo, 'Mismos articulos, mismo orden.');

        $this->assertEquals(
            $viejo->pluck('final_price')->all(),
            $nuevo->pluck('final_price')->all(),
            'Mismo precio final por articulo.'
        );
    }

    /**
     * Mismo chequeo que el anterior, pero con la extension article_variants prendida: un articulo
     * con dos variantes que matchean por descripcion, y otro sin variantes. Es el camino que
     * construye el objeto plano (is_variant=true) en vez de devolver el modelo Article entero.
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function match_descriptors_mas_build_row_da_lo_mismo_que_expand_variants_con_variantes()
    {
        $user = $this->usuario_de_test('eq2');
        $this->dar_extension($user, 'article_variants');
        $this->actingAs($user, 'web');

        $remera = $this->articulo($user, 'Remera basica', 200);
        ArticleVariant::create(['article_id' => $remera->id, 'variant_description' => 'Talle M', 'oculta' => false, 'stock' => 5]);
        ArticleVariant::create(['article_id' => $remera->id, 'variant_description' => 'Talle L', 'oculta' => false, 'stock' => 3]);
        ArticleVariant::create(['article_id' => $remera->id, 'variant_description' => 'Talle XL oculta', 'oculta' => true, 'stock' => 1]);

        $this->articulo($user, 'Pantalon basico', 300); // sin variantes, no matchea "talle"

        $articles = Article::where('status', 'active')
                        ->where('user_id', $user->id)
                        ->where(function ($q) {
                            $q->where('name', 'LIKE', '%remera%')
                              ->orWhereHas('article_variants', function ($vq) {
                                  $vq->where('variant_description', 'LIKE', '%talle%');
                              });
                        })
                        ->withAllSinAcopio()
                        ->get();

        $viejo = VenderSearchHelper::expand_variants($articles, 'talle');

        $descriptores = VenderSearchHelper::match_descriptors($articles, 'talle');
        $por_id = $articles->keyBy('id');
        $nuevo = $descriptores->map(function ($d) use ($por_id) {
            $articulo = $por_id->get($d->article_id);
            $variante = is_null($d->variant_id) ? null : $articulo->article_variants->firstWhere('id', $d->variant_id);
            return VenderSearchHelper::build_row($articulo, $variante);
        })->values();

        // Las dos variantes visibles (Talle M y Talle L) matchean "talle" en variant_description.
        // La oculta no participa (oculta=true) y el articulo sin variantes tampoco (su nombre no
        // matchea "talle" y no tiene variantes que lo hagan matchear).
        $this->assertCount(2, $viejo);
        $this->assertCount(2, $nuevo);

        $this->assertEquals(
            $viejo->pluck('variant_id')->sort()->values()->all(),
            $nuevo->pluck('variant_id')->sort()->values()->all(),
            'Mismas variantes.'
        );
        $this->assertEquals(
            $viejo->pluck('name')->sort()->values()->all(),
            $nuevo->pluck('name')->sort()->values()->all(),
            'Mismo nombre compuesto (articulo + variant_description).'
        );
        $this->assertTrue($viejo->every(function ($fila) { return $fila->is_variant === true; }));
        $this->assertTrue($nuevo->every(function ($fila) { return $fila->is_variant === true; }));
    }

    /**
     * ------------------------------------------------------------------------------------------
     * 2. globalSearch(contexto=vender) pagina de verdad: total real, paginas sin superponerse, y
     *    la consulta de las 27 relaciones acotada a la pagina pedida (no a todo lo que matchea).
     * ------------------------------------------------------------------------------------------
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function global_search_con_contexto_vender_pagina_de_verdad()
    {
        $user = $this->usuario_de_test('gs1');
        $this->actingAs($user, 'web');

        // 12 articulos que matchean "tornillo", 3 que no.
        for ($i = 1; $i <= 12; $i++) {
            $this->articulo($user, 'Tornillo ' . $i, 10 + $i);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->articulo($user, 'Clavo ' . $i, 5);
        }

        $payload = [
            'query_value' => 'tornillo',
            'props'       => ['name', 'provider_code'],
            'contexto'    => 'vender',
            'per_page'    => 5,
        ];

        // --- Pagina 1 ---
        DB::enableQueryLog();
        $res1 = $this->postJson('api/global-search/article?page=1', $payload);
        $log1 = DB::getQueryLog();
        DB::disableQueryLog();

        $res1->assertStatus(200);
        $body1 = $res1->json();

        $this->assertEquals(12, $body1['models']['total'], 'El total tiene que ser el real (los 12 que matchean), no el tamano de la pagina.');
        $this->assertEquals(3, $body1['models']['last_page']);
        $this->assertCount(5, $body1['models']['data']);

        // La query que carga las 27 relaciones es un "select ... from articles where id in (...)".
        // Sus bindings tienen que ser EXACTAMENTE los 5 articulos de la pagina, no los 12 que
        // matchean el termino -- esta es la prueba concreta de que el paginado ya no trae todo.
        $query_fase_2 = collect($log1)->first(function ($q) {
            return stripos($q['query'], 'from `articles`') !== false
                && stripos($q['query'], 'where `id` in') !== false;
        });

        $this->assertNotNull($query_fase_2, 'Tiene que existir una consulta de articulos por id (Fase 2).');
        $this->assertCount(5, $query_fase_2['bindings'], 'La Fase 2 tiene que traer solo los articulos de la pagina pedida (5), no los 12 que matchean.');

        $ids_pagina_1 = collect($body1['models']['data'])->pluck('id')->sort()->values()->all();

        // --- Pagina 3 (la ultima, con el resto: 12 - 5 - 5 = 2) ---
        $res3 = $this->postJson('api/global-search/article?page=3', $payload);
        $res3->assertStatus(200);
        $body3 = $res3->json();

        $this->assertEquals(12, $body3['models']['total']);
        $this->assertCount(2, $body3['models']['data'], 'La ultima pagina tiene el resto: 12 - 5 - 5 = 2.');

        $ids_pagina_3 = collect($body3['models']['data'])->pluck('id')->sort()->values()->all();

        $this->assertEmpty(
            array_intersect($ids_pagina_1, $ids_pagina_3),
            'La pagina 1 y la pagina 3 no pueden compartir ningun articulo.'
        );
    }

    /**
     * ------------------------------------------------------------------------------------------
     * 3. search_nombre() (la ruta vieja) sigue devolviendo el mismo contrato de respuesta.
     * ------------------------------------------------------------------------------------------
     *
     * @group sales
     * @group vender-search
     * @test
     */
    public function search_nombre_sigue_con_el_mismo_contrato_de_respuesta()
    {
        $user = $this->usuario_de_test('sn1');
        $this->actingAs($user, 'web');

        $this->articulo($user, 'Tornillo autoperforante', 123.45);
        $this->articulo($user, 'Clavo comun', 30);

        $res = $this->postJson('api/vender/buscar-articulo-por-nombre/0', ['query_value' => 'tornillo']);

        $res->assertStatus(200);
        $body = $res->json();

        $this->assertArrayHasKey('current_page', $body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('per_page', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('last_page', $body);

        $this->assertEquals(1, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('Tornillo autoperforante', $body['data'][0]['name']);
        $this->assertEquals(123.45, $body['data'][0]['final_price']);
    }
}
