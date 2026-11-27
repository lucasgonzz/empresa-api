<?php

namespace Tests\Feature\Whatsapp;

use App\Http\Controllers\Helpers\DuplicarRecetaHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\Article;
use App\Models\Description;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\Recipe;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingDispatcher;
use App\Services\ArticleEmbeddingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión embedding-un-solo-job — F/34: cada artículo tiene, como mucho, UNA generación de
 * embedding pendiente.
 *
 * Contexto del problema (medido en Ferretotal): la cola llegó a tener 462 jobs
 * `GenerateArticleEmbeddingJob` y el scheduler (`articles:generate-embeddings`) volvía a
 * encolar una tanda de ~546 cada 30 minutos sobre los MISMOS artículos, más lo que despachan
 * `ArticleObserver` y `DescriptionObserver` cada vez que cambia el nombre o una descripción.
 *
 * Diseño que estos tests fijan (PLAN §2): una columna `articles.embedding_queued_at` es la
 * marca de "ya hay un job pendiente", y un servicio único, `ArticleEmbeddingDispatcher`, es el
 * ÚNICO lugar que encola el job. El job libera la marca al empezar a correr (así toma el texto
 * vigente y un cambio posterior sí encola otro), la conserva si va a reintentar, la libera si
 * muere, y el comando del scheduler no encola lo ya marcado ni lo que ya está al día.
 *
 * 🔴 ESTOS TESTS SE ESCRIBIERON ANTES QUE EL CÓDIGO: hoy fallan todos, porque no existen la
 * columna, el servicio ni los getters `get_article_id()` / `get_embedding_run_id()` del job, y
 * porque la duplicación real (3 jobs por 3 cambios, una tanda que re-encola lo ya encolado)
 * sigue ocurriendo. Prohibido ajustar una aserción para que pase.
 *
 * Cómo se cuentan los jobs de UN artículo: `Queue::fake()` captura todo lo que se encole, y
 * `jobs_de_articulo()` filtra por `get_article_id()`. Se cuenta con `Queue::pushed(...)`.
 *
 * Sobre `Queue::fake()` y la marca: el fake descarta el job, así que NADIE libera la marca
 * (en producción la libera el job al correr). Por eso cada test decide a mano en qué estado
 * está la marca con `poner_marca()` en lugar de depender de lo que hizo un despacho anterior.
 *
 * PHP 7.4: nada de match, str_contains, operador nullsafe, argumentos nombrados, union types ni promoción
 * de propiedades en el constructor.
 */
class Embeddings_un_solo_job_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'agentes_ia';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /**
     * Calcado de los tests 16/17: pausa apagada, clave de OpenAI de prueba, gate de observers
     * reseteado, USER_ID de la instancia y red fingida para que nada salga a internet.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // La base de los tests arranca con los embeddings pausados (ver tests/TestCase.php); acá se miden, así que se prenden.
        config(['services.openai.embeddings_generacion_pausada' => false]);

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        // De prueba y no null: el comando no despacha nada sin clave (guarda de GenerateArticleEmbeddings).
        // Sigue sin salir a la red: los jobs los captura Queue::fake() y, donde el job corre de verdad,
        // fingir_openai() intercepta el HTTP.
        config(['services.openai.api_key' => 'clave-de-prueba']);
        // El broadcast real se corta por config.
        config(['broadcasting.default' => 'null']);

        // El memo del gate es estático y sobrevive entre casos del mismo proceso de PHPUnit.
        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio embedding un solo job',
            'company_name' => 'Ferreteria un solo job',
            'email'        => 'embedding-un-solo-job-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando resuelve el dueño de la instancia por acá, igual que el scheduler.
        config(['app.USER_ID' => $this->comercio->id]);

        // OJO: acá NO va un `Http::fake(['*' => ...])` genérico. En Laravel 8 los stubs de
        // Http::fake() se acumulan y gana el PRIMERO que matchea, así que un '*' puesto en el
        // setUp tapaba al stub de api.openai.com de fingir_openai() y el job nunca recibía vector.
        // Los tests que corren el job llaman a fingir_openai(); los demás no salen a la red porque
        // la cola está fakeada o el cliente HTTP no se usa.
    }

    /**
     * Deja limpio el memo estático del gate para el próximo test del proceso.
     *
     * @return void
     */
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
     * Crea un artículo activo del comercio.
     *
     * @param string $nombre
     * @param array  $extra Pisa o completa los atributos.
     * @return Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * Respuesta de OpenAI con un vector cualquiera, para los casos donde el job SÍ escribe un
     * embedding nuevo.
     *
     * @return void
     */
    protected function fingir_openai()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => [0.5, 0.25, 0.125]]]], 200),
            '*'                => Http::response([], 200),
        ]);
    }

    /**
     * Texto que el job vectorizaría hoy para ese artículo, y su huella.
     *
     * @param int $article_id
     * @return array{0: string, 1: string} [texto, sha1(texto)]
     */
    protected function texto_y_huella($article_id)
    {
        $fresco = Article::with(['category', 'brand', 'descriptions'])->find($article_id);
        $texto  = (new ArticleEmbeddingService())->embedding_for_article($fresco);

        return [$texto, sha1($texto)];
    }

    /**
     * Lee la marca de "encolado" de un artículo directo de la base (sin pasar por Eloquent).
     *
     * Si la columna no existe lanza una QueryException: es el rojo esperado antes de la migración.
     *
     * @param int $article_id
     * @return string|null El timestamp de la marca, o null si el artículo no está marcado.
     */
    protected function marca_de($article_id)
    {
        return DB::table('articles')->where('id', $article_id)->value('embedding_queued_at');
    }

    /**
     * Pone (o saca) la marca de "encolado" con el query builder, SIN tocar `updated_at` ni
     * disparar observers. Simula el estado en que dejó la marca un despacho anterior.
     *
     * @param int      $article_id
     * @param int|null $minutos_atras Hace cuántos minutos se marcó; null = sin marca.
     * @return void
     */
    protected function poner_marca($article_id, $minutos_atras)
    {
        DB::table('articles')->where('id', $article_id)->update([
            'embedding_queued_at' => is_null($minutos_atras) ? null : Carbon::now()->subMinutes($minutos_atras),
        ]);
    }

    /**
     * Cuenta los GenerateArticleEmbeddingJob empujados al fake para UN artículo.
     *
     * @param int $article_id
     * @return int
     */
    protected function jobs_de_articulo($article_id)
    {
        return Queue::pushed(GenerateArticleEmbeddingJob::class, function ($job) use ($article_id) {
            return $job->get_article_id() === (int) $article_id;
        })->count();
    }

    /**
     * Devuelve los jobs empujados para un artículo (para leer su run_id o correrlos).
     *
     * @param int $article_id
     * @return \Illuminate\Support\Collection
     */
    protected function jobs_empujados_de($article_id)
    {
        return Queue::pushed(GenerateArticleEmbeddingJob::class, function ($job) use ($article_id) {
            return $job->get_article_id() === (int) $article_id;
        });
    }

    /**
     * Devuelve los GenerateArticleEmbeddingsBatchJob empujados que llevan a un artículo entre sus ids.
     * Desde la misión embeddings-lote-cola el COMANDO ya no empuja un job por artículo sino lotes
     * (los observers siguen empujando el job de un artículo: ver jobs_empujados_de()).
     *
     * @param int $article_id
     * @return \Illuminate\Support\Collection
     */
    protected function lotes_con_articulo($article_id)
    {
        return Queue::pushed(GenerateArticleEmbeddingsBatchJob::class, function ($lote) use ($article_id) {
            return in_array((int) $article_id, array_map('intval', $lote->get_article_ids()), true);
        });
    }

    /**
     * Escenario común a CA2 y CA7: el artículo A queda con el job del observer (marcado, sin
     * run) y el artículo B queda pendiente SIN marca (se creó antes de dar la extensión, así
     * que ningún observer lo encoló). `Queue::fake()` queda puesto y SIN re-fakear.
     *
     * @return Article[] [A, B]
     */
    protected function armar_escenario_a_y_b()
    {
        Queue::fake();

        // B primero y sin extensión: el gate del observer está cerrado, nadie lo encola.
        $articulo_b = $this->articulo('Articulo B pendiente sin marca');

        // Recién ahora se da la extensión: lo que se cree desde acá pasa por el observer.
        $this->dar_extension();

        // A: el observer lo encola (1 job, sin run) y lo marca. Se fija la marca a mano para que
        // el escenario no dependa de cómo la deja el despacho.
        $articulo_a = $this->articulo('Articulo A encolado por el observer');
        $this->poner_marca($articulo_a->id, 1);

        return [$articulo_a, $articulo_b];
    }

    /**
     * CA1. Con la extensión activa y sin re-fakear la cola: crear un artículo, cambiarle el
     * nombre y agregarle una descripción deja EXACTAMENTE 1 job para ese artículo, y la marca
     * queda puesta. Hoy son 3 (uno por observer disparado).
     *
     * @group whatsapp
     * @test
     */
    public function test_dos_cambios_seguidos_dejan_un_solo_job()
    {
        $this->dar_extension();
        Queue::fake();

        // Cambio 1: el alta.
        $articulo = $this->articulo('Taladro percutor');

        // Cambio 2: el nombre (campo que entra al texto del embedding).
        $articulo->update(['name' => 'Taladro percutor 13 mm']);

        // Cambio 3: una descripción nueva (DescriptionObserver también encola).
        Description::create([
            'article_id' => $articulo->id,
            'title'      => 'Uso',
            'content'    => 'Para mampostería y hormigón.',
        ]);

        $this->assertSame(
            1,
            $this->jobs_de_articulo($articulo->id),
            'Tres cambios seguidos sin que corra el job tienen que dejar UN solo job pendiente.'
        );

        $this->assertNotNull(
            $this->marca_de($articulo->id),
            'El artículo con un job pendiente tiene que quedar con embedding_queued_at puesto.'
        );
    }

    /**
     * CA2. Sobre el estado de CA1 más un segundo artículo pendiente sin marca: correr el
     * comando del scheduler deja 1 job para A (el del observer, sin run), 1 job para B con el
     * run_id de la tanda, y la tanda con total_jobs = 1 (cuenta sólo lo despachado de verdad).
     *
     * @group whatsapp
     * @test
     */
    public function test_el_scheduler_no_reencola_lo_ya_encolado_y_cuenta_solo_lo_despachado()
    {
        list($articulo_a, $articulo_b) = $this->armar_escenario_a_y_b();

        // El job del observer para A (sin run), tal como lo dejaría el despacho real.
        $this->assertSame(
            1,
            $this->jobs_de_articulo($articulo_a->id),
            'Precondición: el observer dejó un job para A.'
        );

        Artisan::call('articles:generate-embeddings');

        $this->assertSame(
            1,
            $this->jobs_de_articulo($articulo_a->id),
            'A ya tenía un job pendiente: el scheduler no puede encolarle otro.'
        );
        $this->assertNull(
            $this->jobs_empujados_de($articulo_a->id)->first()->get_embedding_run_id(),
            'El único job de A es el del observer, que no pertenece a ninguna tanda.'
        );

        $this->assertSame(
            0,
            $this->lotes_con_articulo($articulo_a->id)->count(),
            'A ya tenía un job pendiente: el scheduler tampoco lo mete en un lote.'
        );

        $jobs_de_b = $this->lotes_con_articulo($articulo_b->id);
        $this->assertSame(1, $jobs_de_b->count(), 'B estaba pendiente y sin marca: el scheduler lo encola en un lote.');

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($tanda, 'El scheduler abre una tanda para lo que sí despacha.');
        $this->assertSame(
            (int) $tanda->id,
            $jobs_de_b->first()->get_embedding_run_id(),
            'El job de B lleva el run_id de la tanda.'
        );
        $this->assertSame(
            1,
            (int) $tanda->total_jobs,
            'total_jobs cuenta lo despachado de verdad (B), no lo salteado por la marca (A).'
        );
    }

    /**
     * CA3. Un artículo al día (vector, huella = sha1 del texto actual, `updated_at` movido por
     * un guardado de stock) y un artículo con texto vacío ya procesado (embedding NULL, huella
     * sha1(''), fecha puesta) NO se encolan: el comando no despacha ni crea tanda, sella al
     * primero (`embedding_generated_at >= updated_at`) y una segunda pasada tampoco hace nada.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_scheduler_no_encola_articulos_al_dia_ni_con_texto_vacio()
    {
        // Sin extensión durante el armado: ningún observer despacha, no hay marcas de por medio.
        Queue::fake();

        // Artículo al día: vector con la huella del texto actual.
        $al_dia = $this->articulo('Martillo carpintero');
        $huella = $this->texto_y_huella($al_dia->id);

        // Artículo con texto vacío: nombre vacío, sin marca, categoría ni descripciones.
        $vacio       = $this->articulo('');
        $huella_vacia = $this->texto_y_huella($vacio->id);
        $this->assertSame('', $huella_vacia[0], 'Precondición: el artículo sin nombre produce texto vacío.');

        // El guardado de stock movió updated_at DESPUÉS de generar el embedding.
        DB::table('articles')->where('id', $al_dia->id)->update([
            'embedding'              => json_encode([1.0, 0.0, 0.0]),
            'embedding_source_hash'  => $huella[1],
            'embedding_generated_at' => now()->subDay(),
            'updated_at'             => now()->subHour(),
        ]);

        // Texto vacío ya procesado: el job sella fecha y huella sha1('') pero el vector queda NULL.
        DB::table('articles')->where('id', $vacio->id)->update([
            'embedding'              => null,
            'embedding_source_hash'  => sha1(''),
            'embedding_generated_at' => now()->subDay(),
            'updated_at'             => now()->subHour(),
        ]);

        // Ahora sí se activa la extensión y se arranca de cero la cola, para medir sólo al comando.
        $this->dar_extension();
        Queue::fake();

        Artisan::call('articles:generate-embeddings');

        Queue::assertNothingPushed();
        $this->assertSame(
            0,
            EmbeddingRun::where('user_id', $this->comercio->id)->count(),
            'Si no hay nada que despachar no se abre ninguna tanda.'
        );

        $fila = DB::table('articles')->where('id', $al_dia->id)->first();
        $this->assertTrue(
            strtotime($fila->embedding_generated_at) >= strtotime($fila->updated_at),
            'El comando sella al artículo al día (embedding_generated_at >= updated_at) para no volver a verlo.'
        );

        // Segunda pasada: tampoco despacha ni abre tanda.
        Artisan::call('articles:generate-embeddings');

        Queue::assertNothingPushed();
        $this->assertSame(0, EmbeddingRun::where('user_id', $this->comercio->id)->count());
    }

    /**
     * CA4. El job libera la marca ANTES de leer el artículo y vectoriza el texto VIGENTE: con
     * la marca puesta y el nombre cambiado por query builder (sin observer), al correr
     * `handle()` la marca queda NULL y la huella es la del texto con el nombre nuevo. Después
     * un cambio por Eloquent sí empuja 1 job nuevo.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_job_libera_la_marca_al_empezar_y_toma_el_texto_actual()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Nombre viejo del articulo');
        $this->poner_marca($articulo->id, 1);

        // El nombre cambia sin pasar por el observer (el job ya estaba encolado).
        DB::table('articles')->where('id', $articulo->id)->update(['name' => 'Nombre nuevo del articulo']);

        $this->fingir_openai();

        (new GenerateArticleEmbeddingJob($articulo->id))->handle(app(ArticleEmbeddingService::class));

        $this->assertNull($this->marca_de($articulo->id), 'Al correr, el job libera la marca.');

        $esperada = $this->texto_y_huella($articulo->id);
        $this->assertStringContainsString('Nombre nuevo', $esperada[0], 'Precondición: el texto actual lleva el nombre nuevo.');
        $this->assertSame(
            $esperada[1],
            DB::table('articles')->where('id', $articulo->id)->value('embedding_source_hash'),
            'El job vectoriza el texto vigente al correr, no el de cuando se encoló.'
        );

        // Con la marca liberada, un cambio posterior vuelve a encolar (exactamente 1 job).
        Queue::fake();
        Article::find($articulo->id)->update(['name' => 'Nombre posterior al job']);

        $this->assertSame(
            1,
            $this->jobs_de_articulo($articulo->id),
            'Sin job pendiente, un cambio de nombre por Eloquent encola un job nuevo.'
        );
    }

    /**
     * CA5. La marca tiene vigencia (TTL): vencida, un cambio de nombre vuelve a encolar y
     * renueva la marca; vigente (TTL - 1 minutos), no encola nada.
     *
     * @group whatsapp
     * @test
     */
    public function test_una_marca_vencida_permite_reencolar()
    {
        $this->dar_extension();
        Queue::fake();

        $inicio_del_test = Carbon::now()->startOfSecond();
        $ttl             = ArticleEmbeddingDispatcher::QUEUED_MARK_TTL_MINUTES;

        $articulo = $this->articulo('Articulo con marca vieja');

        // Marca vencida: se puso hace TTL + 1 minutos y el job nunca corrió (cola perdida).
        $this->poner_marca($articulo->id, $ttl + 1);
        Queue::fake();

        Article::find($articulo->id)->update(['name' => 'Articulo con marca vieja renombrado']);

        $this->assertSame(1, $this->jobs_de_articulo($articulo->id), 'Marca vencida: el cambio vuelve a encolar.');

        $marca = $this->marca_de($articulo->id);
        $this->assertNotNull($marca);
        $this->assertTrue(
            strtotime($marca) >= $inicio_del_test->timestamp,
            'Al reencolar, la marca queda renovada (no la vieja).'
        );

        // Marca vigente: hace TTL - 1 minutos todavía cuenta como "hay un job pendiente".
        $this->poner_marca($articulo->id, $ttl - 1);
        Queue::fake();

        Article::find($articulo->id)->update(['name' => 'Articulo con marca vigente renombrado']);

        $this->assertSame(0, $this->jobs_de_articulo($articulo->id), 'Marca vigente: no se encola otro job.');
    }

    /**
     * CA6. Si OpenAI responde 500 el job lanza y CONSERVA la marca (va a reintentar y va a
     * releer el texto); `failed()` la libera; y en el último intento no la vuelve a poner.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_reintento_conserva_la_marca_y_failed_la_libera()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Articulo que falla en OpenAI');
        $job      = new GenerateArticleEmbeddingJob($articulo->id);

        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        // Intento 1 de 3 (sin job de cola, attempts() devuelve 1): va a reintentar.
        $this->poner_marca($articulo->id, 1);
        $lanzo = false;
        try {
            $job->handle(app(ArticleEmbeddingService::class));
        } catch (\Throwable $e) {
            $lanzo = true;
        }

        $this->assertTrue($lanzo, 'Con OpenAI en 500 el job tiene que lanzar la excepción para que la cola reintente.');
        $this->assertNotNull($this->marca_de($articulo->id), 'Va a reintentar: el job sigue pendiente, la marca se conserva.');

        // failed() = murió definitivo: libera la marca.
        $job->failed(new \RuntimeException('definitivo'));
        $this->assertNull($this->marca_de($articulo->id), 'failed() libera la marca para que el scheduler pueda reintentar.');

        // Último intento: attempts() == tries, no hay reintento y no se re-marca.
        $ultimo = new GenerateArticleEmbeddingJob($articulo->id);
        $cola   = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $cola->shouldReceive('attempts')->andReturn($ultimo->tries);
        $ultimo->setJob($cola);

        $this->poner_marca($articulo->id, 1);
        $lanzo = false;
        try {
            $ultimo->handle(app(ArticleEmbeddingService::class));
        } catch (\Throwable $e) {
            $lanzo = true;
        }

        $this->assertTrue($lanzo, 'El último intento también lanza (así la cola dispara failed()).');
        $this->assertNull($this->marca_de($articulo->id), 'En el último intento no se re-marca: no queda ningún reintento pendiente.');
    }

    /**
     * CA7. La tanda de CA2 cierra aunque A haya sido salteado por la marca: corre el job de B
     * (OpenAI fingido) y el finalizador, y la tanda queda `completada` con terminados =
     * total_jobs = 1.
     *
     * @group whatsapp
     * @test
     */
    public function test_la_tanda_cierra_aunque_haya_articulos_salteados()
    {
        list($articulo_a, $articulo_b) = $this->armar_escenario_a_y_b();

        Artisan::call('articles:generate-embeddings');

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($tanda, 'Precondición: el comando abrió una tanda.');

        // El job de la tanda (el de B), corrido a mano con OpenAI fingido.
        $job_de_b = $this->lotes_con_articulo($articulo_b->id)->first();
        $this->assertNotNull($job_de_b, 'Precondición: B tiene su job (el lote que lo lleva).');

        $this->fingir_openai();
        Event::fake([\App\Events\ArticleEmbeddingsBatchGenerated::class]);

        // El contenedor resuelve las dependencias de handle() (el job de lote delega en su procesador).
        app()->call([$job_de_b, 'handle']);
        (new FinalizeEmbeddingRun($tanda->id))->handle();

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertSame('completada', $tanda->status, 'La tanda cierra: sólo espera lo que de verdad despachó.');
        $this->assertSame(1, (int) $tanda->total_jobs);
        $this->assertSame(1, (int) $tanda->terminados, 'terminados == total_jobs aunque A se haya salteado.');
    }

    /**
     * CA8. Si la transacción se revierte, la marca también: no queda un "encolado fantasma".
     *
     * @group whatsapp
     * @test
     */
    public function test_una_transaccion_revertida_no_deja_la_marca()
    {
        // El artículo parte sin marca (se crea antes de la extensión: ningún observer lo encola).
        $articulo = $this->articulo('Articulo de la transaccion revertida');
        $this->assertNull($this->marca_de($articulo->id), 'Precondición: el artículo parte sin marca.');

        $this->dar_extension();
        Queue::fake();

        DB::beginTransaction();
        Article::find($articulo->id)->update(['name' => 'Nombre dentro de la transaccion']);
        DB::rollBack();

        $this->assertNull(
            $this->marca_de($articulo->id),
            'El rollback revierte la marca junto con el cambio: no queda encolado fantasma.'
        );
    }

    /**
     * CA9. Duplicar una receta cuyo artículo original tiene la marca puesta: el duplicado NO
     * hereda la marca (nace NULL) y, con la extensión activa, queda 1 job empujado para él.
     *
     * Se llama al helper de duplicación directo (lo que usa `POST api/recipe/{id}/duplicar`),
     * con un controlador mínimo que sólo aporta `userId()` y `num()`.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_duplicado_de_una_receta_no_hereda_la_marca()
    {
        $this->dar_extension();
        Queue::fake();

        $original = $this->articulo('Mesa original con marca');
        $this->poner_marca($original->id, 1);

        $receta = Recipe::create([
            'name'       => $original->name,
            'article_id' => $original->id,
            'user_id'    => $this->comercio->id,
        ]);

        // Controlador mínimo: el helper sólo le pide el dueño y el próximo número.
        $comercio_id = $this->comercio->id;
        $controlador = new class($comercio_id) {
            /** @var int */
            private $user_id;

            public function __construct($user_id)
            {
                $this->user_id = $user_id;
            }

            public function userId()
            {
                return $this->user_id;
            }

            public function num($table)
            {
                return 1;
            }
        };

        // Sólo se miran los jobs que genera la duplicación.
        Queue::fake();

        DuplicarRecetaHelper::duplicar($receta->id, 'Mesa duplicada sin marca', $controlador);

        $duplicado = Article::where('user_id', $this->comercio->id)
                            ->where('name', 'Mesa duplicada sin marca')
                            ->first();

        $this->assertNotNull($duplicado, 'Precondición: el duplicado existe.');
        $this->assertSame(
            1,
            $this->jobs_de_articulo($duplicado->id),
            'El duplicado no hereda la marca: el observer le encola su propio job.'
        );
    }

    /**
     * CA10. Detección de la clase de error: ningún archivo de `app/` despacha ni instancia
     * `GenerateArticleEmbeddingJob` por fuera de `ArticleEmbeddingDispatcher`. Barre `app/`
     * recursivamente y descarta los comentarios (con `token_get_all`), para que un docblock
     * que MENCIONE el despacho no cuente como despacho.
     *
     * @group whatsapp
     * @test
     */
    public function test_nadie_despacha_el_job_por_fuera_del_servicio()
    {
        $raiz_app = realpath(__DIR__ . '/../../../app');
        $this->assertNotFalse($raiz_app, 'No se encontró la carpeta app/.');

        // Único archivo autorizado a encolar el job.
        $permitido = $raiz_app . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . 'ArticleEmbeddingDispatcher.php';

        // Formas de encolar: ::dispatch*, ::withChain, new GenerateArticleEmbeddingJob, dispatch(new ...).
        // Misión embeddings-lote-cola (CA18): la guardia cubre también GenerateArticleEmbeddingsBatchJob.
        $patron = '/GenerateArticleEmbedding(?:Job|sBatchJob)\s*::\s*(dispatch\w*|withChain)\b|new\s+\\\\?(?:App\\\\Jobs\\\\)?GenerateArticleEmbedding(?:Job|sBatchJob)\b/';

        $infractores = [];

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz_app, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $ruta = $archivo->getRealPath();
            if ($ruta === $permitido) {
                continue;
            }

            // Se reconstruye el código sin comentarios para no marcar menciones en docblocks.
            $codigo = '';
            foreach (token_get_all(file_get_contents($ruta)) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $codigo .= $token[1];
                } else {
                    $codigo .= $token;
                }
            }

            if (preg_match($patron, $codigo)) {
                $infractores[] = substr($ruta, strlen($raiz_app) + 1);
            }
        }

        $this->assertSame(
            [],
            $infractores,
            'Estos archivos de app/ encolan GenerateArticleEmbeddingJob o GenerateArticleEmbeddingsBatchJob sin pasar por ArticleEmbeddingDispatcher: '
            . implode(', ', $infractores)
        );
    }
}
