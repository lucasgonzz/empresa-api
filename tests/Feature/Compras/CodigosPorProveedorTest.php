<?php

namespace Tests\Feature\Compras;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ProcessRow;
use App\Imports\ProviderOrderArticleImport;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Services\EscaneoFacturaCompraService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class CodigosPorProveedorTest extends ComprasTestCase
{
    protected $proveedor_a;
    protected $proveedor_b;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        config(['services.anthropic.api_key' => null]);
        ArticleIndexCache::reset_runtime_de_tests();
        ArticleIndexCache::set_contexto_de_importacion(null, null);
        $this->proveedor_a = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $this->proveedor_b = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
    }

    protected function tearDown(): void
    {
        ArticleIndexCache::reset_runtime_de_tests();
        ArticleIndexCache::set_contexto_de_importacion(null, null);
        parent::tearDown();
    }

    protected function producto($nombre = 'Producto de varios proveedores', $codigo = 'ULTIMO-B')
    {
        return Article::create([
            'name' => $nombre, 'status' => 'active', 'user_id' => auth()->id(),
            'provider_id' => $this->proveedor_b->id, 'provider_code' => $codigo,
            'cost' => 100, 'price' => 200, 'final_price' => 200,
            'iva_id' => $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->iva_id,
        ]);
    }

    protected function compra()
    {
        return ProviderOrder::create([
            'num' => mt_rand(9000000, 9999999), 'provider_id' => $this->proveedor_a->id,
            'provider_order_status_id' => 1, 'modo_facturacion' => 'manual',
            'update_stock' => 0, 'update_prices' => 0, 'generate_current_acount' => 0,
            'precios_incluyen_iva' => false, 'moneda_id' => 1, 'user_id' => auth()->id(),
        ]);
    }

    protected function escaneo($compra)
    {
        return ProviderOrderScan::create([
            'uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'auth_user_id' => auth()->id(),
            'provider_order_id' => $compra->id, 'estado' => 'listo', 'progreso' => 100,
            'resultado' => ['articulos' => [], 'avisos' => []],
        ]);
    }

    protected function coincidencia($scan, $codigo, $nombre = null, $bar_code = null)
    {
        $servicio = new class extends EscaneoFacturaCompraService {
            public function buscar($filas, $scan) { return $this->matchear_articulos($filas, $scan); }
        };
        return $servicio->buscar([['bar_code' => $bar_code, 'codigo_proveedor' => $codigo, 'nombre' => $nombre]], $scan)[0]['match'];
    }

    public function test_escaneo_encuentra_codigo_del_proveedor_anterior_y_no_codigo_ajeno()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'ANTERIOR-A', 'cost' => 90]);
        $scan = $this->escaneo($this->compra());
        $this->assertSame($article->id, $this->coincidencia($scan, 'ANTERIOR-A')['article_id']);
        $this->assertNull($this->coincidencia($scan, 'ULTIMO-B')['article_id']);
    }

    public function test_escaneo_no_elige_entre_codigos_repetidos_ni_nombres_parecidos()
    {
        foreach (['Producto duplicado uno', 'Producto duplicado dos'] as $nombre) {
            $this->producto($nombre)->providers()->attach($this->proveedor_a->id, ['provider_code' => 'REPETIDO']);
        }
        $scan = $this->escaneo($this->compra());
        $this->assertNull($this->coincidencia($scan, 'REPETIDO', 'Producto duplicado uno')['article_id']);
        $this->assertNull($this->coincidencia($scan, 'NUEVO', 'Producto duplicado')['article_id']);
    }

    public function test_nombre_exacto_unico_admite_otro_proveedor_pero_no_equivale_acentos()
    {
        $article = $this->producto('Piña natural');
        $scan = $this->escaneo($this->compra());
        $this->assertSame($article->id, $this->coincidencia($scan, 'NUEVO', 'PIÑA NATURAL')['article_id']);
        $this->assertNull($this->coincidencia($scan, 'NUEVO', 'Pina natural')['article_id']);
        $this->producto('Piña natural', 'OTRO');
        $this->assertNull($this->coincidencia($scan, 'NUEVO', 'Piña natural')['article_id']);
    }

    public function test_confirmar_vinculo_aprende_codigo_aun_sin_actualizar_precios_y_es_idempotente()
    {
        $extencion = ExtencionEmpresa::firstOrCreate(['slug' => 'agentes_ia'], ['name' => 'Agentes de IA']);
        auth()->user()->extencions()->syncWithoutDetaching([$extencion->id]);
        auth()->user()->load('extencions');
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_b->id, ['provider_code' => 'ULTIMO-B', 'cost' => 100]);
        $scan = $this->escaneo($this->compra());
        $payload = ['articulos' => [[
            'incluir' => true, 'article_id' => $article->id, 'crear_en_catalogo' => false,
            'codigo_proveedor' => 'NUEVO-A', 'nombre' => $article->name,
            'cantidad' => 2, 'costo_unitario' => 75, 'iva_id' => $article->iva_id,
        ]], 'factura' => ['guardar' => false]];
        $this->postJson('/api/provider-order-scan/' . $scan->uuid . '/confirmar', $payload)->assertOk();
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => 'NUEVO-A', 'cost' => 75]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_b->id, 'provider_code' => 'ULTIMO-B', 'cost' => 100]);
        $this->assertEquals(100, $article->fresh()->cost);
        $this->assertSame('ULTIMO-B', $article->fresh()->provider_code);
        $this->postJson('/api/provider-order-scan/' . $scan->uuid . '/confirmar', $payload)->assertStatus(409);
        $this->assertSame($article->id, $this->coincidencia($this->escaneo($scan->provider_order), 'NUEVO-A')['article_id']);
    }

    public function test_compra_guarda_codigo_sin_costo_y_conserva_costo_anterior()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'VIEJO', 'cost' => 45]);
        $compra = $this->compra();
        $helper = new \App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper($compra, [], false);
        $helper->catalogar_costo_proveedor($article, ['provider_code' => '0', 'pivot' => ['cost' => null]]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => '0', 'cost' => 45]);
        $helper->catalogar_costo_proveedor($article, ['provider_code' => null, 'pivot' => ['cost' => 0]]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => '0', 'cost' => 0]);
    }

    public function test_cambiar_proveedor_principal_no_atribuye_codigo_anterior_al_nuevo_proveedor()
    {
        foreach ([null, 'EXPLICITO-A'] as $codigo) {
            $article = $this->producto('Cambio de proveedor ' . ($codigo ?? 'sin código'));
            $compra = $this->compra();
            $compra->update_prices = 1;
            $helper = new \App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper($compra, [], false);
            $fila = ['provider_code' => $codigo, 'pivot' => ['cost' => 60]];
            $helper->catalogar_costo_proveedor($article, $fila);
            $helper->update_article_provider($article, $fila);
            $this->assertSame($codigo, $article->fresh()->provider_code);
            $this->assertEquals($this->proveedor_a->id, $article->fresh()->provider_id);
        }
    }

    public function test_busqueda_manual_contextual_y_reapertura_de_compra()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'MANUAL-A']);
        $payload = ['query_value' => 'MANUAL-A', 'props' => ['provider_code'], 'contexto' => 'provider_order', 'provider_id' => $this->proveedor_a->id];
        $this->postJson('/api/global-search/article', $payload)->assertOk()->assertJsonPath('models.data.0.id', $article->id)->assertJsonPath('models.data.0.provider_code', 'MANUAL-A');
        $payload['provider_id'] = $this->proveedor_b->id;
        $this->postJson('/api/global-search/article', $payload)->assertOk()->assertJsonPath('models.total', 0);
        $compra = $this->compra();
        $compra->articles()->attach($article->id, ['amount' => 1]);
        $this->assertSame('MANUAL-A', $compra->load('articles')->toArray()['articles'][0]['provider_code']);
        $this->assertSame('ULTIMO-B', $article->fresh()->provider_code);
    }

    public function test_busqueda_con_nombre_y_codigo_respeta_modos_conector_y_filtros()
    {
        $article = $this->producto('Martillo contextual');
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'A-42']);
        $payload = ['query_value' => 'Martillo A-42', 'props' => ['name', 'provider_code'], 'contexto' => 'provider_order', 'provider_id' => $this->proveedor_a->id];
        $this->postJson('/api/global-search/article', $payload)->assertOk()->assertJsonPath('models.data.0.id', $article->id);
        $payload['props'] = [['key' => 'name', 'keyword_mode' => 'todas'], ['key' => 'provider_code', 'keyword_mode' => 'alguna']];
        $payload['conector'] = 'and';
        $this->postJson('/api/global-search/article', $payload)->assertOk()->assertJsonPath('models.total', 0);
        $payload['query_value'] = 'A-42';
        $payload['props'] = ['provider_code'];
        $payload['extra_filters'] = [['key' => 'id', 'operator' => '=', 'value' => 2147483647]];
        $this->postJson('/api/global-search/article', $payload)->assertOk()->assertJsonPath('models.total', 0);
    }

    public function test_busqueda_sin_proveedor_con_variantes_conserva_coincidencia_por_escalar_legado()
    {
        $extencion = ExtencionEmpresa::where('slug', 'article_variants')->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'article_variants', 'name' => 'Variantes']);
        }
        auth()->user()->extencions()->syncWithoutDetaching([$extencion->id]);
        auth()->user()->load('extencions');
        $article = $this->producto('Martillo legado', 'LEGADO-A7');
        $this->postJson('/api/global-search/article', [
            'query_value' => 'LEGADO-A7', 'props' => ['name', 'provider_code'],
            'contexto' => 'provider_order', 'provider_id' => null,
        ])->assertOk()->assertJsonPath('models.data.0.id', $article->id)->assertJsonPath('models.data.0.provider_code', null);
    }

    public function test_formulario_anterior_no_copia_codigo_de_otro_proveedor_al_crear_o_editar_compra()
    {
        foreach ([null, 'CODIGO-A'] as $codigo_a) {
            $article = $this->producto('Compatibilidad formulario ' . ($codigo_a ?? 'sin relación'));
            if (!is_null($codigo_a)) {
                $article->providers()->attach($this->proveedor_a->id, ['provider_code' => $codigo_a]);
            }
            $payload = $this->payload_compra([
                'provider_id' => $this->proveedor_a->id, 'modo_facturacion' => 'manual',
                'update_prices' => 0, 'update_stock' => 0, 'generate_current_acount' => 0,
                'articles' => [[
                    'id' => $article->id, 'bar_code' => null, 'provider_code' => 'ULTIMO-B',
                    'pivot' => ['amount' => 1, 'cost' => 80, 'iva_id' => $article->iva_id, 'update_provider' => 0],
                ]],
            ]);
            $respuesta = $this->postJson('/api/provider-order', $payload)->assertStatus(201);
            $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => $codigo_a]);
            $this->putJson('/api/provider-order/' . $respuesta->json('model.id'), $payload)->assertOk();
            $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => $codigo_a]);
        }
    }

    public function test_compra_rescata_codigo_del_proveedor_anterior_cuyo_pivot_estaba_vacio()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_b->id, ['provider_code' => null, 'cost' => 45]);
        $compra = $this->compra();
        $compra->update_prices = 1;
        $helper = new \App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper($compra, [], false);
        $helper->update_article(['id' => $article->id, 'provider_code' => 'NUEVO-A', 'bar_code' => null, 'pivot' => ['iva_id' => $article->iva_id, 'cost' => 60, 'price' => null, 'cost_in_dollars' => 0]]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_b->id, 'provider_code' => 'ULTIMO-B', 'cost' => 45]);
        $this->assertSame('NUEVO-A', $article->fresh()->provider_code);
    }

    public function test_busqueda_y_escaneo_no_ven_articulos_borrados_o_de_otro_comercio()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'BORRADO-A']);
        $article->delete();
        $this->assertNull($this->coincidencia($this->escaneo($this->compra()), 'BORRADO-A')['article_id']);
        $this->postJson('/api/global-search/article', ['query_value' => 'BORRADO-A', 'props' => ['provider_code'], 'contexto' => 'provider_order', 'provider_id' => $this->proveedor_a->id])->assertJsonPath('models.total', 0);
        $this->postJson('/api/global-search/article', ['contexto' => 'provider_order', 'provider_id' => 2147483647])->assertStatus(422);
        $ajeno = $this->producto('Artículo ajeno');
        $ajeno->update(['user_id' => \App\Models\User::where('id', '<>', auth()->id())->value('id')]);
        $ajeno->providers()->attach($this->proveedor_a->id, ['provider_code' => 'AJENO-A']);
        $this->assertNull($this->coincidencia($this->escaneo($this->compra()), 'AJENO-A')['article_id']);
        $this->postJson('/api/global-search/article', ['query_value' => 'AJENO-A', 'props' => ['provider_code'], 'contexto' => 'provider_order', 'provider_id' => $this->proveedor_a->id])->assertJsonPath('models.total', 0);
    }

    public function test_excel_compra_resuelve_pivot_sin_duplicar_y_aprende_codigo_por_nombre()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'EXCEL-A']);
        $compra = $this->compra();
        $import = new ProviderOrderArticleImport(['codigo_de_proveedor' => 0, 'nombre' => 1, 'cantidad' => 2, 'costo' => 3], 1, 1, auth()->user(), $compra);
        $import->collection(collect([collect(['EXCEL-A', 'Nombre distinto del proveedor', 2, 80])]));
        $this->assertSame(0, $import->creados);
        $this->assertDatabaseHas('article_provider_order', ['article_id' => $article->id, 'provider_order_id' => $compra->id, 'amount' => 2]);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => 'EXCEL-A', 'cost' => 80]);
        $import = new ProviderOrderArticleImport(['codigo_de_proveedor' => 0, 'nombre' => 1, 'cantidad' => 2, 'costo' => 3], 1, 1, auth()->user(), $compra);
        $import->collection(collect([collect(['NUEVO-EXCEL-A', $article->name, 3, 82])]));
        $this->assertSame(0, $import->creados);
        $this->assertDatabaseHas('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => 'NUEVO-EXCEL-A']);
    }

    public function test_excel_codigos_con_ceros_no_se_fusionan_y_ambiguos_no_se_eligen()
    {
        $uno = $this->producto('Código uno');
        $otro = $this->producto('Código con cero');
        $uno->providers()->attach($this->proveedor_a->id, ['provider_code' => '1']);
        $otro->providers()->attach($this->proveedor_a->id, ['provider_code' => '001']);
        $compra = $this->compra();
        $import = new ProviderOrderArticleImport(['codigo_de_proveedor' => 0, 'cantidad' => 1], 1, 2, auth()->user(), $compra);
        $import->collection(collect([collect(['1', 2]), collect(['001', 3])]));
        $this->assertDatabaseHas('article_provider_order', ['article_id' => $uno->id, 'provider_order_id' => $compra->id, 'amount' => 2]);
        $this->assertDatabaseHas('article_provider_order', ['article_id' => $otro->id, 'provider_order_id' => $compra->id, 'amount' => 3]);
        $this->producto('Duplicado de uno')->providers()->attach($this->proveedor_a->id, ['provider_code' => '1']);
        $this->expectException(\InvalidArgumentException::class);
        (new ProviderOrderArticleImport(['codigo_de_proveedor' => 0], 1, 1, auth()->user(), $this->compra()))->collection(collect([collect(['1'])]));
    }

    public function test_excel_codigo_desconocido_no_aprende_sobre_nombre_que_solo_coincide_sin_acentos()
    {
        $article = $this->producto('Pina natural');
        $compra = $this->compra();
        $import = new ProviderOrderArticleImport(['codigo_de_proveedor' => 0, 'nombre' => 1, 'cantidad' => 2], 1, 1, auth()->user(), $compra);
        $import->collection(collect([collect(['CODIGO-PIÑA', 'Piña natural', 1])]));
        $this->assertSame(1, $import->creados);
        $this->assertDatabaseMissing('article_provider_order', ['article_id' => $article->id, 'provider_order_id' => $compra->id]);
        $this->assertDatabaseMissing('article_provider', ['article_id' => $article->id, 'provider_id' => $this->proveedor_a->id, 'provider_code' => 'CODIGO-PIÑA']);
    }

    public function test_excel_segunda_fila_reconoce_codigo_aprendido_sobre_articulo_existente_en_primera_fila()
    {
        $article = $this->producto();
        $article->update(['bar_code' => '779998876655']);
        $compra = $this->compra();
        $import = new ProviderOrderArticleImport(['codigo_de_barras' => 0, 'codigo_de_proveedor' => 1, 'nombre' => 2, 'cantidad' => 3], 1, 2, auth()->user(), $compra);
        $import->collection(collect([
            collect([$article->bar_code, 'CODIGO-APRENDIDO-A', 'Nombre primera fila', 1]),
            collect(['', 'CODIGO-APRENDIDO-A', 'Nombre distinto segunda fila', 3]),
        ]));
        $this->assertSame(0, $import->creados);
        $this->assertSame(1, $compra->articles()->count());
        $this->assertDatabaseHas('article_provider_order', ['article_id' => $article->id, 'provider_order_id' => $compra->id, 'amount' => 3]);
    }

    public function test_indice_catalogo_completo_y_acotado_incluyen_codigo_exclusivo_del_pivot()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => '0']);
        ArticleIndexCache::build(auth()->id(), $this->proveedor_a->id, false);
        $this->assertSame([$article->id], ArticleIndexCache::get(auth()->id())['provider_codes'][$this->proveedor_a->id]['0']);
        $ruta = tempnam(sys_get_temp_dir(), 'codigos_proveedor_');
        try {
            file_put_contents($ruta, serialize(['ids' => [], 'bar_codes' => [], 'skus' => [], 'provider_codes' => ['0' => true], 'names' => []]));
            ArticleIndexCache::set_contexto_de_importacion($ruta, 'codigos-proveedor-test');
            ArticleIndexCache::build(auth()->id(), $this->proveedor_a->id, false);
            $index = ArticleIndexCache::get(auth()->id());
            $this->assertSame([$article->id], $index['provider_codes'][$this->proveedor_a->id]['0']);
            $this->assertSame($article->id, $index['ids'][$article->id]);
        } finally {
            unlink($ruta);
        }
    }

    public function test_import_catalogo_preserva_codigo_sin_costo_y_actualiza_indice_del_siguiente_lote()
    {
        $article = $this->producto();
        $article->providers()->attach($this->proveedor_a->id, ['provider_code' => 'CAT-A', 'cost' => 55]);
        ArticleIndexCache::build(auth()->id(), $this->proveedor_a->id, false);
        $procesador = (new \ReflectionClass(ProcessRow::class))->newInstanceWithoutConstructor();
        $procesador->update_provider_relation($article->load('providers'), ['provider_code' => 'CAT-NUEVO'], $this->proveedor_a->id);
        $procesador->update_provider_relation($article, ['cost' => 65], $this->proveedor_a->id);
        $buffer = $procesador->get_provider_relations_buffer();
        $this->assertSame('CAT-NUEVO', $buffer[$article->id][$this->proveedor_a->id]['provider_code']);
        $this->assertSame(65, $buffer[$article->id][$this->proveedor_a->id]['cost']);
        ArticleIndexCache::persist(auth()->id());
        ArticleIndexCache::reset_runtime_de_tests();
        $this->assertSame([$article->id], ArticleIndexCache::get_index(auth()->id())['provider_codes'][$this->proveedor_a->id]['CAT-NUEVO']);
    }
}
