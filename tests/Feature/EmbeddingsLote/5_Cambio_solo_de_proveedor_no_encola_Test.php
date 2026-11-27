<?php

namespace Tests\Feature\EmbeddingsLote;

use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA19) — una actualización masiva que cambia SÓLO el proveedor no genera embeddings.
 *
 * Caso real (Dany, versión vieja 4.3.65): una actualización masiva de 27.580 artículos que cambió únicamente `provider_id` movió
 * `updated_at` y el comando agendado volvió a encolar todo el catálogo. Pedido de Lucas (27/11/2026): "los embeddings solamente
 * deben generarse cuando cambia el nombre del producto o la descripción".
 *
 * Hoy lo impide en dos puntas: el observer sólo encola si cambia name/bar_code/category_id/brand_id/status, y el comando
 * `articles:generate-embeddings` sella `embedding_generated_at` de los pendientes cuyo texto (huella) no cambió, sin despachar.
 * Este test FIJA ese comportamiento con el escenario real (más de un lote de 100) para que el lote y la cola propia no lo rompan.
 *
 * 🔴 Sin OpenAI real: `Http::fake()` + `Http::assertNothingSent()` y `Queue::fake()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types ni enum.
 *
 * @group whatsapp
 */
class Cambio_solo_de_proveedor_no_encola_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** Artículos del catálogo de prueba: más de un lote de 100. */
    const CANTIDAD = 120;

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Pausa apagada, clave de prueba, gate de observers reseteado y dueño de la instancia en `app.USER_ID`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.embeddings_generacion_pausada' => false]);
        config(['services.openai.api_key' => 'clave-de-prueba']);
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);
        config(['cache.default' => 'array']);
        Cache::forget(\App\Services\EmbeddingsCredencialCorte::CLAVE);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio cambio de proveedor',
            'company_name' => 'Distribuidora cambio de proveedor',
            'email'        => 'embeddings-lote-proveedor-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Deja limpio el memo estático del gate y la hora fingida.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Le da la extensión `agentes_ia` al comercio y olvida el memo del gate.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Agentes IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        ArticleObserver::resetear_cache_gate();
    }

    /**
     * Crea el catálogo con la extensión todavía cerrada (nadie encola) y deja a cada artículo con vector, huella igual a la
     * de su texto actual y fecha de generación de ayer: el estado de un catálogo ya vectorizado.
     *
     * @return int[] IDs creados.
     */
    protected function crear_catalogo_al_dia()
    {
        $ids = [];

        for ($i = 1; $i <= self::CANTIDAD; $i++) {
            $articulo = Article::create([
                'name'        => 'Tornillo hexagonal ' . $i,
                'status'      => 'active',
                'user_id'     => $this->comercio->id,
                'provider_id' => null,
            ]);

            $ids[] = (int) $articulo->id;
        }

        $servicio = new ArticleEmbeddingService();

        foreach ($ids as $id) {
            $fresco = Article::with(['category', 'brand', 'descriptions'])->find($id);
            $texto  = $servicio->embedding_for_article($fresco);

            DB::table('articles')->where('id', $id)->update([
                'embedding'              => json_encode([1.0, 0.0, 0.0]),
                'embedding_source_hash'  => sha1($texto),
                'embedding_generated_at' => Carbon::now()->subDay(),
                'embedding_queued_at'    => null,
                'updated_at'             => Carbon::now()->subDays(2),
            ]);
        }

        return $ids;
    }

    /**
     * Simula la actualización masiva de Dany: cambia SÓLO el proveedor, uno por uno con Eloquent (corren los observers), y
     * después deja `updated_at` movido a hace un minuto (posterior a la generación de ayer).
     *
     * @param int[] $ids
     *
     * @return void
     */
    protected function cambiar_solo_proveedor(array $ids)
    {
        foreach ($ids as $id) {
            $articulo = Article::find($id);
            $articulo->provider_id = 987654;
            $articulo->save();
        }

        DB::table('articles')->whereIn('id', $ids)->update(['updated_at' => Carbon::now()->subMinute()]);
    }

    /**
     * CA19. 120 artículos al día, cambio masivo sólo de proveedor: el observer no despacha nada, el comando tampoco, no hay ni un
     * pedido a OpenAI y los 120 quedan sellados (`embedding_generated_at >= updated_at`), así que una segunda pasada tampoco hace nada.
     *
     * @group whatsapp
     * @test
     */
    public function test_cambiar_solo_el_proveedor_no_encola_ni_llama_a_openai()
    {
        $ids = $this->crear_catalogo_al_dia();

        $this->dar_extension();
        Queue::fake();
        Http::fake();

        $this->cambiar_solo_proveedor($ids);

        // Por el observer: ni un job.
        Queue::assertNothingPushed();

        $this->assertSame(
            self::CANTIDAD,
            DB::table('articles')->whereIn('id', $ids)->where('provider_id', 987654)->count(),
            'Precondición: el proveedor sí cambió en los 120.'
        );
        $this->assertSame(
            self::CANTIDAD,
            DB::table('articles')->whereIn('id', $ids)->whereColumn('updated_at', '>', 'embedding_generated_at')->count(),
            'Precondición: los 120 aparecen como "modificados después de la última generación".'
        );

        // Por el comando agendado: tampoco.
        Artisan::call('articles:generate-embeddings');

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertNotPushed(GenerateArticleEmbeddingsBatchJob::class);
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $sin_sellar = DB::table('articles')->whereIn('id', $ids)->whereColumn('embedding_generated_at', '<', 'updated_at')->count();
        $this->assertSame(0, $sin_sellar, 'Los 120 quedan sellados: embedding_generated_at >= updated_at.');

        $con_marca = DB::table('articles')->whereIn('id', $ids)->whereNotNull('embedding_queued_at')->count();
        $this->assertSame(0, $con_marca, 'Nadie reclamó la marca de encolado.');

        // Segunda pasada: nada pendiente.
        Artisan::call('articles:generate-embeddings');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /**
     * Contraste. En el mismo catálogo, cambiar el NOMBRE de un artículo SÍ lo encola (el observer despacha su job) y, si el
     * comando lo encuentra pendiente, arma un lote con ese solo artículo: los otros 119 (sólo con proveedor nuevo) siguen sin encolarse.
     *
     * @group whatsapp
     * @test
     */
    public function test_cambiar_el_nombre_si_encola_solo_a_ese_articulo()
    {
        $ids = $this->crear_catalogo_al_dia();

        $this->dar_extension();
        Queue::fake();
        Http::fake();

        $this->cambiar_solo_proveedor($ids);
        Queue::assertNothingPushed();

        // Cambio de nombre real de un artículo.
        $renombrado = $ids[10];
        $articulo = Article::find($renombrado);
        $articulo->name = 'Bulón galvanizado reforzado';
        $articulo->save();

        // Por el observer: un job para ese artículo y ninguno para los demás.
        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
        Queue::assertPushed(GenerateArticleEmbeddingJob::class, function ($job) use ($renombrado) {
            return (int) $job->get_article_id() === $renombrado;
        });

        // Por el comando (sin la marca del observer, como si ese job se hubiera perdido): un lote con sólo ese artículo.
        Queue::fake();
        DB::table('articles')->where('id', $renombrado)->update([
            'embedding_queued_at' => null,
            'updated_at'          => Carbon::now()->subMinute(),
        ]);

        Artisan::call('articles:generate-embeddings');

        Queue::assertPushed(GenerateArticleEmbeddingsBatchJob::class, 1);
        $lote = Queue::pushed(GenerateArticleEmbeddingsBatchJob::class)->first();
        $this->assertSame([$renombrado], array_map('intval', $lote->get_article_ids()), 'El lote lleva sólo al artículo renombrado.');
        Http::assertNothingSent();
    }
}
