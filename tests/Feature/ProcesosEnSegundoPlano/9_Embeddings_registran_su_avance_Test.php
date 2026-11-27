<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\ArticleEmbeddingsBatchGenerated;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\EmbeddingsCredencialCorte;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\Support\AfterCommitComoEnProduccion;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA13, CA14 y CA16, parte API) — la preparación de la búsqueda inteligente se VE en "Procesos en
 * segundo plano".
 *
 * Reglas del diseño (§2.3 del plan):
 *  - La tanda de `articles:generate-embeddings` con 100 o más pendientes (un lote) abre UN proceso `embeddings_articulos`, en el comando,
 *    ANTES de despachar. Con menos de 100 no hay proceso (terminaría en un lote: sería ruido) y el toast sigue como hasta hoy. Las
 *    ediciones sueltas (observers) nunca crean proceso. Vale para todos los orígenes, incluida la prueba gratuita.
 *  - Cada lote escribe el avance como valor ABSOLUTO desde `embedding_runs.terminados`.
 *  - `FinalizeEmbeddingRun` lo cierra: `completado` con `{preparados, al_dia, con_error}` (+ `pendientes` si venció), o `fallo` si no se
 *    generó nada y hubo errores. La guarda anti-solapamiento del comando, al marcar `vencida` una tanda vieja, también cierra su proceso.
 *  - Con proceso visible el aviso `ArticleEmbeddingsBatchGenerated` lleva `con_proceso = true` (la SPA no muestra el toast duplicado).
 *
 * 🔴 SIN OPENAI REAL: `Http::fake` con un cuerpo que devuelve un vector por cada texto del pedido. Los lotes se corren a mano con
 * `handle()` (resuelto por el contenedor) a partir de lo que el comando empujó al `Queue::fake()`; sin worker.
 *
 * Con el trait `AfterCommitComoEnProduccion` la transacción base de la suite no cuenta, igual que en producción: el aviso de un proceso
 * sale en el acto (ver 8_Aviso_despues_del_commit_Test).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group procesos-en-segundo-plano
 */
class Embeddings_registran_su_avance_Test extends TestCase
{
    use DatabaseTransactions;
    use AfterCommitComoEnProduccion;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** Tipo del proceso (contrato con la SPA: `tipos.js`). */
    const TIPO = 'embeddings_articulos';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Pausa apagada, clave de prueba, avisos y cola fakeados, y el aviso de procesos "como en producción".
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ejecutar_after_commit_como_en_produccion();

        config(['services.openai.embeddings_generacion_pausada' => false]);
        config(['services.openai.api_key' => 'clave-de-prueba']);
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);
        config(['cache.default' => 'array']);
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio procesos embeddings',
            'company_name' => 'Ferreteria procesos embeddings',
            'email'        => 'embeddings-proceso-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class, ArticleEmbeddingsBatchGenerated::class]);
    }

    /**
     * Deja limpio el reloj y el memo del gate.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ArticleObserver::resetear_cache_gate();

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
     * Crea artículos pendientes SIN pasar por los observers (no se encola ni se marca nada).
     *
     * @param int $cantidad
     *
     * @return void
     */
    protected function crear_pendientes($cantidad)
    {
        Article::withoutEvents(function () use ($cantidad) {
            for ($i = 1; $i <= $cantidad; $i++) {
                Article::create([
                    'name'    => 'Pendiente ' . $i . ' ' . uniqid(),
                    'status'  => 'active',
                    'user_id' => $this->comercio->id,
                ]);
            }
        });
    }

    /**
     * OpenAI fingido: devuelve un vector por cada texto del pedido y un `usage` proporcional.
     *
     * @return void
     */
    protected function fingir_openai()
    {
        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake(['api.openai.com/*' => function ($request) {
            $entrada = (array) $request['input'];
            $data    = [];

            foreach (array_values($entrada) as $indice => $texto) {
                $data[] = ['object' => 'embedding', 'index' => $indice, 'embedding' => [0.5, 0.25, 0.125]];
            }

            return Http::response([
                'object' => 'list',
                'data'   => $data,
                'usage'  => ['prompt_tokens' => count($entrada) * 10, 'total_tokens' => count($entrada) * 10],
            ], 200);
        }]);
    }

    /**
     * Los jobs de lote que el comando empujó al fake, en orden.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function lotes_empujados()
    {
        return Queue::pushed(GenerateArticleEmbeddingsBatchJob::class)->values();
    }

    /**
     * Corre un job como lo hace el worker (el contenedor resuelve `handle()`).
     *
     * @param object $job
     *
     * @return void
     */
    protected function correr($job)
    {
        app()->call([$job, 'handle']);
    }

    /**
     * Procesos de embeddings del comercio, el más nuevo primero.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function procesos()
    {
        return BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', self::TIPO)->orderBy('id', 'desc')->get();
    }

    /**
     * El proceso de embeddings más nuevo, releído de la base.
     *
     * @return BackgroundProcess|null
     */
    protected function proceso()
    {
        return $this->procesos()->first();
    }

    /**
     * La tanda más nueva del comercio.
     *
     * @return EmbeddingRun|null
     */
    protected function tanda()
    {
        return EmbeddingRun::where('user_id', $this->comercio->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Crea una tanda con sus contadores.
     *
     * @param array $atributos Pisa los valores por defecto.
     *
     * @return EmbeddingRun
     */
    protected function crear_tanda(array $atributos = [])
    {
        return EmbeddingRun::create(array_merge([
            'user_id'    => $this->comercio->id,
            'origen'     => 'scheduler',
            'status'     => 'en_proceso',
            'total_jobs' => 0,
            'terminados' => 0,
            'generados'  => 0,
            'salteados'  => 0,
            'fallados'   => 0,
        ], $atributos));
    }

    /**
     * Abre a mano el proceso visible de una tanda, con la misma forma con la que lo abre el comando.
     *
     * @param EmbeddingRun $tanda
     * @param int          $total
     * @param int          $procesados
     *
     * @return BackgroundProcess
     */
    protected function abrir_proceso_de(EmbeddingRun $tanda, $total, $procesados)
    {
        return BackgroundProcessHelper::iniciar($this->comercio->id, self::TIPO, 'Preparando búsqueda inteligente de artículos', [
            'total'      => $total,
            'procesados' => $procesados,
            'unidad'     => 'artículos',
            'status'     => 'en_proceso',
            'referencia' => $tanda,
        ]);
    }

    /**
     * Retrasa el `created_at` de la tanda (el reloj del vencimiento) con el query builder.
     *
     * @param EmbeddingRun $tanda
     * @param int          $minutos
     *
     * @return EmbeddingRun
     */
    protected function envejecer_tanda(EmbeddingRun $tanda, $minutos)
    {
        DB::table('embedding_runs')->where('id', $tanda->id)->update(['created_at' => Carbon::now()->subMinutes($minutos)]);

        return EmbeddingRun::find($tanda->id);
    }

    // ─────────────────────────────── CA13 ───────────────────────────────

    /**
     * CA13 (tanda grande). Con 205 pendientes el comando crea UN proceso `embeddings_articulos` (título, unidad, total, referencia a la
     * tanda y detalle según el origen); los 3 lotes (3 pedidos a OpenAI, no 205) lo avanzan de forma absoluta (100 tras el primero, 205 al
     * final) y `FinalizeEmbeddingRun` lo deja `completado` con `{preparados: 205, al_dia: 0, con_error: 0}`. Se emitió
     * `BackgroundProcessUpdated`.
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_tanda_grande_crea_avanza_y_cierra()
    {
        $this->crear_pendientes(205);
        $this->dar_extension();

        Artisan::call('articles:generate-embeddings');

        $this->assertCount(1, $this->procesos(), 'El comando crea UN proceso visible para la tanda.');
        $proceso = $this->proceso();
        $tanda   = $this->tanda();

        $this->assertSame('Preparando búsqueda inteligente de artículos', $proceso->titulo);
        $this->assertSame('artículos', $proceso->unidad);
        $this->assertSame(205, (int) $proceso->total, 'El total son los artículos pendientes.');
        $this->assertSame(EmbeddingRun::class, $proceso->referencia_type, 'Apunta a la tanda.');
        $this->assertSame((int) $tanda->id, (int) $proceso->referencia_id);
        $this->assertSame('Artículos nuevos o modificados', $proceso->detalle, 'Origen scheduler.');
        $this->assertFalse($proceso->esta_terminado(), 'Mientras los lotes no corren, el proceso está activo.');
        $this->assertSame('En espera del procesador', $proceso->etapa);
        Event::assertDispatched(BackgroundProcessUpdated::class);

        $lotes = $this->lotes_empujados();
        $this->assertCount(3, $lotes, 'Precondición: 3 lotes.');

        $this->fingir_openai();

        // Primer lote: el avance es ABSOLUTO, leído de embedding_runs.terminados.
        $this->correr($lotes[0]);

        $proceso = $this->proceso();
        $this->assertSame(100, (int) $proceso->procesados, 'Tras el primer lote van 100 de 205.');
        $this->assertSame('Preparando artículos para la búsqueda', $proceso->etapa);

        $this->correr($lotes[1]);
        $this->correr($lotes[2]);

        Http::assertSentCount(3);
        $this->assertSame(205, (int) $this->proceso()->procesados);
        $this->assertSame(205, (int) EmbeddingRun::find($tanda->id)->terminados);

        // El finalizador cierra la tanda y su proceso.
        (new FinalizeEmbeddingRun($tanda->id))->handle();

        $proceso = $this->proceso();
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $resultado = $proceso->resultado();
        $this->assertSame(205, (int) $resultado['preparados']);
        $this->assertSame(0, (int) $resultado['al_dia']);
        $this->assertSame(0, (int) $resultado['con_error']);
        $this->assertArrayNotHasKey('pendientes', $resultado, 'No venció: no hay pendientes que informar.');
        Event::assertDispatched(BackgroundProcessUpdated::class);
    }

    /**
     * CA13 (tanda chica). Con 99 pendientes (menos que un lote) NO se crea ningún proceso, ni al despachar ni al cerrar; y el toast sigue:
     * el aviso `ArticleEmbeddingsBatchGenerated` sale igual.
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_tanda_chica_no_crea()
    {
        $this->crear_pendientes(99);
        $this->dar_extension();

        Artisan::call('articles:generate-embeddings');

        $this->assertCount(0, $this->procesos(), 'Menos de 100 pendientes: ningún proceso visible.');

        $lotes = $this->lotes_empujados();
        $this->assertCount(1, $lotes, 'Precondición: un solo lote de 99.');
        $this->assertCount(99, $lotes[0]->get_article_ids());

        $this->fingir_openai();
        $this->correr($lotes[0]);
        (new FinalizeEmbeddingRun($this->tanda()->id))->handle();

        $this->assertCount(0, $this->procesos(), 'Tampoco al terminar.');
        Event::assertDispatched(ArticleEmbeddingsBatchGenerated::class, function ($evento) {
            return $evento->generados === 99;
        });
    }

    /**
     * CA13 (observer). Una edición suelta de un artículo (observer: job de UN artículo, sin tanda) nunca crea un proceso, y el job que
     * la procesa tampoco.
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_observer_no_crea()
    {
        $this->dar_extension();

        $articulo = Article::create(['name' => 'Artículo editado a mano', 'status' => 'active', 'user_id' => $this->comercio->id]);

        $jobs = Queue::pushed(GenerateArticleEmbeddingJob::class);
        $this->assertCount(1, $jobs, 'Precondición: el observer encoló el job de un artículo.');

        $this->fingir_openai();
        $this->correr($jobs->first());

        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('embedding'), 'El artículo quedó vectorizado.');
        $this->assertCount(0, $this->procesos(), 'Una edición suelta no crea proceso visible.');
        $this->assertSame(0, BackgroundProcess::where('user_id', $this->comercio->id)->count());
    }

    /**
     * CA13 (prueba gratuita). El origen `prueba` también crea y cierra el proceso (es el caso de la importación de la prueba: miles de
     * artículos), con su detalle propio; y ahí sigue sin avisar por toast (como hoy).
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_prueba_crea_y_cierra()
    {
        $this->crear_pendientes(205);
        $this->dar_extension();

        Artisan::call('articles:generate-embeddings', ['--origen' => 'prueba']);

        $this->assertCount(1, $this->procesos(), 'El origen prueba también abre su proceso.');
        $proceso = $this->proceso();
        $this->assertSame(205, (int) $proceso->total);
        $this->assertSame('Catálogo de la prueba', $proceso->detalle);

        $this->fingir_openai();
        foreach ($this->lotes_empujados() as $lote) {
            $this->correr($lote);
        }

        (new FinalizeEmbeddingRun($this->tanda()->id))->handle();

        $proceso = $this->proceso();
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status, 'El cierre del proceso va ANTES del corte por origen prueba.');
        $this->assertSame(205, (int) $proceso->resultado()['preparados']);
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);
    }

    // ─────────────────────────────── CA14 ───────────────────────────────

    /**
     * CA14 (vencida). Una tanda que VENCE con `total_jobs=300, terminados=200, generados=150, salteados=50` cierra su proceso como
     * `completado` informando `pendientes = 100` (lo que faltó se completa en la pasada siguiente).
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_vencida_completa_con_pendientes()
    {
        $tanda = $this->crear_tanda(['total_jobs' => 300, 'terminados' => 200, 'generados' => 150, 'salteados' => 50]);
        $tanda = $this->envejecer_tanda($tanda, FinalizeEmbeddingRun::MINUTOS_PARA_VENCER + 5);
        $this->abrir_proceso_de($tanda, 300, 200);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        $this->assertSame('vencida', EmbeddingRun::find($tanda->id)->status);

        $proceso = $this->proceso();
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status, 'Vencer no es fallar: el proceso se completa con lo que haya.');
        $resultado = $proceso->resultado();
        $this->assertSame(150, (int) $resultado['preparados']);
        $this->assertSame(50, (int) $resultado['al_dia']);
        $this->assertSame(0, (int) $resultado['con_error']);
        $this->assertSame(100, (int) $resultado['pendientes'], 'Lo que faltó: total − terminados.');
        $this->assertSame('Lo que faltó se completa en la próxima pasada', $proceso->etapa);
    }

    /**
     * CA14 (todo fallado). Una tanda con `generados = 0` y `fallados = 205` deja el proceso en `fallo`, con el mensaje que le dice al
     * comerciante que el sistema reintenta solo.
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_todo_fallado_falla()
    {
        $tanda = $this->crear_tanda(['total_jobs' => 205, 'terminados' => 205, 'fallados' => 205]);
        $this->abrir_proceso_de($tanda, 205, 205);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        $proceso = $this->proceso();
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertSame('No se pudo preparar la búsqueda inteligente; el sistema lo reintenta solo.', $proceso->error_message);
        $this->assertSame(205, (int) $proceso->resultado()['con_error']);
    }

    /**
     * CA14 (guarda anti-solapamiento). Cuando el comando encuentra una tanda vieja abierta y la marca `vencida` para abrir la nueva, también
     * completa el proceso de esa tanda vieja (si no, quedaría "en curso" hasta que lo cierre el barrido de colgados 3 horas después).
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_tanda_vieja_cierra_su_proceso()
    {
        $vieja = $this->crear_tanda(['total_jobs' => 10, 'terminados' => 4, 'generados' => 4]);
        $vieja = $this->envejecer_tanda($vieja, FinalizeEmbeddingRun::MINUTOS_PARA_VENCER + 5);
        $this->abrir_proceso_de($vieja, 10, 4);

        $this->crear_pendientes(3);
        $this->dar_extension();

        Artisan::call('articles:generate-embeddings');

        $this->assertSame('vencida', EmbeddingRun::find($vieja->id)->status, 'Precondición: la guarda marca vencida a la tanda vieja.');

        $proceso_viejo = BackgroundProcess::where('referencia_type', EmbeddingRun::class)->where('referencia_id', $vieja->id)->first();
        $this->assertNotNull($proceso_viejo);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso_viejo->status, 'El proceso de la tanda vieja se completa.');
        $this->assertCount(1, $this->procesos(), 'La tanda nueva es chica (3): no abre otro proceso.');
    }

    // ─────────────────────────────── CA16 ───────────────────────────────

    /**
     * CA16 (API). El aviso `ArticleEmbeddingsBatchGenerated` lleva `con_proceso = true` cuando la tanda tiene proceso visible y `false` cuando
     * no; el resto del payload (`generados`, `origen`) no cambia.
     *
     * @group procesos-en-segundo-plano
     * @test
     */
    public function test_el_aviso_dice_si_hubo_proceso()
    {
        // Tanda con proceso visible.
        $con_proceso = $this->crear_tanda(['total_jobs' => 150, 'terminados' => 150, 'generados' => 150, 'origen' => 'importacion']);
        $this->abrir_proceso_de($con_proceso, 150, 150);

        (new FinalizeEmbeddingRun($con_proceso->id))->handle();

        Event::assertDispatched(ArticleEmbeddingsBatchGenerated::class, function ($evento) {
            $payload = $evento->broadcastWith();

            return $payload['generados'] === 150
                && $payload['origen'] === 'importacion'
                && array_key_exists('con_proceso', $payload)
                && $payload['con_proceso'] === true;
        });

        // Tanda sin proceso visible (chica).
        $sin_proceso = $this->crear_tanda(['total_jobs' => 5, 'terminados' => 5, 'generados' => 5, 'origen' => 'scheduler']);

        (new FinalizeEmbeddingRun($sin_proceso->id))->handle();

        Event::assertDispatched(ArticleEmbeddingsBatchGenerated::class, function ($evento) {
            $payload = $evento->broadcastWith();

            return $payload['generados'] === 5
                && $payload['origen'] === 'scheduler'
                && array_key_exists('con_proceso', $payload)
                && $payload['con_proceso'] === false;
        });
    }
}
