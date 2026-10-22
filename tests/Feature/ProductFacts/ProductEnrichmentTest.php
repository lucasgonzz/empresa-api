<?php

namespace Tests\Feature\ProductFacts;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Jobs\ProcessProductEnrichmentJob;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductEnrichment;
use App\Models\SerperUsageEvent;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\ImageSearch\SerperImageSearchProvider;
use App\Services\ProductFacts\Gtin;
use App\Services\ProductFacts\ProductEnrichmentService;
use App\Services\ProductFacts\ProductFactsResearchService;
use App\Services\ProductFacts\SerperUsageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;

class ProductEnrichmentTest extends EmpresaTestCase
{
    use OtorgaAgentesIa;

    const CODE = '7791234567898';
    protected $owner;
    protected $category;
    protected $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Dueño fichas', 'company_name' => 'Comercio fichas', 'email' => uniqid() . '@fichas.test', 'password' => 'sin-acceso']);
        // Misión agentes-ia-extension-unica (22/10/2026): la ficha por código de barras es de `agentes_ia`; el comercio de prueba la paga.
        $this->otorgar_agentes_ia($this->owner);
        $this->asPerson($this->owner);
        config(['app.USER_ID' => $this->owner->id, 'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.inbound_key' => 'CLAVE-ADMIN-FALSA', 'services.serper.api_key' => 'CLAVE-SERPER-FALSA',
            'services.anthropic.api_key' => 'CLAVE-IA-FALSA', 'services.anthropic.base_url' => 'https://api.anthropic.com']);
        Queue::fake();
        app()->bind(ProductFactsResearchService::class, ResearchWithTestDns::class);
    }

    protected function asPerson($person)
    {
        Auth::forgetGuards();
        $this->actingAs($person, 'web');
        session()->forget(['auth_user', 'owner']);
    }

    protected function tree()
    {
        $this->category = Category::create(['name' => 'Alimentos', 'user_id' => $this->owner->id]);
        $this->sub = SubCategory::create(['name' => 'Galletitas', 'category_id' => $this->category->id, 'user_id' => $this->owner->id]);
    }

    protected function task(array $extra = [])
    {
        return ProductEnrichment::create(array_merge(['uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'auth_user_id' => $this->owner->id, 'bar_code' => self::CODE, 'gtin' => Gtin::canonical(self::CODE), 'status' => 'queued'], $extra));
    }

    protected function runTask($task)
    {
        (new ProcessProductEnrichmentJob($task->id))->handle(app(ProductEnrichmentService::class));
        return $task->fresh();
    }

    protected function product()
    {
        return ['name' => 'Galletitas Prueba 500 g', 'brand' => 'Prueba', 'description' => 'Galletitas dulces.',
            'net_content' => '500 g', 'weight_kg' => null, 'width_cm' => null, 'height_cm' => null, 'length_cm' => null,
            'sources' => [['url' => 'https://fabricante.test/producto', 'title' => 'Fabricante']]];
    }

    protected function ai($data, $stop = 'end_turn')
    {
        return Http::response(['model' => 'claude-haiku-4-5-20251001', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 80, 'output_tokens' => 30],
            'content' => [['type' => 'text', 'text' => json_encode($data)]]]);
    }

    protected function coldHttp($extract = null, $page = null, $stop = 'end_turn')
    {
        $self = $this;
        Http::fake(function ($request) use ($self, $extract, $page, $stop) {
            $url = $request->url();
            if (strpos($url, 'admin.test/api/inbound/product-facts/') !== false) {
                return Http::response($request->method() === 'POST' ? ['status' => 'claimed', 'lease_token' => 'reserva-falsa'] : ['status' => 'found']);
            }
            if ($url === 'https://google.serper.dev/search') {
                return Http::response(['organic' => [['link' => 'https://fabricante.test/producto', 'title' => 'Fabricante']], 'credits' => 1]);
            }
            if ($url === 'https://fabricante.test/producto') {
                return Http::response($page ?: '<html><h1>Galletitas Prueba 500 g</h1><p>GTIN 7791234567898</p><p>Contenido neto: 500 g</p><p>Peso bruto para envío: 550 g</p><p>Ancho del embalaje: 10 cm</p></html>', 200, ['Content-Type' => 'text/html']);
            }
            if ($url === 'https://api.anthropic.com/v1/messages') {
                if (strpos($request['system'], 'Elegí') !== false) {
                    return $self->ai(['category_id' => $self->category->id, 'sub_category_id' => $self->sub->id]);
                }
                $data = $extract === null ? array_merge($self->product(), ['matched_gtin' => self::CODE,
                    'weight_kg' => ['value' => 500, 'unit' => 'g', 'evidence' => 'Contenido neto: 500 g', 'source_url' => 'https://fabricante.test/producto'],
                    'width_cm' => ['value' => 10, 'unit' => 'cm', 'evidence' => 'Ancho del embalaje: 10 cm', 'source_url' => 'https://fabricante.test/producto']]) : $extract;
                return $self->ai($data, $stop);
            }
            throw new \RuntimeException('HTTP inesperado en el test: ' . $url);
        });
    }

    public function test_validacion_gtin_preserva_ceros_y_excluye_balanza()
    {
        $this->assertSame('00012345678905', Gtin::canonical('012345678905'));
        $this->assertContains('012345678905', Gtin::equivalents('00012345678905'));
        $this->assertNull(Gtin::canonical('0000000000000'));
        $this->assertNull(Gtin::canonical('2001234567893'));
        $this->assertNull(Gtin::canonical('7791234567899'));
    }

    public function test_endpoint_encola_sin_buscar_y_duplicados_no_consumen()
    {
        Http::fake();
        $result = $this->postJson('/api/article-enrichments', ['bar_code' => self::CODE])->assertStatus(202)->assertJsonPath('status', 'queued');
        Queue::assertPushed(ProcessProductEnrichmentJob::class);
        Http::assertNothingSent();
        $this->getJson('/api/article-enrichments/' . $result->json('uuid'))->assertOk()->assertJsonPath('status', 'queued');
        $this->deleteJson('/api/article-enrichments/' . $result->json('uuid'))->assertOk()->assertJsonPath('status', 'cancelled');
        Article::create(['name' => 'Existente', 'bar_code' => '0' . self::CODE, 'user_id' => $this->owner->id]);
        $this->postJson('/api/article-enrichments', ['bar_code' => self::CODE])->assertStatus(409)->assertJsonPath('status', 'duplicate');
        $this->postJson('/api/article-enrichments', ['bar_code' => '123'])->assertStatus(422);
    }

    public function test_permiso_tenant_y_usuario_efectivo_son_obligatorios()
    {
        $task = $this->task();
        $other = User::create(['name' => 'Otro comercio', 'email' => uniqid() . '@fichas.test', 'password' => 'sin-acceso']);
        // El otro comercio también la paga: lo que se prueba es que NO vea la tarea del primero (404), no el gate de la extensión.
        $this->otorgar_agentes_ia($other);
        $this->asPerson($other);
        config(['app.USER_ID' => $other->id]);
        $this->getJson('/api/article-enrichments/' . $task->uuid)->assertNotFound();
        $this->deleteJson('/api/article-enrichments/' . $task->uuid)->assertNotFound();
        $employee = User::create(['name' => 'Empleado sin permiso', 'owner_id' => $this->owner->id, 'email' => uniqid() . '@fichas.test', 'password' => 'sin-acceso']);
        config(['app.USER_ID' => $this->owner->id]);
        $this->asPerson($employee);
        $this->postJson('/api/article-enrichments', ['bar_code' => self::CODE])->assertForbidden();
    }

    public function test_cache_compartida_devuelve_campos_sin_serper_ni_extraccion_aun_sin_cupo()
    {
        $this->tree();
        $this->owner->update(['plan_ia_tope_busquedas_web_diarias' => 1]);
        AiTokenUsageHelper::registrar(['user_id' => $this->owner->id, 'proceso' => 'busqueda_codigo_barras', 'usage' => ['input_tokens' => 1], 'modelo' => 'prueba']);
        $self = $this;
        Http::fake(function ($request) use ($self) {
            if (strpos($request->url(), '/claim') !== false) { return Http::response(['status' => 'found', 'product' => $self->product()]); }
            return $self->ai(['category_id' => $self->category->id, 'sub_category_id' => $self->sub->id]);
        });
        $task = $this->runTask($this->task());
        $this->assertSame('found', $task->status);
        $this->assertTrue($task->cache_hit);
        // MySQL normaliza el orden de las claves de un objeto JSON; se verifican todos los campos y valores.
        $this->assertEquals($this->product(), $task->product);
        $this->assertSame($this->sub->id, $task->classification['sub_category_id']);
        $this->assertSame(0, (int) $task->search_attempts);
        $this->assertSame(0, SerperUsageEvent::where('user_id', $this->owner->id)->count());
        Http::assertSentCount(2);
        Http::assertNotSent(function ($request) { return strpos($request->url(), 'serper') !== false || strpos((string) ($request['system'] ?? ''), 'Extraé') !== false; });
    }

    public function test_cold_path_real_extrae_clasifica_y_nunca_convierte_neto_en_envio()
    {
        $this->tree();
        $this->coldHttp();
        $task = $this->runTask($this->task());
        $this->assertSame('found', $task->status);
        $this->assertFalse($task->cache_hit);
        $this->assertSame('Galletitas Prueba 500 g', $task->product['name']);
        $this->assertNull($task->product['weight_kg']);
        $this->assertSame(10, $task->product['width_cm']);
        $this->assertSame('500 g', $task->product['net_content']);
        $this->assertSame($this->category->id, $task->classification['category_id']);
        $this->assertSame(2, AiTokenUsage::where('user_id', $this->owner->id)->where('proceso', 'like', 'ficha_producto_%')->count());
        $this->assertSame(1, SerperUsageEvent::where('user_id', $this->owner->id)->where('endpoint', 'search')->count());
        $this->assertEquals(1, SerperUsageEvent::where('user_id', $this->owner->id)->first()->credits);
        Http::assertSent(function ($request) { return $request->method() === 'PUT' && $request['status'] === 'found'; });
        Http::assertNotSent(function ($request) { return strpos($request->url(), 'serper-usage') !== false; });
    }

    public function test_upc_busqueda_preserva_el_cero_inicial()
    {
        $this->coldHttp(['matched_gtin' => null, 'name' => null, 'sources' => []], '<html>GTIN 012345678905</html>');
        $this->runTask($this->task(['bar_code' => '012345678905', 'gtin' => Gtin::canonical('012345678905')]));
        Http::assertSent(function ($request) { return $request->url() === 'https://google.serper.dev/search' && $request['q'] === '"012345678905"'; });
    }

    public function test_peso_bruto_explicito_se_convierte_de_gramos_a_kg()
    {
        $product = array_merge($this->product(), ['matched_gtin' => self::CODE, 'weight_kg' => [
            'value' => 550, 'unit' => 'g', 'evidence' => 'Peso bruto para envío: 550 g', 'source_url' => 'https://fabricante.test/producto']]);
        $this->coldHttp($product);
        $task = $this->runTask($this->task());
        $this->assertSame('found', $task->status);
        $this->assertSame(0.55, $task->product['weight_kg']);
    }

    public function test_no_acepta_categoria_ajena_ni_subcategoria_de_otro_padre()
    {
        $this->tree();
        $otherCategory = Category::create(['name' => 'Otra', 'user_id' => $this->owner->id]);
        $foreignSub = SubCategory::create(['name' => 'Fuera del padre', 'category_id' => $otherCategory->id, 'user_id' => $this->owner->id]);
        $self = $this;
        Http::fake(function ($request) use ($self, $foreignSub) {
            if (strpos($request->url(), '/claim') !== false) { return Http::response(['status' => 'found', 'product' => $self->product()]); }
            return $self->ai(['category_id' => $self->category->id, 'sub_category_id' => $foreignSub->id]);
        });
        $task = $this->runTask($this->task());
        $this->assertSame($this->category->id, $task->classification['category_id']);
        $this->assertNull($task->classification['sub_category_id']);
    }

    public function test_reserva_pendiente_no_busca_y_reintento_reclama_de_nuevo()
    {
        $self = $this;
        $calls = 0;
        Http::fake(function () use ($self, &$calls) { $calls++; return Http::response($calls === 1 ? ['status' => 'pending'] : ['status' => 'found', 'product' => $self->product()]); });
        $task = $this->runTask($this->task());
        $this->assertSame('waiting', $task->status);
        $task = $this->runTask($task);
        $this->assertSame('found', $task->status);
        $this->assertSame(0, (int) $task->search_attempts);
        Http::assertSentCount(2);
    }

    public function test_cold_path_cupo_agotado_libera_reserva_y_no_llama_serper()
    {
        $this->owner->update(['plan_ia_tope_busquedas_web_diarias' => 1]);
        $this->task(['status' => 'failed', 'search_attempts' => 1, 'search_started_at' => now()]);
        $this->coldHttp();
        $task = $this->runTask($this->task());
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString('tope diario', $task->message);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) { return $request->method() === 'PUT' && $request['status'] === 'failed'; });
    }

    /** @dataProvider respuestasIncompletas */
    public function test_json_incompleto_o_sin_fuentes_es_fallo_no_cache_negativa($data, $stop)
    {
        $this->coldHttp($data, null, $stop);
        $task = $this->runTask($this->task());
        $this->assertSame('failed', $task->status);
        Http::assertSent(function ($request) { return $request->method() === 'PUT' && $request['status'] === 'failed'; });
        Http::assertNotSent(function ($request) { return $request->method() === 'PUT' && $request['status'] === 'not_found'; });
    }

    public function respuestasIncompletas()
    {
        return [[[], 'end_turn'], [['matched_gtin' => self::CODE, 'name' => 'Producto', 'sources' => []], 'end_turn'],
            [['matched_gtin' => self::CODE, 'name' => 'Producto', 'sources' => [['url' => 'https://fabricante.test/producto']]], 'max_tokens']];
    }

    public function test_cancelacion_y_cola_de_otro_dueno_no_consumen()
    {
        Http::fake();
        $this->runTask($this->task(['status' => 'cancelled']));
        $task = $this->task();
        config(['app.USER_ID' => $this->owner->id + 1]);
        $this->assertSame('failed', $this->runTask($task)->status);
        Http::assertNothingSent();
    }

    public function test_cancelar_durante_claim_no_reabre_tarea_ni_gasta()
    {
        foreach (['pending', 'claimed'] as $claimStatus) {
            $task = $this->task();
            Http::fake(function ($request) use ($task, $claimStatus) {
                if ($request->method() === 'POST') {
                    $task->update(['status' => 'cancelled']);
                    return Http::response(['status' => $claimStatus, 'lease_token' => 'reserva-falsa']);
                }
                return Http::response(['status' => 'miss']);
            });
            $this->assertSame('cancelled', $this->runTask($task)->status);
            Http::assertNotSent(function ($request) { return strpos($request->url(), 'serper') !== false; });
        }
    }

    public function test_ssrf_no_sigue_redireccion_privada_ni_publica_cache_negativa()
    {
        $self = $this;
        Http::fake(function ($request) use ($self) {
            if (strpos($request->url(), '/claim') !== false) { return Http::response(['status' => 'claimed', 'lease_token' => 'reserva-falsa']); }
            if ($request->method() === 'PUT') { return Http::response(['status' => 'miss']); }
            if ($request->url() === 'https://google.serper.dev/search') { return Http::response(['organic' => [['link' => 'https://fabricante.test/producto']], 'credits' => 1]); }
            return Http::response('', 302, ['Location' => 'http://interno.test/secreto']);
        });
        $this->assertSame('failed', $this->runTask($this->task())->status);
        Http::assertNotSent(function ($request) { return strpos($request->url(), 'interno.test') !== false; });
        Http::assertSent(function ($request) { return $request->method() === 'PUT' && $request['status'] === 'failed'; });
    }

    public function test_serper_images_mide_una_llamada_y_timeout_desconocido_se_reintenta_sin_duplicar_uuid()
    {
        $provider = (new SerperImageSearchProvider('CLAVE-FALSA'))->usageContext(['user_id' => $this->owner->id, 'auth_user_id' => $this->owner->id, 'process' => 'imagenes_seleccion']);
        $phase = 'success';
        $uuid = null;
        Http::fake(function ($request) use (&$phase, &$uuid) {
            if (strpos($request->url(), 'serper.dev') !== false) {
                if ($phase === 'timeout') { throw new ConnectionException('Tiempo agotado'); }
                return Http::response(['images' => [], 'credits' => 1]);
            }
            if ($phase === 'sync-fails') { return Http::response([], 503); }
            $this->assertSame($uuid, $request['events'][0]['event_uuid']);
            return Http::response(['accepted' => count($request['events'])]);
        });
        $provider->buscar('Producto');
        $phase = 'timeout';
        $provider->buscar('Otro producto');
        $events = SerperUsageEvent::where('user_id', $this->owner->id)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(1.0, $events[0]->credits);
        $this->assertNull($events[1]->credits);
        $this->assertNull($events[1]->http_status);
        $uuid = $events[0]->event_uuid;
        $phase = 'sync-fails';
        try { app(SerperUsageService::class)->sync(); $this->fail('Debía quedar pendiente.'); } catch (\RuntimeException $e) {}
        $this->assertNull($events[0]->fresh()->synced_at);
        $phase = 'sync-ok';
        $this->assertSame(2, app(SerperUsageService::class)->sync());
        $this->assertNotNull($events[0]->fresh()->synced_at);
    }

    public function test_telemetria_solo_sincroniza_dueno_de_la_instancia()
    {
        $other = User::create(['name' => 'Otro dueño', 'email' => uniqid() . '@fichas.test', 'password' => 'sin-acceso']);
        SerperUsageService::record(['user_id' => $this->owner->id], 'images', 'Propio', null, microtime(true));
        SerperUsageService::record(['user_id' => $other->id], 'images', 'Ajeno', null, microtime(true));
        Http::fake(function ($request) { $this->assertCount(1, $request['events']); $this->assertSame('Propio', $request['events'][0]['query']); return Http::response(['accepted' => 1]); });
        $this->assertSame(1, app(SerperUsageService::class)->sync());
        $this->assertNull(SerperUsageEvent::where('user_id', $other->id)->first()->synced_at);
    }

    public function test_alta_real_guarda_marca_y_descripcion_segura_solo_al_confirmar()
    {
        $before = Brand::where('user_id', $this->owner->id)->count();
        $payload = ['name' => 'ZZ Producto enriquecido', 'cost' => 100, 'iva_id' => 2, 'aplicar_iva' => 1,
            'apply_provider_percentage_gain' => 0, 'cost_in_dollars' => 0, 'provider_cost_in_dollars' => 0,
            'online' => 0, 'in_offer' => 0, 'precio_pausado' => 0, 'default_in_vender' => 0, 'personalizar_price_en_vender' => 0,
            'omitir_en_lista_pdf' => 0, 'mercado_libre' => 0, 'disponible_tienda_nube' => 0, 'requires_shipping' => 0,
            'free_shipping' => 0, 'es_insumo' => 0, 'featured' => 0, 'price_types' => [], 'price_type_monedas' => [], 'tags' => [], 'addresses' => [], 'childrens' => [],
            'product_facts_apply' => ['brand_name' => 'Nueva marca confirmada', 'description' => '<script>alert(1)</script>Descripción real', 'sources' => $this->product()['sources']]];
        $result = $this->postJson('/api/article', $payload)->assertCreated();
        $article = Article::findOrFail($result->json('model.id'));
        $this->assertSame($before + 1, Brand::where('user_id', $this->owner->id)->count());
        $this->assertSame('Nueva marca confirmada', $article->brand->name);
        $description = $article->descriptions()->firstOrFail();
        $this->assertStringNotContainsString('<script>', $description->content);
        $this->assertStringContainsString('&lt;script&gt;', $description->content);
        $this->assertNotNull($description->ai_reviewed_at);
        $this->assertSame($this->product()['sources'], $description->ai_sources);
    }
}

class ResearchWithTestDns extends ProductFactsResearchService
{
    protected function resolver_host($host)
    {
        return $host === 'interno.test' ? ['10.0.0.5'] : ['93.184.216.34'];
    }
}
