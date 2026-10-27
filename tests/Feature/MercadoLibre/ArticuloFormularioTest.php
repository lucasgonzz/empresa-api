<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use App\Models\SyncToMeliArticle;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Mercado Libre en el formulario del artículo (misión mercado-libre-integracion, 27/10/2026):
 * store/update con y sin las claves `meli_category_id` / `meli_attributes` (sin las claves, la SPA
 * vieja no pisa nada), el estado de la publicación con lo que falta, "Sincronizar ahora" y la
 * tenencia de esas rutas.
 *
 * @group mercado-libre
 */
class ArticuloFormularioTest extends MercadoLibreTestCase
{
    /** @var \App\Models\MeliCategory */
    protected $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = $this->categoria_local('MLA5232');
    }

    /**
     * Payload mínimo del formulario del artículo (el mismo que usan las suites del slug).
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    protected function payload_articulo(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Articulo formulario ML '.uniqid(),
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 1,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Atributos ML guardados del artículo, `[meli_id => [value_id, value_name]]`.
     *
     * @param int $article_id
     * @return array<string, array<int, string|null>>
     */
    protected function atributos_guardados($article_id)
    {
        $guardados = [];

        foreach (ArticleMeliAttribute::where('article_id', $article_id)->with('meli_attribute')->get() as $fila) {
            $guardados[$fila->meli_attribute->meli_id] = [$fila->value_id, $fila->value_name];
        }

        ksort($guardados);

        return $guardados;
    }

    public function test_el_alta_guarda_la_categoria_y_los_atributos_que_vienen()
    {
        $otra = $this->categoria_local('MLA7777');

        $respuesta = $this->postJson('/api/article', $this->payload_articulo([
            'meli_category_id' => $this->categoria->id,
            'meli_attributes'  => [
                ['meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id, 'value_id' => null, 'value_name' => 'M-100'],
                ['meli_attribute_id' => $this->atributo($this->categoria, 'COLOR')->id, 'value_id' => '52049', 'value_name' => 'Negro'],
                ['meli_attribute_id' => $this->atributo($this->categoria, 'SELLER_SKU')->id, 'value_id' => '', 'value_name' => ''],
                // De otra categoría: se descarta.
                ['meli_attribute_id' => $this->atributo($otra, 'BRAND')->id, 'value_id' => null, 'value_name' => 'Intrusa'],
            ],
        ]));

        $respuesta->assertStatus(201);

        $id = (int) $respuesta->json('model.id');
        $this->assertSame((string) $this->categoria->id, (string) Article::find($id)->meli_category_id);
        $this->assertSame((int) $this->categoria->id, (int) $respuesta->json('model.meli_category.id'));

        $this->assertSame([
            'COLOR' => ['52049', 'Negro'],
            'MODEL' => [null, 'M-100'],
        ], $this->atributos_guardados($id));

        $color = ArticleMeliAttribute::where('article_id', $id)->where('meli_attribute_id', $this->atributo($this->categoria, 'COLOR')->id)->first();
        $this->assertNotNull($color->meli_attribute_value_id, 'El valor de lista tiene que quedar atado a su meli_attribute_value.');
    }

    public function test_la_spa_vieja_sin_las_claves_no_pisa_la_categoria_ni_los_atributos()
    {
        $article = $this->articulo_ml($this->categoria);
        ArticleMeliAttribute::create([
            'article_id'        => $article->id,
            'meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id,
            'value_name'        => 'M-100',
        ]);

        $this->putJson('/api/article/'.$article->id, $this->payload_articulo([
            'id'   => $article->id,
            'name' => $article->name,
        ]))->assertStatus(200);

        $this->assertSame((string) $this->categoria->id, (string) $article->fresh()->meli_category_id);
        $this->assertSame(['MODEL' => [null, 'M-100']], $this->atributos_guardados($article->id));
    }

    public function test_la_edicion_con_un_atributo_vacio_lo_borra_y_reemplaza_el_resto()
    {
        $article = $this->articulo_ml($this->categoria);
        ArticleMeliAttribute::create([
            'article_id'        => $article->id,
            'meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id,
            'value_name'        => 'M-100',
        ]);

        $this->putJson('/api/article/'.$article->id, $this->payload_articulo([
            'id'               => $article->id,
            'name'             => $article->name,
            'meli_category_id' => $this->categoria->id,
            'meli_attributes'  => [
                ['meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id, 'value_id' => null, 'value_name' => ''],
                ['meli_attribute_id' => $this->atributo($this->categoria, 'BRAND')->id, 'value_id' => null, 'value_name' => 'Stanley'],
            ],
        ]))->assertStatus(200);

        $this->assertSame(['BRAND' => [null, 'Stanley']], $this->atributos_guardados($article->id));
    }

    /**
     * Contrato corregido por el chequeo del contrato (27/10/2026): la SPA nueva manda SIEMPRE
     * `meli_attributes` junto con la categoría cuando el usuario toca la parte de Mercado Libre
     * (lista vacía si la categoría nueva todavía no tiene datos cargados).
     */
    public function test_cambiar_de_categoria_con_la_lista_de_atributos_vacia_borra_los_de_la_anterior()
    {
        $otra = $this->categoria_local('MLA7777');
        $article = $this->articulo_ml($this->categoria);
        ArticleMeliAttribute::create([
            'article_id'        => $article->id,
            'meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id,
            'value_name'        => 'M-100',
        ]);

        $this->putJson('/api/article/'.$article->id, $this->payload_articulo([
            'id'               => $article->id,
            'name'             => $article->name,
            'meli_category_id' => $otra->id,
            'meli_attributes'  => [],
        ]))->assertStatus(200);

        $this->assertSame((string) $otra->id, (string) $article->fresh()->meli_category_id);
        $this->assertSame([], $this->atributos_guardados($article->id));
    }

    /**
     * El PUT real de cualquier pantalla manda el artículo ENTERO (`getModelToSend()`), con el
     * `meli_category_id` que tenía la fila. Con una fila vieja (null) y sin `meli_attributes`, no se
     * le borra nada al artículo.
     */
    public function test_un_guardado_con_la_categoria_en_null_y_sin_atributos_no_borra_nada()
    {
        $article = $this->articulo_ml($this->categoria);
        ArticleMeliAttribute::create([
            'article_id'        => $article->id,
            'meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id,
            'value_name'        => 'M-100',
        ]);

        $this->putJson('/api/article/'.$article->id, $this->payload_articulo([
            'id'               => $article->id,
            'name'             => $article->name,
            'meli_category_id' => null,
        ]))->assertStatus(200);

        $this->assertSame((string) $this->categoria->id, (string) $article->fresh()->meli_category_id);
        $this->assertSame(['MODEL' => [null, 'M-100']], $this->atributos_guardados($article->id));
    }

    /**
     * Un cambio de categoría que el usuario canceló (cerró sin guardar) puede quedar en la fila; si
     * el próximo guardado sale de otra solapa, viaja sin `meli_attributes` y no se aplica.
     */
    public function test_un_cambio_de_categoria_sin_atributos_no_se_aplica()
    {
        $otra = $this->categoria_local('MLA7777');
        $article = $this->articulo_ml($this->categoria);

        $this->putJson('/api/article/'.$article->id, $this->payload_articulo([
            'id'               => $article->id,
            'name'             => $article->name,
            'meli_category_id' => $otra->id,
        ]))->assertStatus(200);

        $this->assertSame((string) $this->categoria->id, (string) $article->fresh()->meli_category_id);
    }

    public function test_el_estado_dice_que_falta_para_publicar()
    {
        $article = $this->articulo_ml($this->categoria, ['final_price' => 900, 'bar_code' => null]);

        $respuesta = $this->getJson('/api/mercado-libre/articulo/'.$article->id);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure(['estado' => ['me_li_id', 'permalink', 'meli_status', 'ultima_sync', 'faltantes']]);
        $respuesta->assertJsonPath('estado.ultima_sync', null);

        $faltantes = $respuesta->json('estado.faltantes');
        $this->assertContains('El precio para Mercado Libre ($900) está debajo del mínimo de $1.000 que exige Mercado Libre.', $faltantes);
        $this->assertContains('Completar el atributo obligatorio «Marca».', $faltantes);
        $this->assertContains('Completar el atributo obligatorio «Modelo».', $faltantes);
        // GTIN es condicional y SELLER_SKU sale solo del SKU: no figuran como faltantes.
        foreach ($faltantes as $faltante) {
            $this->assertStringNotContainsString('universal', $faltante);
            $this->assertStringNotContainsString('«SKU»', $faltante);
        }
    }

    public function test_el_estado_sin_categoria_ni_fotos_ni_marcar_disponible()
    {
        $article = $this->articulo_ml(null, ['mercado_libre' => 0], false);

        $faltantes = $this->getJson('/api/mercado-libre/articulo/'.$article->id)->assertStatus(200)->json('estado.faltantes');

        $this->assertContains('Marcar «Disponible en Mercado Libre».', $faltantes);
        $this->assertContains('Elegir la categoría de Mercado Libre.', $faltantes);
        $this->assertContains('Cargar al menos una foto.', $faltantes);
    }

    public function test_sincronizar_ahora_encola_y_devuelve_el_estado()
    {
        $article = $this->articulo_ml($this->categoria);

        $respuesta = $this->postJson('/api/mercado-libre/articulo/'.$article->id.'/sincronizar');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado.ultima_sync.status', 'pendiente');
        $this->assertSame(1, SyncToMeliArticle::where('article_id', $article->id)->where('status', 'pendiente')->count());

        // Dos clics no duplican.
        $this->postJson('/api/mercado-libre/articulo/'.$article->id.'/sincronizar')->assertStatus(200);
        $this->assertSame(1, SyncToMeliArticle::where('article_id', $article->id)->count());
    }

    public function test_sincronizar_con_el_modulo_apagado_responde_422()
    {
        $this->apagar_env_usa_mercado_libre();
        $this->extension_usa_mercado_libre(false);
        $article = $this->articulo_ml($this->categoria);

        $this->postJson('/api/mercado-libre/articulo/'.$article->id.'/sincronizar')->assertStatus(422);
        $this->assertFalse(SyncToMeliArticle::where('article_id', $article->id)->exists());
    }

    public function test_las_rutas_del_articulo_no_dejan_ver_ni_sincronizar_el_de_otro_comercio()
    {
        $otro = User::create([
            'name'     => 'Otro comercio ML',
            'email'    => 'ml-tenencia-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);
        $ajeno = Article::create(['name' => 'Artículo ajeno ML', 'user_id' => $otro->id, 'status' => 'active']);

        $this->getJson('/api/mercado-libre/articulo/'.$ajeno->id)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'El artículo no existe o no es de tu cuenta.']);

        $this->postJson('/api/mercado-libre/articulo/'.$ajeno->id.'/sincronizar')->assertStatus(404);
        $this->assertFalse(SyncToMeliArticle::where('article_id', $ajeno->id)->exists());

        $this->getJson('/api/mercado-libre/articulo/no-es-un-id')->assertStatus(404);
    }
}
