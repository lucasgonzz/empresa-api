<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\MeliAttribute;
use App\Models\MeliCategory;
use Illuminate\Support\Facades\Http;

/**
 * Categorías de Mercado Libre para el formulario del artículo (misión mercado-libre-integracion,
 * 27/10/2026): predictor (`?q=` y la ruta vieja, misma forma), árbol, resolve de una hoja (crea la
 * categoría local con sus atributos, requeridos primero, sin los que completa ML) y 422 si no es
 * hoja. Todo anda SIN cuenta conectada, con el token de la app.
 *
 * @group mercado-libre
 */
class CategoriasTest extends MercadoLibreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MeliCategory::whereIn('meli_category_id', ['MLA5232', 'MLA9999', 'MLA1500'])->delete();
    }

    /**
     * Atributos de `categories/MLA5232/attributes` con tags variados.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function atributos_ml()
    {
        return [
            ['id' => 'COLOR', 'name' => 'Color', 'value_type' => 'list', 'values' => [['id' => '52049', 'name' => 'Negro']], 'tags' => []],
            ['id' => 'GTIN', 'name' => 'Código universal de producto', 'value_type' => 'string', 'tags' => ['conditional_required' => true, 'hidden' => true]],
            ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'ITEM_CONDITION', 'name' => 'Condición del ítem', 'value_type' => 'list', 'tags' => ['read_only' => true]],
            ['id' => 'PACKAGE_WEIGHT', 'name' => 'Peso del paquete', 'value_type' => 'number_unit', 'tags' => ['hidden' => true]],
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'relevance' => 1, 'tags' => ['required' => true]],
        ];
    }

    /**
     * Rutas del catálogo público + token de app.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function rutas_catalogo(array $extra = [])
    {
        return array_merge([
            'POST oauth/token'                => [200, ['access_token' => 'APP-TOKEN-DE-LA-APP', 'expires_in' => 21600]],
            'GET categories/MLA5232'          => [200, $this->categoria_ml_hoja('MLA5232', 60)],
            'GET categories/MLA5232/attributes' => [200, $this->atributos_ml()],
            'GET categories/MLA1500'          => [200, [
                'id'                  => 'MLA1500',
                'name'                => 'Construcción',
                'path_from_root'      => [['id' => 'MLA1500', 'name' => 'Construcción']],
                'children_categories' => [['id' => 'MLA2000', 'name' => 'Herramientas', 'total_items_in_this_category' => 10]],
                'settings'            => ['listing_allowed' => false, 'max_title_length' => 60],
            ]],
            'GET categories/MLA9999'          => [404, ['message' => 'Category not found']],
            'GET sites/MLA/categories'        => [200, [['id' => 'MLA1500', 'name' => 'Construcción'], ['id' => 'MLA1051', 'name' => 'Celulares y Teléfonos']]],
            'GET sites/MLA/domain_discovery/search' => [200, [
                ['domain_id' => 'MLA-HAMMERS', 'domain_name' => 'Martillos', 'category_id' => 'MLA5232', 'category_name' => 'Martillos', 'attributes' => []],
                ['domain_id' => 'MLA-HAMMERS-2', 'domain_name' => 'Martillos de goma', 'category_id' => 'MLA5232', 'category_name' => 'Martillos', 'attributes' => []],
            ]],
        ], $extra);
    }

    /**
     * Header Authorization de un pedido grabado.
     *
     * @param \Illuminate\Http\Client\Request $request
     * @return string|null
     */
    protected function autorizacion($request)
    {
        $valor = $request->header('Authorization');

        if (is_array($valor)) {
            return count($valor) > 0 ? $valor[0] : null;
        }

        return $valor;
    }

    public function test_resolve_de_una_hoja_sin_cuenta_conectada_crea_la_categoria_y_devuelve_los_atributos()
    {
        $this->fake_ml($this->rutas_catalogo());

        $respuesta = $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'MLA5232']);

        $respuesta->assertStatus(200);

        $model = $respuesta->json('model');
        $local = MeliCategory::where('meli_category_id', 'MLA5232')->first();

        $this->assertNotNull($local, 'No se creó la categoría local.');
        $this->assertSame((int) $local->id, $model['id']);
        $this->assertSame('MLA5232', $model['meli_category_id']);
        $this->assertSame('Martillos', $model['meli_category_name']);
        $this->assertSame('Construcción > Herramientas > Martillos', $model['path']);
        $this->assertTrue($model['listing_allowed']);
        $this->assertSame(60, $model['max_title_length']);

        $ids = array_map(function ($atributo) {
            return $atributo['meli_id'];
        }, $model['atributos']);

        // Requeridos primero (BRAND por relevancia), después el condicional, después el resto;
        // sin los de solo lectura ni los ocultos (salvo GTIN).
        $this->assertSame(['BRAND', 'MODEL', 'GTIN', 'COLOR'], $ids);

        $brand = $model['atributos'][0];
        $this->assertTrue($brand['required']);
        $this->assertFalse($brand['conditional_required']);
        $this->assertSame('brand', $brand['auto']);
        $this->assertSame((int) MeliAttribute::where('meli_category_id', $local->id)->where('meli_id', 'BRAND')->value('id'), $brand['id']);
        $this->assertSame(
            ['id', 'meli_id', 'name', 'value_type', 'required', 'conditional_required', 'auto', 'values', 'hint', 'tooltip', 'value_max_length'],
            array_keys($brand)
        );

        $this->assertTrue($model['atributos'][2]['conditional_required']);
        $this->assertSame('bar_code', $model['atributos'][2]['auto']);
        $this->assertSame([['id' => '52049', 'name' => 'Negro']], $model['atributos'][3]['values']);
        $this->assertNull($model['atributos'][3]['auto']);

        // Sin cuenta conectada, las consultas van con el token de la app.
        foreach ($this->pedidos_ml('GET', 'categories/*') as $pedido) {
            $this->assertSame('Bearer APP-TOKEN-DE-LA-APP', $this->autorizacion($pedido));
        }
        $canje = $this->pedidos_ml('POST', 'oauth/token');
        $this->assertCount(1, $canje, 'El token de app se pide una vez y se cachea.');
        $this->assertSame('client_credentials', $canje[0]['grant_type']);
    }

    public function test_resolve_de_una_categoria_que_no_es_hoja_responde_422()
    {
        $this->fake_ml($this->rutas_catalogo());

        $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'MLA1500'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Elegí una categoría más específica: en Mercado Libre solo se publica en la última categoría del árbol.']);

        $this->assertFalse(MeliCategory::where('meli_category_id', 'MLA1500')->exists());
    }

    public function test_resolve_de_una_categoria_inexistente_o_mal_escrita_responde_422()
    {
        $this->fake_ml($this->rutas_catalogo());

        $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'MLA9999'])->assertStatus(422);
        $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'cualquier cosa'])->assertStatus(422);
    }

    public function test_resolve_de_una_categoria_local_ya_cargada_no_vuelve_a_pedir_los_atributos()
    {
        $this->categoria_local('MLA5232');
        $this->fake_ml($this->rutas_catalogo());

        $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'MLA5232'])->assertStatus(200);

        $this->assertCount(0, $this->pedidos_ml('GET', 'categories/MLA5232/attributes'));
        $this->assertSame(1, MeliCategory::where('meli_category_id', 'MLA5232')->count());
    }

    public function test_el_predictor_devuelve_categorias_con_la_ruta_y_la_ruta_vieja_da_lo_mismo()
    {
        $this->fake_ml($this->rutas_catalogo());

        $nueva = $this->getJson('/api/meli-category-predictor?q='.urlencode('martillo de carpintero'));
        $nueva->assertStatus(200);
        $nueva->assertExactJson(['categories' => [[
            'category_id'   => 'MLA5232',
            'category_name' => 'Martillos',
            'domain_name'   => 'Martillos',
            'path'          => 'Construcción > Herramientas > Martillos',
        ]]]);

        $vieja = $this->getJson('/api/meli-category-predictor/'.rawurlencode('martillo de carpintero'));
        $vieja->assertStatus(200);
        $this->assertSame($nueva->json(), $vieja->json());

        $busqueda = $this->pedidos_ml('GET', 'sites/MLA/domain_discovery/search');
        $this->assertStringContainsString('q=martillo', urldecode($busqueda[0]->url()));
    }

    public function test_el_predictor_sin_texto_devuelve_vacio_sin_llamar_a_ml()
    {
        $this->fake_ml($this->rutas_catalogo());

        $this->getJson('/api/meli-category-predictor?q=')->assertStatus(200)->assertExactJson(['categories' => []]);

        $this->assertCount(0, $this->pedidos_ml('GET', 'sites/MLA/domain_discovery/search'));
    }

    public function test_el_arbol_devuelve_las_raiz_y_las_hijas_con_la_ruta()
    {
        $this->fake_ml($this->rutas_catalogo());

        $raiz = $this->getJson('/api/meli-category-children');
        $raiz->assertStatus(200);
        $raiz->assertExactJson([
            'categories' => [['id' => 'MLA1500', 'name' => 'Construcción'], ['id' => 'MLA1051', 'name' => 'Celulares y Teléfonos']],
            'path'       => [],
            'hoja'       => false,
        ]);

        $nodo = $this->getJson('/api/meli-category-children/MLA1500');
        $nodo->assertStatus(200);
        $nodo->assertExactJson([
            'categories' => [['id' => 'MLA2000', 'name' => 'Herramientas']],
            'path'       => [['id' => 'MLA1500', 'name' => 'Construcción']],
            'hoja'       => false,
        ]);

        $hoja = $this->getJson('/api/meli-category-children/MLA5232');
        $hoja->assertStatus(200);
        $hoja->assertJsonPath('hoja', true);
        $hoja->assertJsonPath('categories', []);
    }

    public function test_con_cuenta_conectada_usa_el_token_del_vendedor_y_ante_un_403_reintenta_con_el_de_la_app()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_catalogo([
            'GET categories/MLA5232' => function ($request) {
                $auth = $this->autorizacion($request);

                return $auth === 'Bearer '.self::TOKEN
                    ? Http::response(['code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403)
                    : Http::response($this->categoria_ml_hoja('MLA5232', 60), 200);
            },
        ]));

        $this->postJson('/api/meli-category/resolve', ['meli_category_id' => 'MLA5232'])->assertStatus(200);

        $pedidos = $this->pedidos_ml('GET', 'categories/MLA5232');
        $this->assertSame('Bearer '.self::TOKEN, $this->autorizacion($pedidos[0]));
        $this->assertSame('Bearer APP-TOKEN-DE-LA-APP', $this->autorizacion($pedidos[1]));
    }

    public function test_asignar_categoria_desde_el_modal_devuelve_model_y_article()
    {
        $categoria = $this->categoria_local('MLA5232');
        $article = $this->articulo_ml(null);
        $this->fake_ml($this->rutas_catalogo());

        $respuesta = $this->getJson('/api/meli-category-predictor/asignar-meli-category/'.$article->id.'/MLA5232');

        $respuesta->assertStatus(200);
        $this->assertSame((int) $article->id, (int) $respuesta->json('model.id'));
        $this->assertSame((int) $article->id, (int) $respuesta->json('article.id'));
        $this->assertSame((string) $categoria->id, (string) $article->fresh()->meli_category_id);
    }
}
