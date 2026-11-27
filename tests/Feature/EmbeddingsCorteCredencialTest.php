<?php

namespace Tests\Feature;

use App\Events\ArticleEmbeddingsBatchGenerated;
use App\Exceptions\EmbeddingsCredencialRechazadaException;
use App\Http\Controllers\Helpers\article\ArticleEmbeddingsEstadoHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingDispatcher;
use App\Services\ArticleEmbeddingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Misión embeddings-corte-credencial-20261123 (S3 de colas-prevencion-fase2) — una clave de OpenAI
 * RECHAZADA corta la generación de embeddings al primer rechazo, en vez de fallar miles de veces.
 *
 * El caso real: la ola de 15.512 (Ferretotal) + 12.276 (Pesca) `failed_jobs` "Incorrect API key
 * provided". Cada artículo pendiente se despachaba una vez por pasada (cada 30 min), llamaba a OpenAI
 * tres veces (`$tries = 3`) y dejaba una fila en `failed_jobs`. La clave ya se corrigió (11/11); lo que
 * se fija acá es el hueco de código: un error PERMANENTE de credencial no se reintenta a ciegas.
 *
 * 🔴 ESTOS TESTS NO USAN LA COLA `sync` NI `Queue::fake` EN LOS CASOS DE REINTENTO. Con `sync` el job
 * corre adentro del `dispatch`, sin `$tries` ni `$backoff` ni filas en `failed_jobs`: un CA de
 * reintentos medido así pasaría en verde sin medir nada (antecedente: 2_Aviso_en_tiempo_real_sin_cola).
 * Por eso CA3.1/3.1b/3.3/3.3b corren `queue:work` de verdad sobre la cola `database`, con el reloj
 * gobernado por `Carbon::setTestNow` (el backoff de 60 s y el redespacho de 15 s del finalizador se
 * "esperan" avanzando el reloj, no durmiendo).
 *
 * 🔴 `afterCommit`: `GenerateArticleEmbeddingJob` fija `afterCommit = true`. Con `DatabaseTransactions`
 * los 50 jobs quedarían colgados de la transacción del test y nunca llegarían a `jobs` (y el finalizador,
 * que no tiene `afterCommit`, sí): el centinela por clase lo delata ANTES de cualquier aserción de
 * negocio. Para que no pase, `setUp()` reemplaza `db.transactions` por un manager vacío.
 *
 * Reloj: el cache del corte es `array` (se fija en `setUp()`) y su TTL se mide con `Carbon::now()`,
 * así que `Carbon::setTestNow` también vence el corte.
 *
 * Nota de fixture (declarada, no es un ajuste de aserción): CA3.7 pide dos pasadas "a +30 y +60 min
 * (antes del TTL)". Con TTL de 60 min, +60 es exactamente el borde de vencimiento; se usan +30 y +59
 * para medir lo que el CA quiere medir ("antes del TTL") sin depender de si el borde es inclusivo.
 */
class EmbeddingsCorteCredencialTest extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** Clave del corte en el cache por defecto (contrato del PLAN; también la usa `cache:forget`). */
    const CLAVE_CORTE = 'embeddings_credencial_rechazada';

    /** Cantidad de artículos pendientes de los escenarios de la tanda. */
    const CANTIDAD = 50;

    /** @var User */
    protected $comercio;

    /** @var Carbon Instante cero del escenario (reloj congelado). */
    protected $t0;

    /** @var array Diagnóstico por ronda de `queue:work`, para los mensajes de falla. */
    protected $bitacora = [];

    /** @var TestHandler Captura lo que se loguea (cualquier canal). */
    protected $log_handler;

    protected function setUp(): void
    {
        parent::setUp();

        // La base de los tests arranca con los embeddings pausados (tests/TestCase.php); los
        // escenarios crean los artículos con la pausa puesta (el observer no despacha) y la sueltan
        // recién cuando miden.
        config(['services.openai.embeddings_generacion_pausada' => true]);
        config(['services.openai.api_key' => 'clave-de-prueba-que-no-debe-aparecer-en-logs']);
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        // El corte vive en el cache por defecto: `array` para que ningún test herede el de otro.
        config(['cache.default' => 'array']);
        Cache::forget(self::CLAVE_CORTE);

        // Cola `database` real (no `sync`, no `Queue::fake`) antes del primer despacho.
        config(['queue.default' => 'database']);

        // afterCommit: sin esto los jobs quedan colgados de la transacción del test (ver docblock).
        $this->app->instance('db.transactions', new \Illuminate\Database\DatabaseTransactionsManager());

        $this->capturar_logs();

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio corte credencial',
            'company_name' => 'Ferreteria corte credencial',
            'email'        => 'embeddings-corte-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando resuelve el dueño de la instancia por acá, igual que el scheduler.
        config(['app.USER_ID' => $this->comercio->id]);

        $this->t0 = Carbon::parse('2026-11-23 10:00:00');
        $this->bitacora = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Redirige el canal `daily` (y el default) a un handler en memoria, para poder contar y leer
     * lo que se loguea sin depender de archivos.
     *
     * @return void
     */
    protected function capturar_logs()
    {
        $this->log_handler = new TestHandler();
        $handler = $this->log_handler;

        // Driver propio: un canal `monolog` con `handler` lo construiría el contenedor de nuevo (con
        // parámetros no devuelve la instancia registrada) y no se podría leer.
        Log::extend('captura_test', function () use ($handler) {
            return new \Monolog\Logger('captura', [$handler]);
        });

        config([
            'logging.default'        => 'daily',
            'logging.channels.daily' => ['driver' => 'captura_test'],
        ]);

        Log::forgetChannel('daily');
        Log::forgetChannel('stack');
    }

    /**
     * Le da la extensión al comercio y olvida el memo del gate.
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
     * Crea un artículo pendiente (sin vector). Se llama con la pausa puesta: el observer no despacha.
     *
     * @param string $nombre
     *
     * @return Article
     */
    protected function articulo($nombre)
    {
        return Article::create([
            'name'    => $nombre,
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Suelta la pausa global: a partir de acá el observer y el comando despachan.
     *
     * @return void
     */
    protected function soltar_la_pausa()
    {
        config(['services.openai.embeddings_generacion_pausada' => false]);
        ArticleObserver::resetear_cache_gate();
    }

    /**
     * Cuerpo de error de OpenAI (la forma que lee `generate_embedding`: JSON con `error.message`).
     *
     * @param string      $mensaje
     * @param string      $codigo
     * @param string|null $tipo
     *
     * @return array
     */
    protected function cuerpo_openai($mensaje, $codigo, $tipo = 'invalid_request_error')
    {
        return ['error' => ['message' => $mensaje, 'type' => $tipo, 'code' => $codigo]];
    }

    /**
     * Cuerpo de un 401 de OpenAI, con un "eco" de la clave como el real (para chequear que no se loguea).
     *
     * @return array
     */
    protected function cuerpo_401()
    {
        return $this->cuerpo_openai(
            'Incorrect API key provided: sk-proj-ABCDEFGHIJKLMNOPQRSTUVWXYZ123456. You can find your API key at https://platform.openai.com/account/api-keys.',
            'invalid_api_key'
        );
    }

    /**
     * Cuerpo de éxito de OpenAI con un vector de 3 dimensiones.
     *
     * @return array
     */
    protected function cuerpo_exito()
    {
        return ['data' => [['embedding' => [0.5, 0.25, 0.125]]], 'usage' => ['prompt_tokens' => 7]];
    }

    /**
     * Fábrica HTTP limpia con UNA respuesta para OpenAI. Los stubs de `Http::fake()` se acumulan y
     * gana el primero, así que cada cambio de respuesta arranca de una fábrica nueva (con el freno de
     * internet puesto: `HttpFactorySinSalida`, ver tests/TestCase.php).
     *
     * @param mixed $respuesta Lo que devuelve `Http::response()`, o un callable.
     *
     * @return void
     */
    protected function openai_responde($respuesta)
    {
        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake(['api.openai.com/*' => $respuesta]);
    }

    /**
     * Cantidad de pedidos que salieron hacia OpenAI desde la última `openai_responde()`.
     *
     * @return int
     */
    protected function pedidos_enviados()
    {
        return Http::recorded()->count();
    }

    /**
     * Filas de la tabla `jobs` contadas por clase (displayName del payload).
     *
     * @return array [clase => cantidad]
     */
    protected function jobs_por_clase()
    {
        $conteo = [];

        foreach (DB::table('jobs')->get() as $fila) {
            $payload = json_decode($fila->payload, true);
            $clase   = (string) ($payload['displayName'] ?? '?');
            $conteo[$clase] = ($conteo[$clase] ?? 0) + 1;
        }

        return $conteo;
    }

    /**
     * Cantidad de filas de `jobs` de una clase.
     *
     * @param string $clase
     *
     * @return int
     */
    protected function jobs_de($clase)
    {
        $conteo = $this->jobs_por_clase();

        return isset($conteo[$clase]) ? $conteo[$clase] : 0;
    }

    /**
     * Mueve el reloj y corre UNA ronda de `queue:work` sobre la cola `database` hasta que no quede
     * ningún job disponible. Anota requests, filas por clase y `failed_jobs` en la bitácora.
     *
     * `--memory 4096`: con el default (128 MB) PHPUnit supera el límite y el worker se detiene tras el
     * primer job (Worker.php:298).
     *
     * @param Carbon $momento Instante del reloj para esta ronda.
     *
     * @return void
     */
    protected function ronda(Carbon $momento)
    {
        Carbon::setTestNow($momento);

        Artisan::call('queue:work', [
            'connection'       => 'database',
            '--stop-when-empty' => true,
            '--sleep'          => 0,
            '--memory'         => 4096,
        ]);

        $this->bitacora[] = [
            'ronda'    => count($this->bitacora) + 1,
            'reloj'    => $momento->toDateTimeString(),
            'requests' => $this->pedidos_enviados(),
            'jobs'     => $this->jobs_por_clase(),
            'failed'   => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * Las cuatro rondas del CA3.1: R1 en T0, R2 en T0+61, R3 en T0+122, drenaje R4 en T0+183. Cada
     * +61 s vence el `$backoff = 60` y el redespacho de 15 s del finalizador.
     *
     * @param int $desde Índice de la primera ronda a correr (0 = R1; 1 = arranca en R2).
     *
     * @return void
     */
    protected function cuatro_rondas($desde = 0)
    {
        for ($i = $desde; $i < 4; $i++) {
            $this->ronda($this->t0->copy()->addSeconds(61 * $i));
        }
    }

    /**
     * Texto de diagnóstico con la bitácora, para el mensaje de las aserciones.
     *
     * @return string
     */
    protected function diag()
    {
        return 'Bitácora por ronda: ' . json_encode($this->bitacora);
    }

    /**
     * Prepara el escenario común de CA3.1/3.1b/3.3: 50 artículos pendientes, OpenAI → 401, el comando
     * corre UNA vez y deja en `jobs` 50 Generate + 1 Finalize (centinela por clase, que pasa hoy y
     * mañana: si falla, el fixture está mal y se frena, no se ajusta).
     *
     * @return array Ids de los 50 artículos.
     */
    protected function escenario_cincuenta_con_401()
    {
        Carbon::setTestNow($this->t0);
        $this->dar_extension();

        for ($i = 1; $i <= self::CANTIDAD; $i++) {
            $this->articulo('Articulo corte ' . $i);
        }

        $ids = Article::where('user_id', $this->comercio->id)->orderBy('id')->pluck('id')->all();
        $this->assertCount(self::CANTIDAD, $ids);

        $this->soltar_la_pausa();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);
        $this->openai_responde(Http::response($this->cuerpo_401(), 401));

        $this->assertSame(0, Artisan::call('articles:generate-embeddings'));

        // ── Centinela por clase: lo que dejó el comando, ANTES de procesar nada ──
        $this->assertSame(
            self::CANTIDAD,
            $this->jobs_de(GenerateArticleEmbeddingJob::class),
            'CENTINELA: el comando debería dejar 50 GenerateArticleEmbeddingJob en `jobs` (cola database real). '
            . 'Con 0 y 1 Finalize el afterCommit quedó colgado; con total 0 la cola es sync: el fixture está mal, frenar. '
            . json_encode($this->jobs_por_clase())
        );
        $this->assertSame(1, $this->jobs_de(FinalizeEmbeddingRun::class), 'CENTINELA: debería haber 1 FinalizeEmbeddingRun.');
        $this->assertSame(self::CANTIDAD + 1, DB::table('jobs')->count(), 'CENTINELA: 51 filas en total.');

        $finalizador = null;
        foreach (DB::table('jobs')->get() as $fila) {
            $payload = json_decode($fila->payload, true);
            if (($payload['displayName'] ?? '') === FinalizeEmbeddingRun::class) {
                $finalizador = $fila;
            }
        }
        $this->assertSame(
            $this->t0->copy()->addSeconds(10)->getTimestamp(),
            (int) $finalizador->available_at,
            'CENTINELA: el finalizador queda con available_at = T0 + 10 s.'
        );
        $this->assertSame(0, $this->pedidos_enviados(), 'CENTINELA: el comando no llama a OpenAI.');

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($tanda, 'CENTINELA: el comando abrió una tanda.');
        $this->assertSame('en_proceso', $tanda->status);
        $this->assertSame(self::CANTIDAD, (int) $tanda->total_jobs);

        return $ids;
    }

    /**
     * Estado final esperado tras cortar con la clave rechazada y drenar la cola (CA3.1 "mañana").
     * El orden es a propósito: primero las dos que fallan hoy por reintento ciego (150 requests y
     * 50 filas en `failed_jobs`), después el resto.
     *
     * @param array $ids Ids de los 50 artículos.
     *
     * @return void
     */
    protected function afirmar_estado_cortado(array $ids)
    {
        $this->assertSame(1, $this->pedidos_enviados(), 'Con la clave rechazada sale UN solo pedido a OpenAI (el que abre el corte). ' . $this->diag());
        $this->assertSame(0, DB::table('failed_jobs')->count(), '0 filas en failed_jobs: el rechazo de credencial no se re-lanza. ' . $this->diag());
        $this->assertSame(0, DB::table('jobs')->count(), 'La cola `database` queda vacía (ni reintentos ni finalizador pendiente). ' . json_encode($this->jobs_por_clase()));
        $this->assertSame(0, $this->jobs_de(GenerateArticleEmbeddingJob::class));
        $this->assertSame(0, $this->jobs_de(FinalizeEmbeddingRun::class));

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->orderBy('id')->first();
        $this->assertSame('completada', $tanda->status, 'El finalizador cierra la tanda. ' . $this->diag());
        $this->assertSame(self::CANTIDAD, (int) $tanda->fallados);
        $this->assertSame(0, (int) $tanda->generados);
        $this->assertSame(self::CANTIDAD, (int) $tanda->terminados);

        $filas = DB::table('articles')->whereIn('id', $ids)->get();
        $this->assertCount(self::CANTIDAD, $filas);
        foreach ($filas as $fila) {
            $this->assertNull($fila->embedding_generated_at, 'El artículo queda pendiente (sin embedding_generated_at).');
            $this->assertNull($fila->embedding_queued_at, 'La marca de encolado queda libre: no se re-marca para un reintento que no habrá.');
        }

        // CA3.7: la tanda fallida no genera aviso al comerciante.
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);
    }

    /**
     * Abre el corte "a mano" con la forma del contrato ({desde, status, mensaje}) y el TTL por
     * defecto de 60 min. Los casos que miden lo que hace el sistema CON el corte abierto lo usan; la
     * apertura real por un 401 se mide en CA3.1/3.4/3.4b/3.5c.
     *
     * @param int $status
     *
     * @return void
     */
    protected function abrir_corte_a_mano($status = 401)
    {
        Cache::put(self::CLAVE_CORTE, [
            'desde'   => Carbon::now()->toIso8601String(),
            'status'  => $status,
            'mensaje' => 'Incorrect API key provided',
        ], 3600);
    }

    /**
     * Llama a `generate_embedding` sin dueño ni proceso (sin cupo ni consumo) y devuelve lo que lance.
     *
     * @return \Throwable|null null si no lanzó.
     */
    protected function llamar_generate_embedding()
    {
        try {
            app(ArticleEmbeddingService::class)->generate_embedding('Martillo de goma');
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    // ───────────────────────────── criterios ──────────────────────────────

    /**
     * CA3.1 — Corta al primer 401, marcas limpias, con cola `database` real: 50 artículos pendientes,
     * OpenAI → 401, cuatro rondas de worker. Hoy: 150 requests (3 por artículo) y 50 `failed_jobs`.
     *
     * @test
     */
    public function ca3_1_corta_al_primer_401_con_marcas_limpias()
    {
        $ids = $this->escenario_cincuenta_con_401();

        $this->cuatro_rondas();

        $this->afirmar_estado_cortado($ids);
    }

    /**
     * CA3.1b — Jobs restantes al abrirse el corte: el corte se abre en el primer job y los 49 ya
     * encolados terminan sin HTTP. Después de R1 ya no queda ningún Generate (hoy: 50 reintentos a T0+60,
     * 50 requests y 50 marcas re-puestas).
     *
     * @test
     */
    public function ca3_1b_los_jobs_restantes_terminan_sin_http_al_abrirse_el_corte()
    {
        $ids = $this->escenario_cincuenta_con_401();

        $this->ronda($this->t0);

        $this->assertSame(1, $this->pedidos_enviados(), 'Tras R1 sólo salió el pedido que abrió el corte. ' . $this->diag());
        $this->assertSame(0, $this->jobs_de(GenerateArticleEmbeddingJob::class), 'Tras R1 no queda ningún Generate (ni reintentos). ' . $this->diag());
        $this->assertSame(1, $this->jobs_de(FinalizeEmbeddingRun::class), 'Tras R1 queda sólo el finalizador. ' . $this->diag());
        $this->assertSame(
            0,
            DB::table('articles')->whereIn('id', $ids)->whereNotNull('embedding_queued_at')->count(),
            'Ningún artículo queda con embedding_queued_at no nulo. ' . $this->diag()
        );

        // Drenaje: igual que CA3.1.
        $this->cuatro_rondas(1);
        $this->afirmar_estado_cortado($ids);
    }

    /**
     * CA3.1c — El catch específico del job: ante la credencial rechazada NO re-lanza, NO re-marca
     * (`mark_as_queued`), no escribe `embedding_generated_at` ni `embedding_source_hash` y suma a
     * `fallados` de la tanda. Hoy re-lanza y re-marca porque quedan intentos.
     *
     * @test
     */
    public function ca3_1c_el_job_ante_credencial_rechazada_no_relanza_ni_remarca()
    {
        $articulo = $this->articulo('Articulo catch especifico');
        $this->soltar_la_pausa();

        $tanda = EmbeddingRun::create([
            'user_id' => $this->comercio->id, 'origen' => 'scheduler', 'status' => 'en_proceso', 'total_jobs' => 1,
        ]);

        $this->openai_responde(Http::response($this->cuerpo_401(), 401));

        $lanzo = null;
        try {
            (new GenerateArticleEmbeddingJob($articulo->id, $tanda->id))->handle(app(ArticleEmbeddingService::class));
        } catch (\Throwable $e) {
            $lanzo = $e;
        }

        $this->assertNull($lanzo, 'El job no re-lanza ante credencial rechazada (0 reintentos, 0 failed_jobs). Lanzó: ' . ($lanzo ? get_class($lanzo) . ': ' . $lanzo->getMessage() : ''));

        $fila = DB::table('articles')->where('id', $articulo->id)->first();
        $this->assertNull($fila->embedding_queued_at, 'No se llama a mark_as_queued: la marca queda libre.');
        $this->assertNull($fila->embedding_generated_at, 'No se escribe embedding_generated_at: queda pendiente.');
        $this->assertNull($fila->embedding_source_hash, 'No se escribe embedding_source_hash: queda pendiente.');

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(1, (int) $tanda->fallados);
        $this->assertSame(1, (int) $tanda->terminados);
        $this->assertSame(0, (int) $tanda->generados);
    }

    /**
     * CA3.2 — Corte abierto no despacha: el comando sale con aviso, sin tanda y sin jobs; editar un
     * artículo (observer) tampoco despacha y su `embedding_queued_at` queda nulo.
     *
     * @test
     */
    public function ca3_2_con_el_corte_abierto_no_se_despacha_nada()
    {
        $this->dar_extension();
        $pendiente = $this->articulo('Articulo pendiente durante el corte');
        $editable = $this->articulo('Articulo editable durante el corte');
        $this->soltar_la_pausa();

        $this->abrir_corte_a_mano(401);
        Queue::fake();

        $exit = Artisan::call('articles:generate-embeddings');

        $this->assertSame(0, $exit);
        $this->assertSame(0, EmbeddingRun::where('user_id', $this->comercio->id)->count(), 'Con el corte abierto el comando no abre tanda.');
        Queue::assertNothingPushed();
        $this->assertNotSame('', trim(Artisan::output()), 'El comando avisa (warn) por qué no despacha.');

        // Observer: editar el nombre cambia un campo relevante para el embedding.
        $editable->name = 'Articulo editable durante el corte (editado)';
        $editable->save();

        Queue::assertNothingPushed();
        $this->assertNull(
            DB::table('articles')->where('id', $editable->id)->value('embedding_queued_at'),
            'El corte se consulta ANTES de reclamar la marca: el artículo queda con embedding_queued_at nulo.'
        );
        $this->assertNull(DB::table('articles')->where('id', $pendiente->id)->value('embedding_queued_at'));
    }

    /**
     * CA3.3 — Recuperación de los mismos 50: tras el corte (CA3.1) y vencido el TTL, con OpenAI sano
     * la siguiente pasada del comando los genera; el corte queda cerrado. Arranca verificando el
     * corte (1 solo request): hoy la fase del corte ya falla.
     *
     * @test
     */
    public function ca3_3_vencido_el_corte_la_siguiente_pasada_genera_los_mismos_50()
    {
        $ids = $this->escenario_cincuenta_con_401();
        $this->cuatro_rondas();

        $this->assertSame(1, $this->pedidos_enviados(), 'Precondición: el corte dejó UN solo request. ' . $this->diag());
        $this->assertTrue(Cache::has(self::CLAVE_CORTE), 'Precondición: el 401 abrió el corte.');

        // +61 min desde la última ronda: vence el TTL de 60 min. OpenAI vuelve sano.
        $despues = $this->t0->copy()->addSeconds(183)->addMinutes(61);
        Carbon::setTestNow($despues);
        $this->openai_responde(Http::response($this->cuerpo_exito(), 200));

        $this->assertFalse(Cache::has(self::CLAVE_CORTE), 'Vencido el TTL el corte se cierra solo.');

        $this->assertSame(0, Artisan::call('articles:generate-embeddings'));
        $this->ronda($despues);
        $this->ronda($despues->copy()->addSeconds(61));

        $filas = DB::table('articles')->whereIn('id', $ids)->get();
        $this->assertCount(self::CANTIDAD, $filas, 'Son los mismos 50 ids.');
        foreach ($filas as $fila) {
            $this->assertNotNull($fila->embedding, 'El artículo ' . $fila->id . ' recuperó su vector.');
            $this->assertNotNull($fila->embedding_generated_at);
            $this->assertNull($fila->embedding_queued_at);
        }
        $this->assertSame(self::CANTIDAD, $this->pedidos_enviados(), 'Un pedido por artículo tras reabrir. ' . $this->diag());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertFalse(Cache::has(self::CLAVE_CORTE), 'El corte sigue cerrado.');
        Event::assertDispatched(ArticleEmbeddingsBatchGenerated::class, 1);
    }

    /**
     * CA3.3b — Editado durante el corte: un artículo ya vectorizado se edita con el corte abierto →
     * no se despacha; vencido el corte, la siguiente pasada lo toma (`updated_at > embedding_generated_at`)
     * y su `embedding_source_hash` cambia.
     *
     * @test
     */
    public function ca3_3b_un_articulo_editado_durante_el_corte_se_recupera_en_la_siguiente_pasada()
    {
        Carbon::setTestNow($this->t0);
        $this->dar_extension();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        $articulo = $this->articulo('Taladro percutor corte');

        // Ya vectorizado: generado en T0 (updated_at = T0, o sea NO pendiente).
        $service = app(ArticleEmbeddingService::class);
        $hash_viejo = sha1($service->embedding_for_article(Article::with(['category', 'brand', 'descriptions'])->find($articulo->id)));
        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'              => json_encode([0.1, 0.2, 0.3]),
            'embedding_generated_at' => $this->t0,
            'embedding_source_hash'  => $hash_viejo,
        ]);

        $this->soltar_la_pausa();
        $this->abrir_corte_a_mano(401);

        // +5 min: se edita el nombre con el corte abierto.
        Carbon::setTestNow($this->t0->copy()->addMinutes(5));
        $editable = Article::find($articulo->id);
        $editable->name = 'Taladro percutor corte inalambrico';
        $editable->save();

        $this->assertSame(0, $this->jobs_de(GenerateArticleEmbeddingJob::class), 'Con el corte abierto el observer no despacha.');
        $this->assertNull(DB::table('articles')->where('id', $articulo->id)->value('embedding_queued_at'));

        // +66 min: el corte (TTL 60) venció; OpenAI sano; la pasada del comando lo toma.
        $despues = $this->t0->copy()->addMinutes(66);
        Carbon::setTestNow($despues);
        $this->assertFalse(Cache::has(self::CLAVE_CORTE));
        $this->openai_responde(Http::response($this->cuerpo_exito(), 200));

        $this->assertSame(0, Artisan::call('articles:generate-embeddings'));
        $this->ronda($despues);
        $this->ronda($despues->copy()->addSeconds(61));

        $fila = DB::table('articles')->where('id', $articulo->id)->first();
        $hash_nuevo = sha1($service->embedding_for_article(Article::with(['category', 'brand', 'descriptions'])->find($articulo->id)));
        $this->assertNotSame($hash_viejo, $hash_nuevo, 'Fixture: el texto cambió con la edición.');
        $this->assertSame($hash_nuevo, (string) $fila->embedding_source_hash, 'El hash del texto nuevo quedó guardado.');
        $this->assertSame([0.5, 0.25, 0.125], json_decode($fila->embedding, true), 'El vector se regeneró.');
        $this->assertNull($fila->embedding_queued_at);
    }

    /**
     * CA3.4 — Transitorios no cortan: 500 y `ConnectionException` re-lanzan y reintentan como hoy; 429
     * de rate limit no abre; 429 con `insufficient_quota` abre.
     *
     * @test
     */
    public function ca3_4_los_errores_transitorios_no_cortan_y_la_cuota_agotada_si()
    {
        // 500: RuntimeException de hoy, corte cerrado; el job re-marca y reintenta.
        $this->openai_responde(Http::response($this->cuerpo_openai('The server had an error', 'server_error', 'server_error'), 500));
        $error = $this->llamar_generate_embedding();
        $this->assertNotNull($error);
        $this->assertSame(\RuntimeException::class, get_class($error), '500 sigue siendo RuntimeException.');
        $this->assertStringContainsString('error HTTP 500', $error->getMessage());
        $this->assertFalse(Cache::has(self::CLAVE_CORTE), '500 no abre el corte.');

        $articulo = $this->articulo('Articulo transitorio 500');
        $this->soltar_la_pausa();
        $relanzo = null;
        try {
            (new GenerateArticleEmbeddingJob($articulo->id))->handle(app(ArticleEmbeddingService::class));
        } catch (\Throwable $e) {
            $relanzo = $e;
        }
        $this->assertNotNull($relanzo, 'El job re-lanza el 500 para que la cola reintente.');
        $this->assertNotNull(
            DB::table('articles')->where('id', $articulo->id)->value('embedding_queued_at'),
            'Con intentos restantes el job re-marca el artículo (camino de hoy).'
        );

        // Timeout / conexión.
        $this->openai_responde(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        $error = $this->llamar_generate_embedding();
        $this->assertInstanceOf(ConnectionException::class, $error, 'Un timeout se propaga como hoy.');
        $this->assertFalse(Cache::has(self::CLAVE_CORTE), 'Un timeout no abre el corte.');

        // 429 de rate limit: no abre.
        $this->openai_responde(Http::response($this->cuerpo_openai('Rate limit reached for requests', 'rate_limit_exceeded', 'requests'), 429));
        $error = $this->llamar_generate_embedding();
        $this->assertSame(\RuntimeException::class, get_class($error), '429 de rate limit sigue siendo RuntimeException.');
        $this->assertFalse(Cache::has(self::CLAVE_CORTE), '429 sin insufficient_quota no abre el corte.');

        // 429 con insufficient_quota: abre.
        $this->openai_responde(Http::response($this->cuerpo_openai('You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota', 'insufficient_quota'), 429));
        $error = $this->llamar_generate_embedding();
        $this->assertSame(EmbeddingsCredencialRechazadaException::class, is_object($error) ? get_class($error) : null, '429 insufficient_quota lanza la excepción del corte.');
        $this->assertTrue(Cache::has(self::CLAVE_CORTE), '429 con insufficient_quota abre el corte.');
    }

    /**
     * CA3.4b — 403 según el cuerpo: con cuerpo de error de OpenAI abre el corte; con la página HTML
     * de un proxy/WAF NO abre, lanza RuntimeException y el job re-marca y reintenta como hoy.
     *
     * @test
     */
    public function ca3_4b_un_403_corta_solo_si_el_cuerpo_es_de_openai()
    {
        // 403 HTML (proxy): camino de hoy.
        $this->openai_responde(Http::response('<html><body><h1>403 Forbidden</h1></body></html>', 403, ['Content-Type' => 'text/html']));
        $error = $this->llamar_generate_embedding();
        $this->assertNotNull($error);
        $this->assertSame(\RuntimeException::class, get_class($error), '403 HTML sigue siendo RuntimeException.');
        $this->assertStringContainsString('error HTTP 403', $error->getMessage());
        $this->assertFalse(Cache::has(self::CLAVE_CORTE), 'Un 403 de proxy (HTML) no abre el corte.');

        $articulo = $this->articulo('Articulo 403 html');
        $this->soltar_la_pausa();
        $relanzo = null;
        try {
            (new GenerateArticleEmbeddingJob($articulo->id))->handle(app(ArticleEmbeddingService::class));
        } catch (\Throwable $e) {
            $relanzo = $e;
        }
        $this->assertNotNull($relanzo, 'El job re-lanza el 403 HTML y reintenta como hoy.');
        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('embedding_queued_at'), 'Re-marca: quedan intentos.');

        // 403 con cuerpo de error de OpenAI: abre.
        $this->openai_responde(Http::response(
            $this->cuerpo_openai('Country, region, or territory not supported', 'unsupported_country_region_territory'),
            403
        ));
        $error = $this->llamar_generate_embedding();
        $this->assertSame(EmbeddingsCredencialRechazadaException::class, is_object($error) ? get_class($error) : null, '403 con cuerpo de OpenAI lanza la excepción del corte.');
        $this->assertStringContainsString('error HTTP 403 en OpenAI', (string) $error->getMessage(), 'Conserva el texto de siempre.');
        $this->assertTrue(Cache::has(self::CLAVE_CORTE), '403 con cuerpo de OpenAI abre el corte.');
    }

    /**
     * CA3.5 — Estado visible: `credencial_rechazada.status = 401` con el corte abierto y null cerrado;
     * una sola línea `error` por apertura, sin la clave, con la salida manual escrita.
     *
     * @test
     */
    public function ca3_5_el_estado_expone_el_corte_y_el_log_dice_como_salir()
    {
        $this->assertSame(60, config('services.openai.embeddings_corte_minutos'), 'Default de 60 min en config/services.php.');

        $cerrado = ArticleEmbeddingsEstadoHelper::estado($this->comercio->id);
        $this->assertArrayHasKey('credencial_rechazada', $cerrado, 'Campo nuevo opcional del estado.');
        $this->assertNull($cerrado['credencial_rechazada'], 'Cerrado → null.');

        // Apertura real: un 401 con cuerpo de OpenAI; después una segunda llamada (bloqueada, sin HTTP).
        $this->openai_responde(Http::response($this->cuerpo_401(), 401));
        $this->llamar_generate_embedding();
        $this->llamar_generate_embedding();
        $this->assertSame(1, $this->pedidos_enviados(), 'La segunda llamada ya no sale a OpenAI.');

        $abierto = ArticleEmbeddingsEstadoHelper::estado($this->comercio->id);
        $this->assertArrayHasKey('credencial_rechazada', $abierto);
        $this->assertIsArray($abierto['credencial_rechazada']);
        $this->assertSame(401, (int) $abierto['credencial_rechazada']['status']);
        $this->assertArrayHasKey('desde', $abierto['credencial_rechazada']);

        // Compatibilidad hacia atrás: las claves de siempre siguen.
        foreach (['sin_generar', 'pendiente', 'generandose'] as $clave) {
            $this->assertArrayHasKey($clave, $abierto);
        }

        // Log: una sola línea `error` por apertura, con la salida manual, sin la clave.
        $con_salida = 0;
        foreach ($this->log_handler->getRecords() as $registro) {
            $texto = $registro['message'] . ' ' . json_encode($registro['context']);
            if (strpos($texto, 'cache:forget embeddings_credencial_rechazada') !== false) {
                $con_salida++;
                $this->assertSame('ERROR', $registro['level_name'], 'La línea de apertura es de nivel error.');
            }
            $this->assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', $texto, 'El log no repite la clave que eco OpenAI.');
            $this->assertStringNotContainsString('clave-de-prueba-que-no-debe-aparecer-en-logs', $texto, 'El log no incluye la clave configurada.');
        }
        $this->assertSame(1, $con_salida, 'Una sola línea de error por apertura, con el texto `cache:forget embeddings_credencial_rechazada`.');
    }

    /**
     * CA3.5b — Salida manual: con el corte abierto el comando no despacha; tras
     * `cache:forget embeddings_credencial_rechazada` el corte cierra y el comando vuelve a despachar.
     *
     * @test
     */
    public function ca3_5b_la_salida_manual_cache_forget_reabre_el_despacho()
    {
        $this->dar_extension();
        $this->articulo('Articulo salida manual');
        $this->soltar_la_pausa();
        $this->abrir_corte_a_mano(401);
        Queue::fake();

        Artisan::call('articles:generate-embeddings');
        Queue::assertNothingPushed();

        Artisan::call('cache:forget', ['key' => self::CLAVE_CORTE]);

        $this->assertFalse(Cache::has(self::CLAVE_CORTE));
        Artisan::call('articles:generate-embeddings');
        Queue::assertPushed(GenerateArticleEmbeddingsBatchJob::class, 1);
        $this->assertSame(
            1,
            $this->cantidad_de_articulos_en_lotes(),
            'El comando despacha los artículos pendientes agrupados en un job de lote (1 en total).'
        );
    }

    /**
     * CA3.5c — Búsqueda: con el corte abierto `search_similar_articles` lanza la excepción del corte
     * sin HTTP; un 401 con cuerpo de OpenAI en la búsqueda abre el corte.
     *
     * @test
     */
    public function ca3_5c_la_busqueda_respeta_el_corte_y_tambien_lo_abre()
    {
        $service = app(ArticleEmbeddingService::class);

        // Corte abierto: no llama a OpenAI (aunque OpenAI estuviera sano).
        $this->abrir_corte_a_mano(401);
        $this->openai_responde(Http::response($this->cuerpo_exito(), 200));

        $lanzo = null;
        try {
            $service->search_similar_articles('martillo', $this->comercio->id);
        } catch (\Throwable $e) {
            $lanzo = $e;
        }
        $this->assertSame(
            EmbeddingsCredencialRechazadaException::class,
            is_object($lanzo) ? get_class($lanzo) : null,
            'Con el corte abierto la búsqueda lanza la excepción del corte.'
        );
        $this->assertSame(0, $this->pedidos_enviados(), 'Sin HTTP con el corte abierto.');

        // Corte cerrado + 401 en la búsqueda: lo abre.
        Cache::forget(self::CLAVE_CORTE);
        $this->openai_responde(Http::response($this->cuerpo_401(), 401));

        $lanzo = null;
        try {
            $service->search_similar_articles('martillo', $this->comercio->id);
        } catch (\Throwable $e) {
            $lanzo = $e;
        }
        $this->assertSame(
            EmbeddingsCredencialRechazadaException::class,
            is_object($lanzo) ? get_class($lanzo) : null,
            'Un 401 con cuerpo de OpenAI en la búsqueda lanza la excepción del corte.'
        );
        $this->assertTrue(Cache::has(self::CLAVE_CORTE), 'Un 401 en la búsqueda abre el corte.');
    }

    /**
     * CA3.7 — Sin aviso por la tanda fallida (y sin tandas por hora): con el corte abierto, dos
     * pasadas del comando a +30 y +59 min (antes del TTL) no crean `embedding_runs` nuevas ni jobs.
     * El `assertNotDispatched` del evento ya está en `afirmar_estado_cortado()` (CA3.1).
     *
     * @test
     */
    public function ca3_7_con_el_corte_abierto_las_pasadas_horarias_no_crean_tandas()
    {
        Carbon::setTestNow($this->t0);
        $this->dar_extension();
        $this->articulo('Articulo pasadas horarias');
        $this->soltar_la_pausa();
        $this->abrir_corte_a_mano(401);
        Queue::fake();

        foreach ([30, 59] as $minutos) {
            Carbon::setTestNow($this->t0->copy()->addMinutes($minutos));
            $this->assertTrue(Cache::has(self::CLAVE_CORTE), 'A +' . $minutos . ' min el corte sigue abierto (TTL 60).');

            Artisan::call('articles:generate-embeddings');

            $this->assertSame(
                0,
                EmbeddingRun::where('user_id', $this->comercio->id)->count(),
                'La pasada de +' . $minutos . ' min no crea una tanda nueva.'
            );
        }

        Queue::assertNothingPushed();
    }

    /**
     * Suma los artículos que viajan en todos los GenerateArticleEmbeddingsBatchJob empujados al fake.
     * Antes el comando empujaba un GenerateArticleEmbeddingJob por artículo; ahora los agrupa por lote
     * (misión embeddings-lote-cola) y la cantidad de artículos se lee de `get_article_ids()`.
     *
     * @return int
     */
    protected function cantidad_de_articulos_en_lotes()
    {
        $cantidad = 0;

        foreach (Queue::pushed(GenerateArticleEmbeddingsBatchJob::class) as $lote) {
            $cantidad += count($lote->get_article_ids());
        }

        return $cantidad;
    }
}
