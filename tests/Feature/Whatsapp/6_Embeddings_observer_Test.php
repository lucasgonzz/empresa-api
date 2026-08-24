<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\ImportStatus;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/6: el observer de embeddings y el corte por huella.
 *
 * Dos cosas distintas y las dos sobre plata:
 *
 * 1. El observer vectoriza al instante lo que se crea o se edita, en vez de esperar hasta
 *    media hora al comando agendado. Pero SOLO cuando cambió algo que entra en el texto
 *    vectorizado: una importación de lista de precios mueve `final_price` y `stock` de
 *    miles de artículos, y regenerar por eso sería pagarle a OpenAI para que devuelva el
 *    mismo vector.
 * 2. El corte por `embedding_source_hash` (D9) tapa el agujero que quedaba igual: el
 *    comando agendado reencola por `updated_at > embedding_generated_at`, así que sin la
 *    huella la importación terminaba pagando una llamada por artículo de todas formas.
 *
 * 🔴 ACÁ ESTÁ PROHIBIDO `Event::fake()`. Event::fake silencia también los eventos de
 * modelo de Eloquent, así que el observer nunca correría y TODOS estos tests darían verde
 * sin probar absolutamente nada. El broadcast se corta con `broadcasting.default = null`
 * y los despachos se capturan con `Queue::fake()`.
 *
 * 🔴 `ArticleObserver::resetear_cache_gate()` en cada punto donde cambian las condiciones:
 * el memo del gate es estático y sobrevive entre casos del mismo proceso de PHPUnit.
 */
class Embeddings_observer_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'whatsapp_ia';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // Sin Event::fake (ver el docblock de la clase): el broadcast se corta por config.
        config(['broadcasting.default' => 'null']);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-6',
            'company_name' => 'Ferreteria F8-6',
            'email'        => 'whatsapp-f8-6-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    /**
     * Asigna la extensión al comercio y olvida el memo del gate, para que la próxima
     * evaluación vea el estado nuevo.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp con IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        ArticleObserver::resetear_cache_gate();
    }

    /**
     * @param string $nombre
     * @param array  $extra
     * @return Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * Texto que el job vectorizaría hoy para ese artículo, y su huella.
     *
     * @param int $article_id
     * @return array{0: string, 1: string}
     */
    protected function texto_y_huella($article_id)
    {
        $fresco = Article::with(['category', 'brand', 'descriptions'])->find($article_id);
        $texto  = (new ArticleEmbeddingService())->embedding_for_article($fresco);

        return [$texto, sha1($texto)];
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_la_extension_crear_un_articulo_encola_su_embedding()
    {
        $this->dar_extension();
        Queue::fake();

        $this->articulo('Tornillo de 3 pulgadas');

        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
    }

    /**
     * Sin la extensión el catálogo vectorial no lo consume nadie: generarlo sería gasto puro.
     *
     * @group whatsapp
     * @test
     */
    public function sin_la_extension_no_se_encola_nada()
    {
        Queue::fake();

        $articulo = $this->articulo('Tornillo sin extensión');

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        // Y editarlo tampoco encola.
        Queue::fake();
        $articulo->update(['name' => 'Tornillo sin extensión, renombrado']);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
    }

    /**
     * El caso que motiva todo: una importación de lista de precios pisa `final_price` y
     * `stock` de miles de artículos, y ninguno de los dos entra en el texto vectorizado.
     *
     * @group whatsapp
     * @test
     */
    public function un_update_que_solo_mueve_precio_o_stock_no_encola_nada()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Tornillo de precio cambiante');

        // Se re-fakea para limpiar el despacho del alta y aislar lo que hace el update.
        Queue::fake();
        $articulo->update(['final_price' => 1234.56]);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        Queue::fake();
        $articulo->update(['stock' => 87]);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function un_update_de_los_campos_que_entran_al_texto_si_encola()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Tornillo original');

        $cambios = [
            ['name' => 'Tornillo renombrado'],
            ['bar_code' => '7790000000001'],
            ['category_id' => 987654],
            ['brand_id' => 4321],
            ['status' => 'inactive'],
        ];

        foreach ($cambios as $cambio) {
            Queue::fake();
            $articulo->update($cambio);

            Queue::assertPushed(
                GenerateArticleEmbeddingJob::class,
                1
            );
        }
    }

    /**
     * Una importación toca miles de artículos de una: encolar un job por cada uno sería una
     * tormenta sobre filas que además siguen cambiando. Lo que el observer se saltea acá lo
     * levanta después el comando agendado.
     *
     * @group whatsapp
     * @test
     */
    public function con_una_importacion_en_curso_no_se_encola_nada()
    {
        $this->dar_extension();

        ImportStatus::create([
            'user_id' => $this->comercio->id,
            'status'  => 'en_proceso',
        ]);

        ArticleObserver::resetear_cache_gate();
        Queue::fake();

        $articulo = $this->articulo('Tornillo importado');
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        Queue::fake();
        $articulo->update(['name' => 'Tornillo importado y renombrado']);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
    }

    /**
     * D9: el corte que evita pagarle a OpenAI por un vector idéntico al que ya está guardado.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_no_llama_a_openai_si_la_huella_del_texto_no_cambio()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Tornillo ya vectorizado');

        $huella = $this->texto_y_huella($articulo->id);

        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'              => json_encode([1.0, 0.0, 0.0]),
            'embedding_source_hash'  => $huella[1],
            'embedding_generated_at' => now()->subDay(),
        ]);

        Http::fake(['*' => Http::response([], 200)]);

        (new GenerateArticleEmbeddingJob($articulo->id))->handle(app(ArticleEmbeddingService::class));

        Http::assertNothingSent();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();
        $this->assertEquals($huella[1], $fila->embedding_source_hash, 'La huella queda como estaba.');
        $this->assertTrue(
            now()->subMinute()->lt($fila->embedding_generated_at),
            'La marca de tiempo sí se refresca, para que el comando agendado deje de reencolarlo.'
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_job_llama_a_openai_y_escribe_las_dos_columnas_si_el_texto_cambio()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Tornillo con texto nuevo');

        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'             => null,
            'embedding_source_hash' => 'huella-que-ya-no-corresponde',
        ]);

        $llamadas_a_openai = 0;
        Http::fake([
            'api.openai.com/*' => function () use (&$llamadas_a_openai) {
                $llamadas_a_openai++;

                return Http::response(['data' => [['embedding' => [0.5, 0.25, 0.125]]]], 200);
            },
            '*' => Http::response([], 200),
        ]);

        (new GenerateArticleEmbeddingJob($articulo->id))->handle(app(ArticleEmbeddingService::class));

        $this->assertEquals(1, $llamadas_a_openai, 'Con el texto cambiado sí se paga la llamada.');

        $huella = $this->texto_y_huella($articulo->id);
        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertNotNull($fila->embedding, 'El vector nuevo tiene que quedar persistido.');
        $this->assertEquals($huella[1], $fila->embedding_source_hash, 'Y la huella del texto usado, también.');
        $this->assertNotNull($fila->embedding_generated_at);
    }
}
