<?php

namespace Tests\Feature\Integraciones;

use App\Jobs\ProcessSyncArticleImageToTiendaNube;
use App\Models\Article;
use App\Models\Image;
use App\Models\SyncToTNArticle;
use App\Services\TiendaNube\TiendaNubeImageService;
use App\Services\TiendaNube\TiendaNubeProductImageService;
use App\Services\TiendaNube\TiendaNubeProductService;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\EmpresaTestCase;

class TiendaNubeImagenesSyncTest extends EmpresaTestCase
{
    protected $remote = [];
    protected $posts = [];
    protected $puts = [];
    protected $urlStatus = 200;
    protected $apiStatus = 201;
    protected $invalidPost = false;
    protected $confirm = true;
    protected $getBody = null;
    protected $getStatus = 200;
    protected $remotePages = null;
    protected $timeoutOnce = false;
    protected $failPostAt = null;
    protected $photoBody = null;
    protected $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('TN_USER_ID=123456');
        $_ENV['TN_USER_ID'] = '123456';
        putenv('CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE=false');
        $_ENV['CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE'] = 'false';
        Http::fake(function ($request) {
            if (strpos($request->url(), 'photos.test') !== false) {
                $body = $this->photoBody === null ? $this->png().(strpos($request->url(), '2.png') !== false ? 'otra' : '') : $this->photoBody;
                return Http::response($body, $this->urlStatus, ['Content-Type' => 'image/png']);
            }
            if ($request->method() === 'GET' && strpos($request->url(), '/images') !== false) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $body = $this->remotePages === null ? $this->remote : ($this->remotePages[$query['page'] ?? 1] ?? []);
                return Http::response($this->getBody === null ? $body : $this->getBody, $this->getStatus);
            }
            if ($request->method() === 'POST' && substr($request->url(), -7) === '/images') {
                $this->posts[] = $request->data();
                if ($this->failPostAt === count($this->posts)) {
                    return Http::response(['error' => 'fallo simulado'], 500);
                }
                $data = ['id' => 400 + count($this->posts), 'product_id' => 7654321,
                    'src' => 'https://photos.test/cdn/'.(strpos($request['src'], '2.png') !== false ? '2.png' : '1.png')];
                if ($this->confirm && $this->apiStatus === 201) {
                    $this->remote[] = $data;
                }
                if ($this->timeoutOnce) {
                    $this->timeoutOnce = false;
                    throw new \Illuminate\Http\Client\ConnectionException('Respuesta perdida después de subir');
                }
                return Http::response($this->invalidPost ? ['src' => 'incompleta'] : $data, $this->apiStatus);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 7654321, 'variants' => [['id' => 8765432]], 'handle' => ['es' => 'foto']], 201);
            }
            if ($request->method() === 'PUT') {
                $this->puts[] = $request->data();
            }
            return Http::response(['id' => 7654321, 'handle' => ['es' => 'foto']], 200);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->evidence as $path) {
            if (is_file($path)) { unlink($path); }
        }
        putenv('TN_USER_ID');
        putenv('CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE');
        unset($_ENV['TN_USER_ID'], $_ENV['CARGAR_IMAGENES_DE_ARTICULOS_A_TIENDA_NUBE']);
        parent::tearDown();
    }

    protected function png()
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6X4AAAAAASUVORK5CYII=');
    }

    protected function article($new = false)
    {
        return Article::withoutEvents(function () use ($new) {
            return Article::create(['name' => 'Foto ticket 98', 'user_id' => auth()->id(), 'stock' => 9,
                'final_price' => 1250, 'price' => 1250, 'disponible_tienda_nube' => true,
                'tiendanube_product_id' => $new ? null : 7654321, 'tiendanube_variant_id' => $new ? null : 8765432,
                'needs_sync_with_tn' => true]);
        });
    }

    protected function photo($article, $n = 1, array $attributes = [])
    {
        return $article->images()->create(array_merge(['hosting_url' => 'https://photos.test/'.$n.'.png'], $attributes));
    }

    protected function service($article)
    {
        return new TiendaNubeProductImageService($article->user_id);
    }

    protected function lock_key($article)
    {
        $service = $this->service($article);
        $property = new \ReflectionProperty($service, 'store_id');
        $property->setAccessible(true);
        return 'tn-img:'.sha1(DB::connection()->getDatabaseName().':'.$property->getValue($service).':'.$article->tiendanube_product_id);
    }

    public function test_alta_sin_flag_confirma_varias_fotos_y_no_altera_precio_ni_stock()
    {
        $article = $this->article(true);
        $one = $this->photo($article);
        $two = $this->photo($article, 2);
        (new TiendaNubeProductService(null, $article->user_id))->crearOActualizarProducto($article);
        $this->assertCount(2, $this->posts);
        $this->assertEquals(401, $one->fresh()->tienda_nube_image_id);
        $this->assertEquals(402, $two->fresh()->tiendanube_image_id);
        $this->assertFalse((bool) $article->fresh()->needs_sync_with_tn);
        $this->assertEquals(9, $article->fresh()->stock);
        $this->assertEquals(1250, $article->fresh()->final_price);
    }

    public function test_actualizacion_y_reintento_no_duplican_fotos_y_conservan_payload_variante()
    {
        $article = $this->article();
        $this->photo($article);
        $service = new TiendaNubeProductService(null, $article->user_id);
        $service->crearOActualizarProducto($article);
        $service->crearOActualizarProducto($article);
        $this->assertCount(1, $this->posts);
        $variant = collect($this->puts)->first(function ($payload) { return isset($payload['stock']); });
        $this->assertSame(9, $variant['stock']);
        $this->assertEquals(1250, $variant['price']);
    }

    public function test_carga_posterior_por_job_relee_relacion_y_no_duplica_al_reintentar()
    {
        $article = $this->article();
        $article->load('images');
        $image = $this->photo($article);
        $job = new ProcessSyncArticleImageToTiendaNube($article, $image);
        $job->handle();
        $job->handle();
        $this->assertCount(1, $this->posts);
        $this->assertEquals(401, $image->fresh()->tienda_nube_image_id);
    }

    public function test_la_entrada_individual_conserva_posicion_explicita()
    {
        $article = $this->article();
        $image = $this->photo($article);
        (new TiendaNubeImageService($article->user_id))->subirImagenDeArticulo($article, $image, 1);
        $this->assertSame(1, $this->posts[0]['position']);
    }

    public function test_la_entrada_individual_conserva_posicion_al_crear_producto_nuevo()
    {
        $article = $this->article(true);
        $image = $this->photo($article);
        (new TiendaNubeImageService($article->user_id))->subirImagenDeArticulo($article, $image, 1);
        $this->assertSame(1, $this->posts[0]['position']);
        $this->assertEquals(401, $image->fresh()->tienda_nube_image_id);
    }

    public function test_recupera_marcador_heredado_sin_reenviar()
    {
        $article = $this->article();
        $image = $this->photo($article, 1, ['tiendanube_image_id' => 700]);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/cdn/1.png']];
        $this->service($article)->sync_images_for_article($article);
        $this->assertCount(0, $this->posts);
        $this->assertEquals(700, $image->fresh()->tienda_nube_image_id);
    }

    public function test_foto_remota_sin_marcador_se_reconoce_por_contenido()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/cdn/1.png']];
        $this->service($article)->sync_images_for_article($article);
        $this->assertCount(0, $this->posts);
        $this->assertEquals(700, $image->fresh()->tiendanube_image_id);
    }

    public function test_foto_remota_desconocida_se_separa_como_ambigua_sin_subir()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/cdn/2.png']];
        $report = $this->service($article)->reconcile($article);
        $this->assertSame('ambigua', $report['images'][0]['action']);
        $this->assertNull($image->fresh()->tienda_nube_image_id);
        $this->assertCount(0, $this->posts);
    }

    public function test_marcadores_discordantes_no_eligen_una_foto_arbitraria()
    {
        $article = $this->article();
        $this->photo($article, 1, ['tienda_nube_image_id' => 700, 'tiendanube_image_id' => 701]);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/1.png'],
            ['id' => 701, 'product_id' => 7654321, 'src' => 'https://photos.test/2.png']];
        $this->assertSame('ambigua', $this->service($article)->reconcile($article)['images'][0]['action']);
        $this->assertCount(0, $this->posts);
    }

    public function test_url_inaccesible_no_declara_exito_en_cola()
    {
        $article = $this->article();
        $this->photo($article);
        $this->urlStatus = 404;
        $sync = SyncToTNArticle::create(['article_id' => $article->id, 'user_id' => $article->user_id, 'status' => 'pendiente']);
        (new TiendaNubeSyncArticleService())->sync_article($sync);
        $this->assertSame('error', $sync->fresh()->status);
        $this->assertTrue((bool) $article->fresh()->needs_sync_with_tn);
        $this->assertCount(0, $this->posts);
    }

    public function test_fallo_api_no_repite_post_ni_guarda_marcador()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->apiStatus = 500;
        try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía fallar.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('HTTP 500', $e->getMessage()); }
        $this->assertCount(1, $this->posts);
        $this->assertNull($image->fresh()->tienda_nube_image_id);
    }

    public function test_respuesta_post_incompleta_no_guarda_marcador()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->invalidPost = true;
        try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía fallar.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('inválida', $e->getMessage()); }
        $this->assertNull($image->fresh()->tienda_nube_image_id);
    }

    public function test_alta_sin_confirmacion_get_no_guarda_marcador()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->confirm = false;
        try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía fallar.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('no confirmó', $e->getMessage()); }
        $this->assertNull($image->fresh()->tienda_nube_image_id);
    }

    public function test_respuesta_get_objeto_no_se_interpreta_como_lista_vacia()
    {
        $article = $this->article();
        $this->photo($article);
        $this->getBody = '{}';
        $this->expectException(\RuntimeException::class);
        $this->service($article)->sync_images_for_article($article);
    }

    public function test_borrado_con_marcador_heredado_limpia_ambos()
    {
        $article = $this->article();
        $image = $this->photo($article, 1, ['tiendanube_image_id' => 700]);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/1.png']];
        $this->service($article)->delete_image_from_article($article, $image);
        $this->assertNull($image->fresh()->tienda_nube_image_id);
        $this->assertNull($image->fresh()->tiendanube_image_id);
        Http::assertSent(function ($request) { return $request->method() === 'DELETE' && substr($request->url(), -11) === '/images/700'; });
    }

    protected function command($article, $apply = false)
    {
        $path = storage_path('logs/tn98-'.uniqid().'.jsonl');
        $this->evidence[] = $path;
        $options = ['--usuario' => $article->user_id, '--articulos' => (string) $article->id, '--evidencia' => $path];
        if ($apply) { $options['--aplicar'] = true; }
        return [Artisan::call('tiendanube:reconciliar-imagenes', $options), $path];
    }

    public function test_comando_simula_sin_escribir_y_aplica_con_respaldo_repetible()
    {
        $article = $this->article();
        $image = $this->photo($article);
        list($code, $path) = $this->command($article);
        $this->assertSame(0, $code);
        $this->assertCount(0, $this->posts);
        $this->assertNull($image->fresh()->tienda_nube_image_id);
        $this->assertStringContainsString('"event":"antes"', file_get_contents($path));
        list($code, $path) = $this->command($article, true);
        $this->assertSame(0, $code);
        $this->assertEquals(401, $image->fresh()->tienda_nube_image_id);
        $this->assertStringContainsString('"remote_after"', file_get_contents($path));
        list($code) = $this->command($article, true);
        $this->assertSame(0, $code);
        $this->assertCount(1, $this->posts);
    }

    public function test_comando_rechaza_alcance_ajeno_sin_pedidos_remotos()
    {
        $article = $this->article();
        $code = Artisan::call('tiendanube:reconciliar-imagenes', ['--usuario' => $article->user_id + 99999, '--articulos' => (string) $article->id]);
        $this->assertSame(1, $code);
        Http::assertNothingSent();
    }

    public function test_timeout_que_si_creo_foto_se_reconcilia_sin_segundo_post()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->timeoutOnce = true;
        try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía perderse la respuesta.'); }
        catch (\Illuminate\Http\Client\ConnectionException $e) { $this->assertCount(1, $this->remote); }
        $this->assertNull($image->fresh()->tienda_nube_image_id);
        $this->service($article)->sync_images_for_article($article);
        $this->assertCount(1, $this->posts);
        $this->assertEquals(401, $image->fresh()->tienda_nube_image_id);
    }

    public function test_paginacion_busca_la_coincidencia_en_segunda_pagina()
    {
        $article = $this->article();
        $image = $this->photo($article, 1, ['tienda_nube_image_id' => 999]);
        $first = [];
        for ($id = 1; $id <= 100; $id++) {
            $first[] = ['id' => $id, 'product_id' => 7654321, 'src' => 'https://photos.test/2.png'];
        }
        $this->remotePages = [1 => $first, 2 => [['id' => 999, 'product_id' => 7654321, 'src' => 'https://photos.test/1.png']]];
        $this->service($article)->sync_images_for_article($article);
        $this->assertEquals(999, $image->fresh()->tiendanube_image_id);
        $this->assertCount(0, $this->posts);
        Http::assertSent(function ($request) { return $request->method() === 'GET' && strpos($request->url(), 'page=2') !== false; });
    }

    public function test_marcador_de_producto_anterior_se_reemplaza_si_remoto_esta_vacio()
    {
        $article = $this->article();
        $image = $this->photo($article, 1, ['tienda_nube_image_id' => 50, 'tiendanube_image_id' => 50]);
        $this->service($article)->sync_images_for_article($article);
        $this->assertCount(1, $this->posts);
        $this->assertEquals(401, $image->fresh()->tienda_nube_image_id);
    }

    public function test_get_error_no_sube_ni_modifica_marcador()
    {
        $article = $this->article();
        $image = $this->photo($article);
        $this->getStatus = 503;
        try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía fallar.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('HTTP 503', $e->getMessage()); }
        $this->assertCount(0, $this->posts);
        $this->assertNull($image->fresh()->tienda_nube_image_id);
    }

    public function test_url_200_con_html_no_se_sube()
    {
        $article = $this->article();
        $this->photo($article);
        $this->photoBody = '<html>No es una foto</html>';
        $report = $this->service($article)->reconcile($article);
        $this->assertSame('error', $report['images'][0]['action']);
        $this->assertCount(0, $this->posts);
    }

    public function test_candado_ocupado_impide_pedidos_externos()
    {
        $article = $this->article();
        $this->photo($article);
        $connection = config('database.default');
        config(['database.connections.tn_lock_test' => config('database.connections.'.$connection)]);
        $key = $this->lock_key($article);
        $other = DB::connection('tn_lock_test');
        $this->assertEquals(1, $other->selectOne('SELECT GET_LOCK(?, 0) AS tomado', [$key])->tomado);
        try {
            try { $this->service($article)->sync_images_for_article($article); $this->fail('Debía bloquearse.'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('en curso', $e->getMessage()); }
            Http::assertNothingSent();
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$key]);
            DB::purge('tn_lock_test');
        }
    }

    public function test_comando_registra_antes_y_estado_parcial_despues_del_fallo()
    {
        $article = $this->article();
        $one = $this->photo($article);
        $two = $this->photo($article, 2);
        $this->failPostAt = 2;
        list($code, $path) = $this->command($article, true);
        $this->assertSame(1, $code);
        $this->assertEquals(401, $one->fresh()->tienda_nube_image_id);
        $this->assertNull($two->fresh()->tienda_nube_image_id);
        $events = array_map(function ($line) { return json_decode($line, true); }, file($path, FILE_IGNORE_NEW_LINES));
        $local = collect($events)->firstWhere('event', 'local_despues_del_error');
        $this->assertEquals(401, $local['images'][0]['tienda_nube_image_id']);
        $remote = collect($events)->firstWhere('event', 'remoto_despues_del_error');
        $this->assertCount(1, $remote['images']);
        $this->assertCount(2, $this->posts);
    }

    public function test_baja_local_termina_dentro_del_mismo_candado()
    {
        $article = $this->article();
        $image = $this->photo($article, 1, ['tienda_nube_image_id' => 700]);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/1.png']];
        $key = $this->lock_key($article);
        $this->service($article)->delete_image_from_article($article, $image, function () use ($image, $key) {
            $this->assertEquals(DB::selectOne('SELECT CONNECTION_ID() AS id')->id, DB::selectOne('SELECT IS_USED_LOCK(?) AS id', [$key])->id);
            $image->delete();
        });
        $this->assertNull($image->fresh());
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS id', [$key])->id);
    }

    public function test_endpoint_real_borra_foto_y_el_siguiente_sync_no_la_resube()
    {
        Storage::fake('public');
        Storage::disk('public')->put('1.png', $this->png());
        $article = $this->article();
        $image = $this->photo($article, 1, ['tiendanube_image_id' => 700]);
        $this->remote = [['id' => 700, 'product_id' => 7654321, 'src' => 'https://photos.test/1.png']];
        $this->deleteJson('/api/delete-image-model/article/'.$article->id.'/'.$image->id)->assertStatus(200);
        $this->assertNull($image->fresh());
        Storage::disk('public')->assertMissing('1.png');
        $this->service($article)->sync_images_for_article($article);
        $this->assertCount(0, $this->posts);
        $this->assertEquals(9, $article->fresh()->stock);
        $this->assertEquals(1250, $article->fresh()->final_price);
    }
}
