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
use App\Services\EmbeddingsCredencialCorte;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA10 y CA11) — la cola PROPIA de embeddings y los jobs viejos que quedaron en `default`.
 *
 * Todo lo de embeddings (`GenerateArticleEmbeddingsBatchJob`, el `GenerateArticleEmbeddingJob` de los observers y el
 * `FinalizeEmbeddingRun`) se empuja a `<cola base>_embeddings` para dejar de competir con la importación, las imágenes y los
 * exportes por el único worker de `default`. `QUEUE_EMBEDDINGS_WORKER=false` (`queue.embeddings_worker`) es la vuelta atrás: todo
 * vuelve a `default` (cola `null`).
 *
 * Y el caso de la actualización con backlog: los ~22 mil `GenerateArticleEmbeddingJob` ya serializados en `default` (con `queue`
 * null) los levanta un worker de `default` con el código NUEVO. Ahí NO llaman a OpenAI: liberan la marca y se re-despachan por
 * `ArticleEmbeddingDispatcher` a la cola propia, así `default` se vacía en minutos y la tanda sigue contando bien.
 *
 * 🔴 Los tests de CA11 usan la cola `database` y un `queue:work --once` de verdad (como EmbeddingsCorteCredencialTest): con `sync` o
 * `Queue::fake` el job nunca tendría un `$this->job` ni una cola de la que venir, y el caso no se mediría. `afterCommit`: el manager
 * de transacciones se reemplaza por uno vacío (si no, el despacho quedaría colgado de la transacción del test y nunca llegaría a
 * `jobs`). Sin OpenAI real: `Http::fake` y el freno de `tests/TestCase.php`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types ni enum.
 *
 * @group whatsapp
 */
class Cola_propia_y_jobs_viejos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Pausa apagada, clave de prueba, cola `database` con la cola base `default`, interruptor de la cola propia prendido (su default),
     * shared (`app.VPS=false`) y el manager de transacciones vacío (afterCommit).
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
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        config([
            'app.VPS'                          => false,
            'queue.scheduler_worker'           => true,
            'queue.default'                    => 'database',
            'queue.connections.database.queue' => 'default',
            'queue.embeddings_worker'          => true,
        ]);

        // afterCommit: sin esto los jobs quedan colgados de la transacción del test y nunca llegan a `jobs`.
        $this->app->instance('db.transactions', new \Illuminate\Database\DatabaseTransactionsManager());

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio cola propia',
            'company_name' => 'Ferreteria cola propia',
            'email'        => 'embeddings-lote-cola-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Deja limpio el memo estático del gate.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Le da la extensión `agentes_ia` al comercio (sólo una vez) y olvida el memo del gate.
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
     * Crea un artículo pendiente SIN pasar por los observers (no se encola ni se marca): lo que le queda al comando por despachar.
     *
     * @param string $nombre
     *
     * @return Article
     */
    protected function articulo_sin_observers($nombre)
    {
        return Article::withoutEvents(function () use ($nombre) {
            return Article::create([
                'name'    => $nombre,
                'status'  => 'active',
                'user_id' => $this->comercio->id,
            ]);
        });
    }

    /**
     * Fábrica HTTP limpia con la respuesta indicada para OpenAI.
     *
     * @param mixed $respuesta
     *
     * @return void
     */
    protected function openai_responde($respuesta)
    {
        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake(['api.openai.com/*' => $respuesta]);
    }

    /**
     * Respuesta de éxito de OpenAI con UN vector (index 0), válida tanto para el job de un artículo como para un lote de uno.
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    protected function respuesta_de_un_vector()
    {
        return Http::response([
            'data'  => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.5, 0.25, 0.125]]],
            'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
        ], 200);
    }

    /**
     * Corre UN job de la cola `$cola` con un worker real (`queue:work --once`).
     *
     * `--memory 4096`: con el default (128 MB) PHPUnit supera el límite y el worker se detiene.
     *
     * @param string $cola
     *
     * @return void
     */
    protected function correr_un_job_de($cola)
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once'     => true,
            '--queue'    => $cola,
            '--sleep'    => 0,
            '--memory'   => 4096,
        ]);
    }

    /**
     * Cantidad de filas pendientes en `jobs` de una cola.
     *
     * @param string $cola
     *
     * @return int
     */
    protected function pendientes_en($cola)
    {
        return DB::table('jobs')->where('queue', $cola)->count();
    }

    /**
     * Deserializa el comando de una fila de `jobs`.
     *
     * @param object $fila
     *
     * @return object El job tal como lo vería el worker.
     */
    protected function comando_de($fila)
    {
        $payload = json_decode($fila->payload, true);

        return unserialize($payload['data']['command']);
    }

    /**
     * Despacha un `GenerateArticleEmbeddingJob` al estilo VIEJO (cola `null`: el interruptor apagado) para que quede en `default`
     * tal como lo dejó el código anterior, y después deja el interruptor en `$interruptor_al_correr`.
     *
     * @param Article $articulo
     * @param int     $tanda_id
     * @param bool    $interruptor_al_correr
     *
     * @return void
     */
    protected function dejar_un_job_viejo_en_default(Article $articulo, $tanda_id, $interruptor_al_correr)
    {
        config(['queue.embeddings_worker' => false]);

        // La marca la pone el despachador antes de encolar; se la deja puesta como la dejaba el código viejo.
        DB::table('articles')->where('id', $articulo->id)->update(['embedding_queued_at' => now()]);

        GenerateArticleEmbeddingJob::dispatch((int) $articulo->id, (int) $tanda_id);

        config(['queue.embeddings_worker' => $interruptor_al_correr]);

        $this->assertSame(1, $this->pendientes_en('default'), 'Precondición: el job viejo quedó en la cola default.');
        $this->assertNull($this->comando_de(DB::table('jobs')->where('queue', 'default')->first())->queue, 'Precondición: el job viejo se serializó con queue null.');
    }

    /**
     * Para cada job de las tres clases empujado al fake, aserta la cola esperada.
     *
     * @param string|null $esperada
     * @param string      $contexto
     *
     * @return void
     */
    protected function assert_cola_de_todo_lo_de_embeddings($esperada, $contexto)
    {
        foreach ([GenerateArticleEmbeddingsBatchJob::class, GenerateArticleEmbeddingJob::class, FinalizeEmbeddingRun::class] as $clase) {
            $empujados = Queue::pushed($clase);

            $this->assertGreaterThanOrEqual(1, $empujados->count(), $contexto . ': se empujó al menos un ' . $clase . '.');

            foreach ($empujados as $job) {
                $this->assertSame($esperada, $job->queue, $contexto . ': ' . $clase . ' va a la cola ' . var_export($esperada, true) . '.');
            }
        }
    }

    /**
     * Arma el escenario de despacho completo: el observer encola el job de UN artículo editado y el comando encola lote + finalizador
     * para los pendientes. Con `Queue::fake()` queda registrado todo lo que se empujó.
     *
     * @return void
     */
    protected function despachar_todo_lo_de_embeddings()
    {
        $this->articulo_sin_observers('Pendiente uno ' . uniqid());
        $this->articulo_sin_observers('Pendiente dos ' . uniqid());

        Queue::fake();

        // Observer: un artículo nuevo con la extensión activa encola el job de UN artículo.
        Article::create(['name' => 'Editado ' . uniqid(), 'status' => 'active', 'user_id' => $this->comercio->id]);

        // Comando: lote(s) y finalizador para los pendientes.
        Artisan::call('articles:generate-embeddings');
    }

    // ─────────────────────────────── CA10 ───────────────────────────────

    /**
     * CA10 (cola propia). Con el interruptor en su default (prendido), en shared Y en VPS, el job de lote, el job de un artículo (el del
     * observer) y el finalizador se empujan a `default_embeddings`; con `QUEUE_NAME=ferre` a `ferre_embeddings`. Nada de embeddings va
     * a `default`.
     *
     * @group whatsapp
     * @test
     */
    public function test_todo_embeddings_va_a_su_cola()
    {
        $this->dar_extension();

        $escenarios = [
            'shared, QUEUE_NAME sin setear' => [false, 'default', 'default_embeddings'],
            'VPS, QUEUE_NAME sin setear'    => [true,  'default', 'default_embeddings'],
            'shared, QUEUE_NAME=ferre'      => [false, 'ferre',   'ferre_embeddings'],
            'VPS, QUEUE_NAME=ferre'         => [true,  'ferre',   'ferre_embeddings'],
        ];

        foreach ($escenarios as $nombre => $escenario) {
            list($vps, $cola_base, $esperada) = $escenario;

            config([
                'app.VPS'                          => $vps,
                'queue.scheduler_worker'           => ! $vps,
                'queue.connections.database.queue' => $cola_base,
                'queue.embeddings_worker'          => true,
            ]);

            // Un escenario por vez: se limpia el estado del anterior (tandas y marcas).
            DB::table('embedding_runs')->where('user_id', $this->comercio->id)->delete();

            $this->despachar_todo_lo_de_embeddings();

            $this->assert_cola_de_todo_lo_de_embeddings($esperada, $nombre);
            Queue::assertPushedOn($esperada, GenerateArticleEmbeddingsBatchJob::class);
            Queue::assertPushedOn($esperada, GenerateArticleEmbeddingJob::class);
            Queue::assertPushedOn($esperada, FinalizeEmbeddingRun::class);

            // Nada en la cola base: ni siquiera con el nombre derivado igual a la base.
            foreach ([GenerateArticleEmbeddingsBatchJob::class, GenerateArticleEmbeddingJob::class, FinalizeEmbeddingRun::class] as $clase) {
                Queue::assertNotPushed($clase, function ($job) use ($cola_base) {
                    return $job->queue === null || $job->queue === $cola_base;
                });
            }

            // Cada artículo se vuelve a dejar pendiente para el escenario que sigue.
            DB::table('articles')->where('user_id', $this->comercio->id)->update(['embedding_queued_at' => null, 'embedding_generated_at' => null]);
        }
    }

    /**
     * CA10 (vuelta atrás). Con `queue.embeddings_worker = false` los tres vuelven a `default` (cola `null`: la que use la conexión).
     *
     * @group whatsapp
     * @test
     */
    public function test_interruptor_apagado_vuelve_a_default()
    {
        $this->dar_extension();

        config(['queue.embeddings_worker' => false]);

        $this->despachar_todo_lo_de_embeddings();

        $this->assert_cola_de_todo_lo_de_embeddings(null, 'Interruptor apagado');

        // Control en positivo: con el interruptor prendido el MISMO escenario sí usa la cola propia.
        config(['queue.embeddings_worker' => true]);
        DB::table('articles')->where('user_id', $this->comercio->id)->update(['embedding_queued_at' => null, 'embedding_generated_at' => null]);
        DB::table('embedding_runs')->where('user_id', $this->comercio->id)->delete();

        $this->despachar_todo_lo_de_embeddings();

        $this->assert_cola_de_todo_lo_de_embeddings('default_embeddings', 'Control con el interruptor prendido');
    }

    // ─────────────────────────────── CA11 ───────────────────────────────

    /**
     * CA11 (se muda). Un `GenerateArticleEmbeddingJob` viejo (serializado con `queue` null, en `default`) que levanta un worker de
     * `default` con el interruptor PRENDIDO: 0 pedidos a OpenAI, `default` queda vacía y en `default_embeddings` queda UN job del mismo
     * artículo con la misma tanda (con su marca puesta); la tanda todavía no contó nada. Procesado ese job, `generados = 1`.
     *
     * @group whatsapp
     * @test
     */
    public function test_un_job_viejo_en_default_se_muda_sin_llamar_a_openai()
    {
        $articulo = $this->articulo_sin_observers('Artículo del backlog viejo');
        $tanda = EmbeddingRun::create([
            'user_id' => $this->comercio->id, 'origen' => 'scheduler', 'status' => 'en_proceso',
            'total_jobs' => 1, 'terminados' => 0, 'generados' => 0, 'salteados' => 0, 'fallados' => 0,
        ]);

        $this->dejar_un_job_viejo_en_default($articulo, $tanda->id, true);

        $this->openai_responde($this->respuesta_de_un_vector());

        // Un worker de `default` levanta el job viejo.
        $this->correr_un_job_de('default');

        Http::assertNothingSent();
        $this->assertSame(0, $this->pendientes_en('default'), 'default queda vacía: el job viejo se mudó, no se procesó.');
        $this->assertSame(1, $this->pendientes_en('default_embeddings'), 'En la cola propia queda UN job.');

        $fila = DB::table('jobs')->where('queue', 'default_embeddings')->first();
        $payload = json_decode($fila->payload, true);
        $this->assertSame(GenerateArticleEmbeddingJob::class, $payload['displayName'], 'Es el job de un artículo, re-despachado.');
        $mudado = $this->comando_de($fila);
        $this->assertSame((int) $articulo->id, $mudado->get_article_id(), 'Es el mismo artículo.');
        $this->assertSame((int) $tanda->id, $mudado->get_embedding_run_id(), 'Con la misma tanda.');
        $this->assertNotNull(
            DB::table('articles')->where('id', $articulo->id)->value('embedding_queued_at'),
            'El re-despacho pasó por el despachador: el artículo queda marcado como encolado.'
        );
        $this->assertSame(0, (int) EmbeddingRun::find($tanda->id)->terminados, 'La tanda todavía no contó nada: el artículo sigue pendiente (no se cuenta dos veces).');

        // Un worker de la cola propia lo procesa de verdad.
        $this->correr_un_job_de('default_embeddings');

        Http::assertSentCount(1);
        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(1, (int) $tanda->generados, 'Procesado en la cola propia, la tanda cuenta 1 generado.');
        $this->assertSame(1, (int) $tanda->terminados);
        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('embedding'), 'Y el artículo quedó vectorizado.');
        $this->assertSame(0, DB::table('jobs')->count(), 'No queda ningún job pendiente.');
    }

    /**
     * CA11 (interruptor apagado). Con el interruptor apagado el job viejo se procesa donde está: UN pedido a OpenAI, `generados = 1` y
     * nada se muda a `default_embeddings`.
     *
     * @group whatsapp
     * @test
     */
    public function test_con_interruptor_apagado_el_viejo_se_procesa()
    {
        $articulo = $this->articulo_sin_observers('Artículo viejo con la cola apagada');
        $tanda = EmbeddingRun::create([
            'user_id' => $this->comercio->id, 'origen' => 'scheduler', 'status' => 'en_proceso',
            'total_jobs' => 1, 'terminados' => 0, 'generados' => 0, 'salteados' => 0, 'fallados' => 0,
        ]);

        $this->dejar_un_job_viejo_en_default($articulo, $tanda->id, false);

        $this->openai_responde($this->respuesta_de_un_vector());

        $this->correr_un_job_de('default');

        Http::assertSentCount(1);
        $this->assertSame(0, $this->pendientes_en('default'));
        $this->assertSame(0, $this->pendientes_en('default_embeddings'), 'Con la cola apagada nada se muda.');
        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(1, (int) $tanda->generados);
        $this->assertSame(1, (int) $tanda->terminados);
    }

    /**
     * CA11 (llamado directo). Un `handle()` llamado directo (sin `$this->job`: así lo corren los tests de 16, 34, CorteCredencial y Cupo)
     * procesa normal aunque el interruptor esté prendido: no hay bucle posible y la semántica por artículo queda fija.
     *
     * @group whatsapp
     * @test
     */
    public function test_llamado_directo_procesa_normal_aunque_el_interruptor_este_prendido()
    {
        $articulo = $this->articulo_sin_observers('Artículo llamado directo');
        $tanda = EmbeddingRun::create([
            'user_id' => $this->comercio->id, 'origen' => 'scheduler', 'status' => 'en_proceso',
            'total_jobs' => 1, 'terminados' => 0, 'generados' => 0, 'salteados' => 0, 'fallados' => 0,
        ]);

        $this->openai_responde($this->respuesta_de_un_vector());

        app()->call([new GenerateArticleEmbeddingJob((int) $articulo->id, (int) $tanda->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('jobs')->count(), 'Llamado directo no re-despacha nada.');
        $this->assertSame(1, (int) EmbeddingRun::find($tanda->id)->generados);
    }
}
