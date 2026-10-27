<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\Article;
use App\Models\ArticleMeliAttribute;
use App\Models\Brand;
use App\Models\PriceType;
use App\Models\SyncToMeliArticle;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\ProductService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Sincronización de artículos hacia Mercado Libre (misión mercado-libre-integracion, 27/10/2026):
 * alta clásica y User Products, validate antes de publicar, precio (fallback y mínimo), 429 →
 * pendiente, stock 0 en publicaciones nuevas vs gratuitas, artículo borrado, actualización sin
 * cambios, el gate de `add_article_to_sync` y el comando.
 *
 * @group mercado-libre
 */
class PublicacionTest extends MercadoLibreTestCase
{
    /** @var \App\Models\MeliCategory */
    protected $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->categoria = $this->categoria_local('MLA5232');
    }

    /**
     * Rutas de ML que usa cualquier alta.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function rutas_de_alta(array $extra = [])
    {
        return array_merge([
            'GET categories/MLA5232'                       => [200, $this->categoria_ml_hoja('MLA5232', 60)],
            'GET users/'.self::SELLER_ID.'/available_listing_types' => [200, ['available' => [['id' => 'gold_special'], ['id' => 'gold_pro'], ['id' => 'free']]]],
            'POST items/validate'                          => [204, null],
            'POST items'                                   => [201, [
                'id'              => 'MLA111',
                'permalink'       => 'https://articulo.mercadolibre.com.ar/MLA-111-martillo',
                'status'          => 'active',
                'user_product_id' => null,
            ]],
            'POST items/*/description'                     => [201, ['plain_text' => 'ok']],
            'PUT items/*/description'                      => [200, ['plain_text' => 'ok']],
        ], $extra);
    }

    /**
     * Ítem de ML ya publicado, con la forma de `GET items/{id}`.
     *
     * @param Article $article
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function item_publicado(Article $article, array $extra = [])
    {
        return array_merge([
            'id'                 => 'MLA222',
            'title'              => $article->name,
            'family_name'        => null,
            'category_id'        => 'MLA5232',
            'price'              => 15000,
            'available_quantity' => 10,
            'sold_quantity'      => 0,
            'listing_type_id'    => 'gold_special',
            'condition'          => 'new',
            'status'             => 'active',
            'permalink'          => 'https://articulo.mercadolibre.com.ar/MLA-222',
            'tags'               => [],
        ], $extra);
    }

    /**
     * Cuerpo JSON (array) de un pedido grabado por el fake.
     *
     * @param \Illuminate\Http\Client\Request $request
     * @return array<string, mixed>
     */
    protected function cuerpo($request)
    {
        return $request->data();
    }

    /**
     * Atributos del payload indexados por id.
     *
     * @param array<string, mixed> $payload
     * @return array<string, array<string, string>>
     */
    protected function atributos_por_id(array $payload)
    {
        $por_id = [];
        foreach (isset($payload['attributes']) ? $payload['attributes'] : [] as $atributo) {
            $por_id[$atributo['id']] = $atributo;
        }

        return $por_id;
    }

    /**
     * Índice (orden) del primer pedido grabado que cumple método + patrón.
     *
     * @param string $metodo
     * @param string $path_exacto
     * @return int|null
     */
    protected function orden_del_pedido($metodo, $path_exacto)
    {
        foreach (Http::recorded() as $i => $par) {
            $path = ltrim((string) parse_url($par[0]->url(), PHP_URL_PATH), '/');
            if (strtoupper($par[0]->method()) === $metodo && $path === $path_exacto) {
                return $i;
            }
        }

        return null;
    }

    public function test_alta_clasica_valida_antes_publica_con_title_y_atributos_automaticos()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $marca = Brand::create(['name' => 'Stanley', 'user_id' => $this->owner->id]);
        $article = $this->articulo_ml($this->categoria, ['brand_id' => $marca->id, 'meli_descripcion' => 'Martillo de acero forjado.']);

        ArticleMeliAttribute::create([
            'article_id'        => $article->id,
            'meli_attribute_id' => $this->atributo($this->categoria, 'MODEL')->id,
            'value_name'        => 'M-100',
        ]);

        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('exitosa', $sync->status, (string) $sync->error_message);

        $validate = $this->orden_del_pedido('POST', 'items/validate');
        $alta = $this->orden_del_pedido('POST', 'items');
        $this->assertNotNull($validate, 'No se validó con ML antes de publicar.');
        $this->assertNotNull($alta);
        $this->assertLessThan($alta, $validate, 'El validate tiene que ir ANTES del POST items.');

        $payload = $this->cuerpo($this->pedidos_ml('POST', 'items')[0]);

        $this->assertSame($article->name, $payload['title']);
        $this->assertArrayNotHasKey('family_name', $payload);
        $this->assertEquals(15000, $payload['price'], 'Sin lista para ML tiene que usar el precio final.');
        $this->assertSame(10, $payload['available_quantity']);
        $this->assertSame('gold_special', $payload['listing_type_id']);
        $this->assertSame([['source' => 'https://imagenes.comerciocity.test/martillo-'.$article->id.'.jpg']], $payload['pictures']);

        $atributos = $this->atributos_por_id($payload);
        $this->assertSame('Stanley', $atributos['BRAND']['value_name']);
        $this->assertSame('7790001234567', $atributos['GTIN']['value_name']);
        $this->assertSame('SKU-PRUEBA-1', $atributos['SELLER_SKU']['value_name']);
        $this->assertSame('M-100', $atributos['MODEL']['value_name']);

        $article = $article->fresh();
        $this->assertSame('MLA111', $article->me_li_id);
        $this->assertSame('https://articulo.mercadolibre.com.ar/MLA-111-martillo', $article->meli_permalink);
        $this->assertSame('active', $article->meli_status);

        $descripciones = $this->pedidos_ml('POST', 'items/MLA111/description');
        $this->assertCount(1, $descripciones);
        $this->assertSame('Martillo de acero forjado.', $this->cuerpo($descripciones[0])['plain_text']);
    }

    public function test_alta_user_products_manda_family_name_y_no_title()
    {
        $this->conector_ml([], [
            'nickname' => 'VENDEDOR_PRUEBA', 'user_product_seller' => true, 'multiorigen' => false, 'site_id' => 'MLA',
            'conectado_en' => Carbon::now()->subDays(3)->toIso8601String(),
        ]);
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertSame('exitosa', $sync->fresh()->status, (string) $sync->fresh()->error_message);

        foreach (['items/validate', 'items'] as $path) {
            $payload = $this->cuerpo($this->pedidos_ml('POST', $path)[0]);
            $this->assertSame($article->name, $payload['family_name'], $path);
            $this->assertArrayNotHasKey('title', $payload, $path.': en User Products ML rechaza el title.');
        }
    }

    public function test_el_titulo_se_recorta_al_largo_de_la_categoria()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta([
            'GET categories/MLA5232' => [200, $this->categoria_ml_hoja('MLA5232', 20)],
        ]));

        $article = $this->articulo_ml($this->categoria, ['name' => 'Martillo de carpintero con mango de fibra de vidrio']);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $payload = $this->cuerpo($this->pedidos_ml('POST', 'items')[0]);
        $this->assertLessThanOrEqual(20, mb_strlen($payload['title']));
        $this->assertSame('Martillo de carpinte', $payload['title']);
    }

    public function test_si_ml_no_valida_no_se_publica_y_queda_el_motivo_legible()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta([
            'POST items/validate' => [400, [
                'message' => 'Validation error',
                'error'   => 'validation_error',
                'status'  => 400,
                'cause'   => [
                    ['code' => 'item.attribute.missing_required', 'message' => 'The attributes [MODEL] are required for category MLA5232', 'type' => 'error'],
                    ['code' => 'item.title.length', 'message' => 'Title is too long', 'type' => 'warning'],
                ],
            ]],
        ]));

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertCount(0, $this->pedidos_ml('POST', 'items'), 'Con el validate fallido no se puede publicar.');

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertStringContainsString('no se publicó nada', $sync->error_message);
        $this->assertStringContainsString('son obligatorios para la categoría', $sync->error_message);
        $this->assertStringContainsString('item.attribute.missing_required', $sync->error_message);
        $this->assertStringNotContainsString('Title is too long', $sync->error_message, 'Con errores, las advertencias no se mezclan.');
        $this->assertNull($article->fresh()->me_li_id);

        Notification::assertSentTo($this->owner, GlobalNotification::class);
    }

    public function test_precio_debajo_del_minimo_da_error_legible_y_no_llama_a_ml()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria, ['final_price' => 850]);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertSame('El precio para Mercado Libre ($850) está debajo del mínimo de $1.000 que exige Mercado Libre.', $sync->error_message);
        $this->assertCount(0, $this->pedidos_ml('POST', 'items/validate'));
        $this->assertCount(0, $this->pedidos_ml('POST', 'items'));
    }

    public function test_si_hay_lista_para_ml_se_usa_su_precio()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $lista = PriceType::create(['name' => 'Lista ML de prueba', 'user_id' => $this->owner->id, 'se_usa_en_ml' => 1, 'position' => 99]);
        $article = $this->articulo_ml($this->categoria);
        $article->price_types()->attach($lista->id, ['final_price' => 21000]);

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $payload = $this->cuerpo($this->pedidos_ml('POST', 'items')[0]);
        $this->assertEquals(21000, $payload['price']);
    }

    public function test_sin_fotos_publicables_da_error_legible()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria, [], false);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertSame('El artículo no tiene fotos que Mercado Libre pueda bajar. Cargale al menos una foto.', $sync->fresh()->error_message);
    }

    public function test_si_ml_pide_esperar_la_sincronizacion_vuelve_a_pendiente_sin_avisar()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222']);
        $this->fake_ml(['GET items/MLA222' => [429, ['message' => 'too many requests']]]);

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('pendiente', $sync->status);
        $this->assertNotNull($sync->attempted_at);
        $this->assertSame('Mercado Libre pidió esperar; se reintenta solo.', $sync->error_message);

        Notification::assertNothingSent();
    }

    public function test_stock_cero_en_publicacion_nueva_manda_cantidad_cero_sin_pausar()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 0]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article)],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'paused']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertSame('exitosa', $sync->fresh()->status, (string) $sync->fresh()->error_message);

        $payload = $this->cuerpo($this->pedidos_ml('PUT', 'items/MLA222')[0]);
        $this->assertSame(0, $payload['available_quantity']);
        $this->assertArrayNotHasKey('status', $payload, 'En una publicación nueva no gratuita ML pausa sola por falta de stock.');
    }

    public function test_stock_cero_en_publicacion_gratuita_pausa_y_al_volver_el_stock_la_reactiva()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 0]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['listing_type_id' => 'free', 'available_quantity' => 1])],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'paused']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $payload = $this->cuerpo($this->pedidos_ml('PUT', 'items/MLA222')[0]);
        $this->assertSame('paused', $payload['status']);
        $this->assertArrayNotHasKey('available_quantity', $payload);

        $estado = json_decode($article->fresh()->meli_sync_state, true);
        $this->assertTrue($estado['pausado_por_sistema']);

        // Vuelve el stock: la publicación está pausada (por el sistema) y se reactiva.
        $article->stock = 5;
        $article->save();

        $this->rutas_ml['GET items/MLA222'] = [200, $this->item_publicado($article->fresh(), ['listing_type_id' => 'free', 'available_quantity' => 1, 'status' => 'paused'])];
        $this->rutas_ml['PUT items/MLA222'] = [200, ['id' => 'MLA222', 'status' => 'active']];

        $sync2 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync2);

        $puts = $this->pedidos_ml('PUT', 'items/MLA222');
        $payload2 = $this->cuerpo($puts[count($puts) - 1]);
        $this->assertSame('active', $payload2['status']);
        $this->assertArrayNotHasKey('available_quantity', $payload2, 'La gratuita ya tiene 1 unidad: no hay que mandarla de nuevo.');

        $estado = json_decode($article->fresh()->meli_sync_state, true);
        $this->assertFalse($estado['pausado_por_sistema']);
        $this->assertSame('active', $article->fresh()->meli_status);
    }

    public function test_una_pausa_del_vendedor_no_se_reactiva_sola()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 5]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['listing_type_id' => 'free', 'available_quantity' => 1, 'status' => 'paused'])],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'paused']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        foreach ($this->pedidos_ml('PUT', 'items/MLA222') as $put) {
            $this->assertArrayNotHasKey('status', $this->cuerpo($put), 'Una pausa que no hizo el sistema no se toca.');
        }
    }

    public function test_un_articulo_borrado_deja_error_sin_avisar_ni_llamar_a_ml()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);
        $article->delete();

        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertSame('El artículo ya no existe en el sistema.', $sync->error_message);
        $this->assertCount(0, Http::recorded());
        Notification::assertNothingSent();
    }

    public function test_actualizar_sin_cambios_no_manda_put_ni_fotos_ni_descripcion()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria, ['meli_descripcion' => 'Descripción para ML.']);
        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);
        $this->assertSame('exitosa', $sync->fresh()->status, (string) $sync->fresh()->error_message);

        $article = $article->fresh();
        $this->rutas_ml['GET items/MLA111'] = [200, $this->item_publicado($article, ['id' => 'MLA111'])];
        $this->rutas_ml['PUT items/MLA111'] = [200, ['id' => 'MLA111']];

        $descripciones_antes = count($this->pedidos_ml('POST', 'items/*/description')) + count($this->pedidos_ml('PUT', 'items/*/description'));

        $sync2 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync2);

        $this->assertSame('exitosa', $sync2->fresh()->status, (string) $sync2->fresh()->error_message);
        $this->assertCount(1, $this->pedidos_ml('GET', 'items/MLA111'));
        $this->assertCount(0, $this->pedidos_ml('PUT', 'items/MLA111'), 'Sin cambios no hay nada que mandar.');

        $descripciones_despues = count($this->pedidos_ml('POST', 'items/*/description')) + count($this->pedidos_ml('PUT', 'items/*/description'));
        $this->assertSame($descripciones_antes, $descripciones_despues);
    }

    public function test_actualizar_solo_el_precio_manda_solo_el_precio()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $article = $article->fresh();
        $this->rutas_ml['GET items/MLA111'] = [200, $this->item_publicado($article, ['id' => 'MLA111', 'price' => 12000])];
        $this->rutas_ml['PUT items/MLA111'] = [200, ['id' => 'MLA111', 'status' => 'active']];

        $sync2 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync2);

        $payload = $this->cuerpo($this->pedidos_ml('PUT', 'items/MLA111')[0]);
        $this->assertSame(['price'], array_keys($payload));
        $this->assertEquals(15000, $payload['price']);
    }

    /**
     * Arreglo 3 (revisión, 27/10/2026): un precio que no valida no puede frenar el stock (riesgo de
     * sobreventa). Precio 900 + stock 0 → se manda la cantidad 0, no el precio, y la sync queda
     * con el error del precio diciendo que el stock sí se actualizó.
     */
    public function test_con_el_precio_invalido_el_stock_viaja_igual_y_la_sync_avisa_el_precio()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 0, 'final_price' => 900]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['price' => 15000])],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'paused']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $puts = $this->pedidos_ml('PUT', 'items/MLA222');
        $this->assertCount(1, $puts, 'El stock tiene que viajar aunque el precio no valide.');
        $payload = $this->cuerpo($puts[0]);
        $this->assertSame(0, $payload['available_quantity']);
        $this->assertArrayNotHasKey('price', $payload);

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertStringContainsString('está debajo del mínimo de $1.000', $sync->error_message);
        $this->assertStringContainsString('stock', $sync->error_message);
    }

    /**
     * Arreglo 6 de la revisión de merge: con el precio debajo del mínimo, cada cambio de stock
     * dispara una sync que termina con el mismo error. Se notifica UNA vez por ese motivo en ese
     * artículo (la sync igual queda con el aviso); si el motivo cambia, o se arregla y vuelve, se
     * notifica de nuevo.
     */
    public function test_el_mismo_motivo_de_error_no_se_notifica_en_cada_sync()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 5, 'final_price' => 900]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['price' => 15000, 'available_quantity' => 10])],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'active']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);
        $this->assertSame('error', $sync->fresh()->status);
        Notification::assertSentToTimes($this->owner, GlobalNotification::class, 1);

        // Cambia el stock: misma sync, mismo motivo. Queda con el aviso, sin otra notificación.
        $article->stock = 4;
        $article->save();
        $this->rutas_ml['GET items/MLA222'] = [200, $this->item_publicado($article->fresh(), ['price' => 15000, 'available_quantity' => 5])];

        $sync2 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync2);
        $this->assertSame('error', $sync2->fresh()->status);
        $this->assertStringContainsString('debajo del mínimo', $sync2->fresh()->error_message);
        Notification::assertSentToTimes($this->owner, GlobalNotification::class, 1);

        // Otro motivo (otro precio): sí se notifica.
        $article->final_price = 800;
        $article->save();
        $this->rutas_ml['GET items/MLA222'] = [200, $this->item_publicado($article->fresh(), ['price' => 15000, 'available_quantity' => 4])];

        $sync3 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync3);
        Notification::assertSentToTimes($this->owner, GlobalNotification::class, 2);
    }

    /**
     * Arreglo 3: si el PUT combinado da un 400 que no es de límite, se reintenta con SOLO stock y
     * estado antes de rendirse.
     */
    public function test_si_el_put_combinado_falla_se_reintenta_solo_con_el_stock()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'stock' => 3]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['price' => 12000, 'available_quantity' => 10])],
            'PUT items/MLA222' => function ($request) {
                $cuerpo = $request->data();

                if (isset($cuerpo['pictures']) || isset($cuerpo['price'])) {
                    return Http::response([
                        'message' => 'Validation error',
                        'error'   => 'validation_error',
                        'status'  => 400,
                        'cause'   => [['code' => 'item.pictures.invalid', 'message' => 'Invalid picture source', 'type' => 'error']],
                    ], 400);
                }

                return Http::response(['id' => 'MLA222', 'status' => 'active'], 200);
            },
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $puts = $this->pedidos_ml('PUT', 'items/MLA222');
        $ultimo = $this->cuerpo($puts[count($puts) - 1]);
        $this->assertSame(['available_quantity' => 3], $ultimo, 'El último intento tiene que ser solo el stock.');

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertStringContainsString('item.pictures.invalid', $sync->error_message);
        $this->assertStringContainsString('stock', $sync->error_message);

        // Las fotos NO se dan por mandadas: el próximo intento las vuelve a mandar.
        $estado = json_decode((string) $article->fresh()->meli_sync_state, true);
        $this->assertTrue(!is_array($estado) || !isset($estado['fotos']));
    }

    public function test_una_publicacion_cerrada_da_error_legible()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222']);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['status' => 'closed'])],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertStringContainsString('finalizada en Mercado Libre', $sync->fresh()->error_message);
        $this->assertSame('closed', $article->fresh()->meli_status);
        $this->assertCount(0, $this->pedidos_ml('PUT', 'items/MLA222'));
    }

    /**
     * Arreglo 9: una publicación cerrada no se reintenta en cada cambio de stock o precio.
     */
    public function test_una_publicacion_cerrada_no_se_encola_en_cada_cambio()
    {
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'meli_status' => 'closed']);

        ProductService::add_article_to_sync($article);

        $this->assertFalse(SyncToMeliArticle::where('article_id', $article->id)->exists());
    }

    /**
     * Arreglo 9: un timeout en el POST del alta se trata como el 5xx (pudo haber creado el ítem):
     * no se reintenta solo y queda el aviso de revisar en ML.
     */
    public function test_un_timeout_en_el_alta_no_se_reintenta_y_pide_revisar_en_ml()
    {
        $this->conector_ml();

        // Un pedido que tira en el fake no queda en Http::recorded(): se cuentan los intentos acá.
        $intentos = 0;
        $this->fake_ml($this->rutas_de_alta([
            'POST items' => function ($request) use (&$intentos) {
                $intentos++;

                throw new \GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out', $request->toPsrRequest());
            },
        ]));

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $this->assertSame(1, $intentos, 'Un timeout en el alta no se reintenta solo.');

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertStringContainsString('fijate en tu cuenta de Mercado Libre si la publicación quedó creada', $sync->error_message);
    }

    /**
     * Arreglo 9: el comando toma cada fila con un update atómico; si otro proceso ya la tomó, la
     * saltea.
     */
    public function test_una_fila_se_toma_una_sola_vez()
    {
        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);

        $this->assertTrue(ProductService::tomar_para_procesar($sync));
        $this->assertSame('en_progreso', $sync->fresh()->status);

        $otra_copia = SyncToMeliArticle::find($sync->id);
        $otra_copia->status = 'pendiente';
        $this->assertFalse(ProductService::tomar_para_procesar($otra_copia), 'Otro proceso ya la tomó: no se procesa dos veces.');
    }

    public function test_add_article_to_sync_usa_la_extension_y_no_encola_la_papelera()
    {
        // Punto de partida propio: sin la extensión (la base del slot puede tenerla prendida).
        $this->extension_usa_mercado_libre(false);
        $article = $this->articulo_ml($this->categoria);

        ProductService::add_article_to_sync($article);
        $this->assertFalse(SyncToMeliArticle::where('article_id', $article->id)->exists(), 'Sin la extensión no se encola.');

        $this->extension_usa_mercado_libre(true);

        ProductService::add_article_to_sync($article);
        $this->assertSame(1, SyncToMeliArticle::where('article_id', $article->id)->where('status', 'pendiente')->count());

        // Dos veces no duplica.
        ProductService::add_article_to_sync($article);
        $this->assertSame(1, SyncToMeliArticle::where('article_id', $article->id)->count());

        $otro = $this->articulo_ml($this->categoria);
        $otro->delete();
        ProductService::add_article_to_sync(Article::withTrashed()->find($otro->id));
        $this->assertFalse(SyncToMeliArticle::where('article_id', $otro->id)->exists(), 'Un artículo de la papelera no se encola.');
    }

    /**
     * Arreglo 10 (revisión, 27/10/2026): encolar, el Kernel y el listado de pedidos usan el MISMO
     * criterio, la extensión. Con `USA_MERCADO_LIBRE=true` en el .env pero sin la extensión, el
     * Kernel no corre la cola: encolar dejaba filas que nadie procesaba.
     */
    public function test_el_env_usa_mercado_libre_sin_la_extension_no_encola()
    {
        $this->forzar_env_usa_mercado_libre('true');
        $this->extension_usa_mercado_libre(false);

        $article = $this->articulo_ml($this->categoria);
        ProductService::add_article_to_sync($article);

        $this->assertFalse(SyncToMeliArticle::where('article_id', $article->id)->exists());
    }

    /**
     * Arreglo 2: un artículo publicado que se desmarca de "Disponible en Mercado Libre" se encola
     * igual (para pausarlo); uno que nunca se publicó, no.
     */
    public function test_desmarcado_se_encola_solo_si_tiene_publicacion()
    {
        $sin_publicar = $this->articulo_ml($this->categoria, ['mercado_libre' => 0]);
        ProductService::add_article_to_sync($sin_publicar);
        $this->assertFalse(SyncToMeliArticle::where('article_id', $sin_publicar->id)->exists());

        $publicado = $this->articulo_ml($this->categoria, ['mercado_libre' => 0, 'me_li_id' => 'MLA222', 'meli_status' => 'active']);
        ProductService::add_article_to_sync($publicado);
        $this->assertSame(1, SyncToMeliArticle::where('article_id', $publicado->id)->where('status', 'pendiente')->count());

        // Ya pausado por el sistema: no hay nada más que hacer, no se encola de nuevo.
        $pausado = $this->articulo_ml($this->categoria, [
            'mercado_libre'   => 0,
            'me_li_id'        => 'MLA333',
            'meli_status'     => 'paused',
            'meli_sync_state' => json_encode(['pausado_por_sistema' => true]),
        ]);
        ProductService::add_article_to_sync($pausado);
        $this->assertFalse(SyncToMeliArticle::where('article_id', $pausado->id)->exists());
    }

    public function test_un_articulo_desmarcado_y_publicado_se_pausa_y_al_marcarlo_se_reactiva()
    {
        $this->conector_ml();
        $article = $this->articulo_ml($this->categoria, ['me_li_id' => 'MLA222', 'mercado_libre' => 0, 'stock' => 5]);
        $this->fake_ml($this->rutas_de_alta([
            'GET items/MLA222' => [200, $this->item_publicado($article, ['available_quantity' => 5])],
            'PUT items/MLA222' => [200, ['id' => 'MLA222', 'status' => 'paused']],
        ]));

        $sync = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('exitosa', $sync->status, (string) $sync->error_message);
        $this->assertStringContainsString('la publicación se pausó', $sync->error_message);

        $puts = $this->pedidos_ml('PUT', 'items/MLA222');
        $this->assertCount(1, $puts);
        $this->assertSame(['status' => 'paused'], $this->cuerpo($puts[0]), 'Desmarcado solo se pausa: ni precio ni stock.');

        $article = $article->fresh();
        $this->assertSame('paused', $article->meli_status);
        $this->assertTrue(json_decode($article->meli_sync_state, true)['pausado_por_sistema']);

        // Se vuelve a marcar: la sincronización normal reactiva la que pausó el sistema.
        $article->mercado_libre = 1;
        $article->save();

        $this->rutas_ml['GET items/MLA222'] = [200, $this->item_publicado($article->fresh(), ['status' => 'paused', 'available_quantity' => 5])];
        $this->rutas_ml['PUT items/MLA222'] = [200, ['id' => 'MLA222', 'status' => 'active']];

        $sync2 = $this->sync_pendiente($article);
        (new ProductService($this->owner->id))->sync_article($sync2);

        $puts = $this->pedidos_ml('PUT', 'items/MLA222');
        $this->assertSame('active', $this->cuerpo($puts[count($puts) - 1])['status']);
        $this->assertSame('active', $article->fresh()->meli_status);
    }

    public function test_sincronizar_un_articulo_desmarcado_sin_publicacion_da_error_legible_sin_llamar_a_ml()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $article = $this->articulo_ml($this->categoria, ['mercado_libre' => 0]);
        $sync = $this->sync_pendiente($article);

        (new ProductService($this->owner->id))->sync_article($sync);

        $sync = $sync->fresh();
        $this->assertSame('error', $sync->status);
        $this->assertStringContainsString('no está marcado como «Disponible en Mercado Libre»', $sync->error_message);
        $this->assertCount(0, Http::recorded());
    }

    public function test_el_comando_toma_pendientes_respeta_la_espera_y_libera_los_colgados()
    {
        $this->conector_ml();
        $this->fake_ml($this->rutas_de_alta());

        $listo = $this->articulo_ml($this->categoria);
        $esperando = $this->articulo_ml($this->categoria);
        $colgado = $this->articulo_ml($this->categoria);

        $sync_listo = $this->sync_pendiente($listo);

        $sync_esperando = $this->sync_pendiente($esperando);
        $sync_esperando->attempted_at = Carbon::now()->subSeconds(30);
        $sync_esperando->save();

        $sync_colgado = SyncToMeliArticle::create([
            'article_id'   => $colgado->id,
            'user_id'      => $this->owner->id,
            'status'       => 'en_progreso',
            'attempted_at' => Carbon::now()->subMinutes(40),
        ]);

        Artisan::call('sync_to_meli_articles');

        $this->assertSame('exitosa', $sync_listo->fresh()->status, (string) $sync_listo->fresh()->error_message);
        $this->assertSame('pendiente', $sync_esperando->fresh()->status, 'Uno con intento de hace menos de 2 minutos espera.');
        $this->assertSame('exitosa', $sync_colgado->fresh()->status, 'El en_progreso colgado volvió a la cola y se procesó.');
    }

    public function test_el_comando_sin_conexion_no_hace_nada_ni_avisa()
    {
        $this->fake_ml([]);

        $article = $this->articulo_ml($this->categoria);
        $sync = $this->sync_pendiente($article);

        Artisan::call('sync_to_meli_articles');

        $this->assertSame('pendiente', $sync->fresh()->status);
        Notification::assertNothingSent();
        $this->assertCount(0, Http::recorded());
    }
}
