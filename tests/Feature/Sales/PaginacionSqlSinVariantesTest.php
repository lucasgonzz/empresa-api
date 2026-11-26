<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\VenderSearchHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaginacionSqlSinVariantesTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::create(['name' => 'Catálogo paginado', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $this->actingAs($this->dueno, 'web');
    }

    private function extension($slug, $activa)
    {
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        if ($activa) {
            $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        } else {
            $this->dueno->extencions()->detach($extension->id);
        }
        $this->dueno->load('extencions');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    private function catalogo($cantidad)
    {
        $filas = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $filas[] = ['user_id' => $this->dueno->id, 'name' => 'Tornillo volumen ' . $i,
                'status' => 'active', 'provider_code' => 'TV-' . $i, 'bar_code' => 'COD-' . $i,
                'stock' => $i % 3, 'created_at' => '2026-11-17 01:00:00', 'updated_at' => '2026-11-17 01:00:00'];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('articles')->insert($lote);
        }
    }

    public function test_un_catalogo_amplio_solo_materializa_la_pagina_en_ambas_rutas()
    {
        $this->catalogo(3000);
        $recuperados = 0;
        Event::listen('eloquent.retrieved: ' . Article::class, function ($article) use (&$recuperados) {
            if (strpos((string) $article->name, 'Tornillo volumen ') === 0) {
                $recuperados++;
            }
        });

        DB::enableQueryLog();
        $respuesta = $this->postJson('api/global-search/article?page=2', [
            'query_value' => 'tornillo', 'props' => ['name'], 'contexto' => 'vender', 'per_page' => 7,
        // Cambio de contrato (misión busqueda-vender-conteo-acotado-siempre, 26/11/2026): el total de
        // Vender se acota SIEMPRE, también con criterio largo. Página 2 de 7: tope = 7 + 7*20 = 147, así
        // que con 3000 coincidencias el piso informado es 148 y la bandera avisa que no es el total.
        ])->assertStatus(200)->assertJsonPath('models.total', 148)->assertJsonPath('total_es_minimo', true)->assertJsonCount(7, 'models.data');
        $sql = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame(14, $recuperados, 'Solo siete modelos livianos y siete completos; los otros 2993 no se hidratan.');
        $this->assertTrue($sql->contains(function ($query) { return stripos($query, 'count(*) as aggregate') !== false; }));
        $this->assertTrue($sql->contains(function ($query) { return stripos($query, 'limit 7 offset 7') !== false; }));

        $recuperados = 0;
        $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=2', ['query_value' => 'tornillo'])
            ->assertStatus(200)->assertJsonPath('total', 3000)->assertJsonCount(50, 'data');
        $this->assertSame(100, $recuperados, 'La ruta nativa también hidrata solo cincuenta livianos y cincuenta completos.');
    }

    public function test_global_conserva_el_resultado_del_camino_anterior_sin_fanout()
    {
        $this->catalogo(12);
        foreach (['vender', 'provider_order', 'recipe'] as $contexto) {
            foreach ([1, 2, 4] as $pagina) {
                $payload = ['query_value' => 'tornillo', 'props' => ['name', 'provider_code'],
                    'contexto' => $contexto, 'per_page' => 5, 'order_by' => 'name', 'order_direction' => 'ASC',
                    'extra_filters' => [['key' => 'stock', 'operator' => '>', 'value' => 0]]];
                $this->extension('article_variants', false);
                $rapido = $this->postJson('api/global-search/article?page=' . $pagina, $payload)->assertStatus(200)->json();
                // Con la extensión prendida sigue ejecutándose el camino anterior. En este fixture
                // no hay fanout ni descartes: da un oráculo independiente del nuevo COUNT/LIMIT.
                $this->extension('article_variants', true);
                $anterior = $this->postJson('api/global-search/article?page=' . $pagina, $payload)->assertStatus(200)->json();
                $this->assertEquals($anterior, $rapido);
            }
        }
    }

    public function test_la_ruta_nativa_conserva_codigos_filtros_y_paginas()
    {
        $this->catalogo(63);
        $this->extension('search_bar_code_en_vender', true);
        foreach ([['query_value' => 'tornillo'], ['query_value' => 'COD-7'], ['query_value' => 'tornillo', 'stock_option' => 'con_stock']] as $payload) {
            foreach ([1, 2, 3] as $pagina) {
                $this->extension('article_variants', false);
                $rapido = $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=' . $pagina, $payload)->assertStatus(200)->json();
                $this->extension('article_variants', true);
                $anterior = $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=' . $pagina, $payload)->assertStatus(200)->json();
                $this->assertEquals($anterior, $rapido);
            }
        }
    }

    public function test_la_extension_activa_no_se_optimiza_aunque_no_haya_variantes()
    {
        $this->extension('article_variants', true);
        $this->assertNull(VenderSearchHelper::pagina_sql_si_no_expande(Article::where('user_id', $this->dueno->id), 5, 1));
    }

    public function test_la_ruta_nativa_sin_orden_definido_pagina_por_id_sin_repetir_con_filtros()
    {
        $this->catalogo(90);
        $payload = ['query_value' => 'tornillo', 'stock_option' => 'con_stock'];
        DB::enableQueryLog();
        $uno = $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=1', $payload)
            ->assertStatus(200)->assertJsonPath('total', 60)->json('data');
        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $dos = $this->postJson('api/vender/buscar-articulo-por-nombre/0?page=2', $payload)
            ->assertStatus(200)->assertJsonPath('total', 60)->json('data');
        $ids_uno = array_column($uno, 'id');
        $ids_dos = array_column($dos, 'id');
        $this->assertCount(50, $ids_uno);
        $this->assertCount(10, $ids_dos);
        $this->assertEmpty(array_intersect($ids_uno, $ids_dos));
        $todos = array_merge($ids_uno, $ids_dos);
        $ordenados = $todos;
        sort($ordenados, SORT_NUMERIC);
        $this->assertSame($ordenados, $todos);
        // Sin ORDER explícito, un plan diferente para LIMIT puede cambiar páginas aunque el
        // fixture de hoy devuelva casualmente IDs ascendentes. Exigir también la garantía SQL.
        $this->assertTrue($consultas->contains(function ($query) {
            return stripos($query, 'order by `articles`.`id` asc') !== false
                && stripos($query, 'limit 50') !== false;
        }));
    }
}
