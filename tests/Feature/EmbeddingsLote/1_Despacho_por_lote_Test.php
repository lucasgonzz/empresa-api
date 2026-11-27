<?php

namespace Tests\Feature\EmbeddingsLote;

use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingBatchProcessor;
use App\Services\ArticleEmbeddingDispatcher;
use App\Services\EmbeddingsCredencialCorte;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA1 y CA2) — el COMANDO arma los lotes y el despachador reclama la marca por artículo.
 *
 * Hasta hoy `articles:generate-embeddings` despachaba UN `GenerateArticleEmbeddingJob` por artículo (22.700 artículos =
 * 22.700 jobs y 22.700 llamadas a OpenAI). Ahora recorre los pendientes de a 100 (`chunkById`) y por cada tanda de 100 pide
 * `ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued()`, que reclama la marca de CADA artículo con el mismo UPDATE
 * atómico de hoy (es lo que da la idempotencia exacta) y despacha UN `GenerateArticleEmbeddingsBatchJob` con los reclamados.
 *
 * 🔴 Todos estos tests usan `Queue::fake()`: miden QUÉ se despacha y con qué marcas, no lo que hace el job (eso es de
 * `2_Una_llamada_por_lote_Test` y `3_Fallas_del_lote_Test`). No hay OpenAI: ningún pedido sale y el freno de `tests/TestCase.php`
 * lo vigila.
 *
 * Los artículos se crean ANTES de dar la extensión `agentes_ia`: con el gate de los observers cerrado nadie los encola ni los
 * marca, que es el estado de un catálogo recién importado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types ni enum.
 *
 * @group whatsapp
 */
class Despacho_por_lote_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Pausa apagada (la base de tests la trae prendida), clave de prueba (sin clave el comando no despacha), gate de observers
     * reseteado y dueño de la instancia en `app.USER_ID`, igual que el scheduler.
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

        // El corte por credencial vive en el cache: `array` para que ningún test herede el de otro.
        config(['cache.default' => 'array']);
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio despacho por lote',
            'company_name' => 'Ferreteria despacho por lote',
            'email'        => 'embeddings-lote-despacho-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Deja limpio el memo estático del gate para el próximo test del proceso.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

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
     * Crea artículos activos del comercio (pendientes, sin vector ni fecha de generación).
     *
     * @param int    $cantidad
     * @param string $prefijo  Para que los nombres no se parezcan entre escenarios.
     *
     * @return int[] IDs creados, en orden de creación.
     */
    protected function crear_articulos($cantidad, $prefijo = 'Tornillo')
    {
        $ids = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $articulo = Article::create([
                'name'    => $prefijo . ' ' . $i,
                'status'  => 'active',
                'user_id' => $this->comercio->id,
            ]);

            $ids[] = (int) $articulo->id;
        }

        return $ids;
    }

    /**
     * Pone (o saca) la marca de "encolado" con el query builder, sin tocar `updated_at` ni disparar observers.
     *
     * @param int      $article_id
     * @param int|null $minutos_atras Hace cuántos minutos se marcó; null = sin marca.
     *
     * @return void
     */
    protected function poner_marca($article_id, $minutos_atras)
    {
        DB::table('articles')->where('id', $article_id)->update([
            'embedding_queued_at' => is_null($minutos_atras) ? null : Carbon::now()->subMinutes($minutos_atras),
        ]);
    }

    /**
     * Lee la marca de "encolado" de un artículo directo de la base.
     *
     * @param int $article_id
     *
     * @return string|null
     */
    protected function marca_de($article_id)
    {
        return DB::table('articles')->where('id', $article_id)->value('embedding_queued_at');
    }

    /**
     * Los jobs de lote empujados al fake, en el orden en que se empujaron.
     *
     * @return \Illuminate\Support\Collection de GenerateArticleEmbeddingsBatchJob
     */
    protected function lotes_empujados()
    {
        return Queue::pushed(GenerateArticleEmbeddingsBatchJob::class)->values();
    }

    /**
     * Abre el corte por credencial rechazada a mano (como lo deja un 401 de OpenAI).
     *
     * @return void
     */
    protected function abrir_corte_a_mano()
    {
        Cache::put(EmbeddingsCredencialCorte::CLAVE, [
            'desde'   => Carbon::now()->toIso8601String(),
            'status'  => 401,
            'mensaje' => 'Incorrect API key provided',
        ], 3600);
    }

    // ─────────────────────────────── CA1 ───────────────────────────────

    /**
     * CA1. 205 pendientes del dueño (con `agentes_ia` y clave cargada): el comando empuja exactamente 3 lotes de 100, 100 y 5
     * ids (sin repetidos y cuya unión son los 205), ningún job de un artículo, un finalizador; la tanda queda `en_proceso`
     * con `total_jobs = 205` (cuenta ARTÍCULOS) y los 205 quedan marcados.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_comando_despacha_lotes_de_cien()
    {
        $ids = $this->crear_articulos(205);
        $this->dar_extension();

        Queue::fake();

        Artisan::call('articles:generate-embeddings');

        $lotes = $this->lotes_empujados();

        $this->assertCount(3, $lotes, 'Tres lotes: 100 + 100 + 5.');
        $this->assertSame(
            [100, 100, 5],
            $lotes->map(function ($lote) {
                return count($lote->get_article_ids());
            })->all(),
            'Los lotes llevan 100, 100 y 5 artículos.'
        );

        $ids_despachados = [];
        foreach ($lotes as $lote) {
            foreach ($lote->get_article_ids() as $id) {
                $ids_despachados[] = (int) $id;
            }
        }
        $this->assertSame(205, count(array_unique($ids_despachados)), 'Ningún artículo viaja en dos lotes.');
        $ordenados = $ids_despachados;
        sort($ordenados);
        $this->assertSame($ids, $ordenados, 'La unión de los lotes son exactamente los 205 pendientes.');

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($tanda, 'El comando abre una tanda.');
        $this->assertSame('en_proceso', $tanda->status);
        $this->assertSame(205, (int) $tanda->total_jobs, 'total_jobs cuenta ARTÍCULOS despachados (no jobs).');

        foreach ($lotes as $lote) {
            $this->assertSame((int) $tanda->id, $lote->get_embedding_run_id(), 'Cada lote reporta a la tanda.');
        }

        $this->assertSame(
            205,
            DB::table('articles')->whereIn('id', $ids)->whereNotNull('embedding_queued_at')->count(),
            'Los 205 quedan marcados como encolados.'
        );

        // Al final y no al principio: si la clase todavía no existe, que se vea primero que no se despachan lotes.
        $this->assertSame(100, ArticleEmbeddingBatchProcessor::TAMANO_LOTE, 'El tamaño de lote es 100 (OpenAI acepta hasta 2048 entradas; con 100 caben holgado en los tokens del pedido).');
    }

    // ─────────────────────────────── CA2 ───────────────────────────────

    /**
     * CA2 (marca). `dispatch_batch_if_not_queued([a,b,c])` con `b` ya marcado hace 1 min devuelve 2 y el lote lleva [a,c]; repetir
     * la llamada devuelve 0 y no empuja nada; con `b` marcado hace 13 h (vencida porque el TTL es de 12 h) lo incluye.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_reclamo_por_lote_respeta_la_marca()
    {
        list($a, $b, $c) = $this->crear_articulos(3, 'Marca');
        $tanda = EmbeddingRun::create(['user_id' => $this->comercio->id, 'origen' => 'scheduler', 'status' => 'despachando', 'total_jobs' => 0]);

        $this->poner_marca($b, 1);
        Queue::fake();

        $reclamados = ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued([$a, $b, $c], (int) $tanda->id);

        $this->assertSame(2, $reclamados, 'Devuelve la cantidad de artículos reclamados: b ya tenía un job vigente.');
        Queue::assertPushed(GenerateArticleEmbeddingsBatchJob::class, 1);
        $lote = $this->lotes_empujados()->first();
        $this->assertSame([$a, $c], array_map('intval', $lote->get_article_ids()), 'El lote lleva sólo a y c, en el orden recibido.');
        $this->assertSame((int) $tanda->id, $lote->get_embedding_run_id());
        $this->assertNotNull($this->marca_de($a), 'a quedó marcado.');
        $this->assertNotNull($this->marca_de($c), 'c quedó marcado.');

        // Repetir la llamada: a, b y c ya tienen un job vigente.
        $de_nuevo = ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued([$a, $b, $c], (int) $tanda->id);

        $this->assertSame(0, $de_nuevo, 'Repetida, no reclama nada.');
        Queue::assertPushed(GenerateArticleEmbeddingsBatchJob::class, 1);

        // Una marca vencida (más vieja que las 12 h de vigencia) deja de contar.
        $this->poner_marca($b, 13 * 60);

        $con_vencida = ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued([$a, $b, $c], null);

        $this->assertSame(1, $con_vencida, 'Sólo b tiene la marca vencida.');
        $this->assertCount(2, $this->lotes_empujados());
        $segundo = $this->lotes_empujados()->last();
        $this->assertSame([$b], array_map('intval', $segundo->get_article_ids()));
        $this->assertNull($segundo->get_embedding_run_id(), 'Sin tanda, el lote viaja sin run_id.');
    }

    /**
     * CA2 (corte). Con el corte por credencial abierto devuelve 0, no empuja nada y NO marca (consulta el corte ANTES de
     * reclamar: si marcara, los artículos quedarían trabados 12 h sin ningún job).
     *
     * @group whatsapp
     * @test
     */
    public function test_con_corte_no_marca_ni_despacha()
    {
        $ids = $this->crear_articulos(3, 'Corte');
        $this->abrir_corte_a_mano();
        Queue::fake();

        $reclamados = ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued($ids, null);

        $this->assertSame(0, $reclamados, 'Con el corte abierto no se reclama nada.');
        Queue::assertNothingPushed();
        $this->assertSame(
            0,
            DB::table('articles')->whereIn('id', $ids)->whereNotNull('embedding_queued_at')->count(),
            'Ningún artículo queda marcado durante el corte.'
        );

        // Control en positivo: cerrado el corte, el mismo escenario sí reclama y despacha.
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        $this->assertSame(3, ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued($ids, null));
        Queue::assertPushed(GenerateArticleEmbeddingsBatchJob::class, 1);
    }

    /**
     * CA2 (despacho que lanza). Si el despacho del lote lanza, las marcas reclamadas se liberan y la excepción se re-lanza
     * (si no, a y c quedarían marcados 12 h sin ningún job). b, que ya tenía su marca de otro job, NO se toca.
     *
     * La cola se reemplaza por un doble cuya conexión lanza al empujar, así que el test no depende de por qué método del bus
     * despacha el servicio.
     *
     * @group whatsapp
     * @test
     */
    public function test_si_el_despacho_lanza_libera()
    {
        list($a, $b, $c) = $this->crear_articulos(3, 'Lanza');
        $this->poner_marca($b, 1);
        $marca_de_b = $this->marca_de($b);

        $conexion = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $conexion->shouldReceive('push', 'pushOn', 'later', 'laterOn')->andThrow(new \RuntimeException('cola caída (de prueba)'));
        $fabrica = \Mockery::mock(\Illuminate\Contracts\Queue\Factory::class);
        $fabrica->shouldReceive('connection')->andReturn($conexion);
        $this->app->instance('queue', $fabrica);

        $lanzo = null;

        try {
            ArticleEmbeddingDispatcher::dispatch_batch_if_not_queued([$a, $b, $c], null);
        } catch (\RuntimeException $excepcion) {
            $lanzo = $excepcion;
        }

        $this->assertNotNull($lanzo, 'La excepción del despacho se re-lanza.');
        $this->assertSame('cola caída (de prueba)', $lanzo->getMessage());
        $this->assertNull($this->marca_de($a), 'a se libera: no quedó ningún job.');
        $this->assertNull($this->marca_de($c), 'c se libera: no quedó ningún job.');
        $this->assertSame($marca_de_b, $this->marca_de($b), 'b conserva la marca del job que ya lo tenía.');
    }
}
