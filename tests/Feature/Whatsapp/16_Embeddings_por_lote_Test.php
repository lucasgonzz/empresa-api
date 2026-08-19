<?php

namespace Tests\Feature\Whatsapp;

use App\Events\ArticleEmbeddingsBatchGenerated;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
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
 * Misión embeddings-por-lote-y-semilla — F/16: la tanda (`embedding_runs`) y el aviso al
 * comerciante.
 *
 * Lo que estos tests fijan es UNA distinción y todo lo que cuelga de ella: **jobs
 * despachados no es lo mismo que embeddings generados**. El comando corre en el scheduler,
 * los jobs corren en el worker, y un job puede terminar perfecto sin escribir un solo
 * vector (corta por `embedding_source_hash` porque el texto no cambió). Antes de esta
 * misión el único número que existía era el del comando, o sea el equivocado.
 *
 * Por eso el orden de los casos no es casual: primero se prueba que la tanda se abra y se
 * cierre bien, después que cada camino de salida del job caiga en el contador correcto, y
 * recién al final que el aviso salga exactamente cuando `generados > 0` y una sola vez.
 *
 * 🔴 SOBRE `Event::fake()`: ACÁ SÍ, PERO **SOLO CON LISTA EXPLÍCITA**.
 *
 * El archivo hermano `6_Embeddings_observer_Test.php` prohíbe `Event::fake()` en rojo, y
 * tiene toda la razón para lo que él prueba: `Event::fake()` **pelado** reemplaza el
 * dispatcher entero, incluido el que usan los modelos de Eloquent, así que los observers
 * dejan de correr y los tests dan verde sin probar nada.
 *
 * `Event::fake([ArticleEmbeddingsBatchGenerated::class])` es otra cosa, y está verificado
 * contra el código de Laravel 8, no supuesto:
 * `vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/EventFake.php` →
 * `dispatch()` consulta `shouldFakeEvent()`, y `shouldFakeEvent()` devuelve `true` solo si
 * la lista está vacía o el nombre del evento está en ella; en cualquier otro caso llama a
 * `$this->dispatcher->dispatch(...)`, o sea el dispatcher REAL. Con lista, entonces, los
 * eventos de modelo de Eloquent siguen pasando y los observers siguen vivos.
 *
 * Eso no se deja como afirmación de docblock: el caso
 * `una_tanda_sin_vectores_nuevos_se_cierra_sin_avisar_nada` lo comprueba adentro del test,
 * creando un artículo con el fake puesto y verificando que el observer igual despachó su
 * job. Si algún día Laravel cambiara ese comportamiento, ese caso se pone rojo y avisa.
 *
 * 🔴 El que venga a copiar el archivo 6 se va a traer la prohibición sin este matiz, y
 * entonces no va a poder probar el evento de ninguna manera razonable. Por eso está escrito
 * acá y no en el commit.
 *
 * `ArticleObserver::resetear_cache_gate()` en `setUp`, en `tearDown` y cada vez que cambian
 * las condiciones: el memo del gate es estático y sobrevive entre casos del mismo proceso
 * de PHPUnit.
 */
class Embeddings_por_lote_Test extends TestCase
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
        // El broadcast real se corta por config; el evento se captura con Event::fake([...]).
        config(['broadcasting.default' => 'null']);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio embeddings lote',
            'company_name' => 'Ferreteria lote',
            'email'        => 'embeddings-lote-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando resuelve el dueño de la instancia por acá, igual que el scheduler.
        config(['app.USER_ID' => $this->comercio->id]);
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
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * Crea una tanda del comercio con los contadores que pida el caso.
     *
     * @param array $atributos Pisa los valores por defecto.
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
     * Retrasa el `created_at` de la tanda, que es el reloj del vencimiento.
     *
     * Se escribe con el query builder y no con el modelo porque Eloquent pisa los
     * timestamps al guardar, y entonces la tanda volvería a nacer recién.
     *
     * @param EmbeddingRun $tanda
     * @param int          $minutos
     * @return EmbeddingRun La tanda releída.
     */
    protected function envejecer_tanda(EmbeddingRun $tanda, $minutos)
    {
        DB::table('embedding_runs')
            ->where('id', $tanda->id)
            ->update(['created_at' => Carbon::now()->subMinutes($minutos)]);

        return EmbeddingRun::find($tanda->id);
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
     * Respuesta de OpenAI con un vector cualquiera, para los casos donde el job SÍ tiene que
     * escribir un embedding nuevo.
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
     * Caso 1. El comando abre la tanda, la deja cerrada al despacho y con el total real de
     * artículos pendientes.
     *
     * El `total_jobs` tiene que quedar escrito recién DESPUÉS de despachar: mientras despacha,
     * la tanda vive en 'despachando' justamente para que el finalizador no la cierre viendo
     * `terminados (0) >= total_jobs (0)`.
     *
     * @group whatsapp
     * @test
     */
    public function el_comando_abre_la_tanda_y_la_deja_en_proceso_con_el_total_de_pendientes()
    {
        $this->dar_extension();
        Queue::fake();

        $this->articulo('Amoladora pendiente 1');
        $this->articulo('Amoladora pendiente 2');
        $this->articulo('Amoladora pendiente 3');

        // Se re-fakea para dejar afuera los despachos del observer y aislar lo que hace el comando.
        Queue::fake();

        Artisan::call('articles:generate-embeddings');

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();

        $this->assertNotNull($tanda, 'El comando tiene que abrir una tanda.');
        $this->assertEquals('en_proceso', $tanda->status, 'Terminado el despacho, la tanda pasa a en_proceso.');
        $this->assertEquals('scheduler', $tanda->origen, 'Sin --origen, la corrida es la del scheduler.');
        $this->assertEquals(3, (int) $tanda->total_jobs, 'total_jobs es el total de artículos pendientes.');
        $this->assertEquals(0, (int) $tanda->terminados, 'Todavía no terminó ningún job.');
        $this->assertNull($tanda->notificado_at, 'Y nadie notificó nada todavía.');

        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 3);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);
    }

    /**
     * Caso 2. El job que pertenece a una tanda suma a `generados` cuando efectivamente escribe
     * un vector nuevo.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_de_una_tanda_suma_a_generados_cuando_escribe_un_vector_nuevo()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Taladro sin vectorizar');
        $tanda    = $this->crear_tanda(['total_jobs' => 1]);

        $this->fingir_openai();

        (new GenerateArticleEmbeddingJob($articulo->id, $tanda->id))
            ->handle(app(ArticleEmbeddingService::class));

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals(1, (int) $tanda->generados, 'Se escribió un vector nuevo: cuenta como generado.');
        $this->assertEquals(0, (int) $tanda->salteados);
        $this->assertEquals(0, (int) $tanda->fallados);
        $this->assertEquals(1, (int) $tanda->terminados, 'terminados se mueve junto con el contador específico.');

        $fila = DB::table('articles')->where('id', $articulo->id)->first();
        $this->assertNotNull($fila->embedding, 'Y el vector quedó persistido de verdad.');
    }

    /**
     * Caso 3. El mismo job, con el mismo run_id, suma a `salteados` cuando corta por huella.
     *
     * Es el caso que separa "el job terminó bien" de "se generó un embedding": termina bien,
     * no llama a OpenAI, y no puede sumar al número que después ve el comerciante.
     *
     * @group whatsapp
     * @test
     */
    public function el_job_de_una_tanda_suma_a_salteados_cuando_corta_por_huella()
    {
        $this->dar_extension();
        Queue::fake();

        $articulo = $this->articulo('Taladro ya vectorizado');
        $huella   = $this->texto_y_huella($articulo->id);

        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'              => json_encode([1.0, 0.0, 0.0]),
            'embedding_source_hash'  => $huella[1],
            'embedding_generated_at' => now()->subDay(),
        ]);

        $tanda = $this->crear_tanda(['total_jobs' => 1]);

        Http::fake(['*' => Http::response([], 200)]);

        (new GenerateArticleEmbeddingJob($articulo->id, $tanda->id))
            ->handle(app(ArticleEmbeddingService::class));

        Http::assertNothingSent();

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals(0, (int) $tanda->generados, 'El texto no cambió: no hay vector nuevo que anunciar.');
        $this->assertEquals(1, (int) $tanda->salteados);
        $this->assertEquals(1, (int) $tanda->terminados);
    }

    /**
     * Caso 4. 🔴 EL CORAZÓN DE LA MISIÓN: una tanda entera salteada por huella cierra bien y
     * NO avisa nada.
     *
     * Sin esto, la corrida agendada cada treinta minutos le abriría un aviso al comerciante
     * cada treinta minutos para decirle que no pasó nada: el filtro SQL del comando levanta
     * artículos por `updated_at > embedding_generated_at`, les despacha jobs, y los jobs
     * cortan por huella sin gastar una llamada. "Jobs despachados" diría 40; "embeddings
     * generados" dice 0, y 0 no se notifica.
     *
     * De paso, este caso es el que verifica en vivo lo que afirma el docblock de la clase
     * sobre `Event::fake([...])` con lista: con el fake puesto se crea un artículo y el
     * observer igual despacha su job, o sea que los eventos de modelo de Eloquent siguieron
     * llegando al dispatcher real.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_sin_vectores_nuevos_se_cierra_sin_avisar_nada()
    {
        $this->dar_extension();
        Queue::fake();

        $tanda = $this->crear_tanda([
            'total_jobs' => 5,
            'terminados' => 5,
            'generados'  => 0,
            'salteados'  => 5,
        ]);

        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        // Comprobación del matiz del docblock: con lista explícita, los eventos de modelo
        // siguen viajando al dispatcher real y el observer sigue vivo.
        $this->articulo('Artículo creado con el fake puesto');
        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('completada', $tanda->status, 'La tanda cierra igual: cerrar y avisar son cosas distintas.');
        $this->assertNotNull(
            $tanda->notificado_at,
            'notificado_at se escribe SIEMPRE, aunque no se emita: es el candado, no el registro del broadcast.'
        );
    }

    /**
     * Caso 5. Con jobs sin terminar y la tanda todavía joven, el finalizador no cierra ni
     * avisa: se vuelve a encolar.
     *
     * Se re-despacha una instancia nueva en vez de usar `release()` justamente para no
     * comerse los `tries` mientras espera.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_que_todavia_espera_jobs_no_avisa_y_se_vuelve_a_encolar()
    {
        $tanda = $this->crear_tanda([
            'total_jobs' => 3,
            'terminados' => 1,
            'generados'  => 1,
        ]);

        Queue::fake();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('en_proceso', $tanda->status, 'No se cierra nada mientras falten jobs.');
        $this->assertNull($tanda->notificado_at);
    }

    /**
     * Caso 5 bis. Mientras la tanda está en 'despachando' el finalizador tampoco compara
     * contadores, por más que den `0 >= 0`.
     *
     * Es el caso que existe por `QUEUE_CONNECTION=database`: el worker levanta los jobs del
     * primer chunk mientras el comando todavía despacha el segundo, así que el finalizador
     * puede llegar con la tanda recién creada y `total_jobs = 0`.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_que_todavia_esta_despachando_no_se_cierra_aunque_los_contadores_den()
    {
        $tanda = $this->crear_tanda([
            'status'     => 'despachando',
            'total_jobs' => 0,
            'terminados' => 0,
        ]);

        Queue::fake();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('despachando', $tanda->status);
        $this->assertNull($tanda->notificado_at, 'Una tanda a la que no se le despachó nada no puede darse por cerrada.');
    }

    /**
     * Caso 6. Tanda completa con vectores nuevos: avisa una vez, con el número exacto de
     * `generados` (no el de jobs, no el de terminados).
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_completa_con_vectores_nuevos_avisa_el_numero_exacto()
    {
        $tanda = $this->crear_tanda([
            'origen'     => 'manual',
            'total_jobs' => 4,
            'terminados' => 4,
            'generados'  => 3,
            'salteados'  => 1,
        ]);

        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        $comercio_id = $this->comercio->id;

        Event::assertDispatched(
            ArticleEmbeddingsBatchGenerated::class,
            function ($evento) use ($comercio_id) {
                return (int) $evento->user_id === (int) $comercio_id
                    && (int) $evento->generados === 3
                    && $evento->origen === 'manual';
            }
        );

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('completada', $tanda->status);
        $this->assertNotNull($tanda->notificado_at);
    }

    /**
     * Caso 7. Tanda vencida: con jobs que ya no van a llegar y más de veinte minutos encima,
     * se cierra como 'vencida' y se avisa lo que alcanzó a generarse.
     *
     * Quedarse esperando para siempre dejaría al comerciante sin el aviso de los artículos
     * que SÍ se vectorizaron, que es la única parte que le sirve.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_vencida_se_cierra_igual_y_avisa_lo_que_alcanzo_a_generar()
    {
        $tanda = $this->crear_tanda([
            'total_jobs' => 10,
            'terminados' => 4,
            'generados'  => 2,
            'salteados'  => 2,
        ]);

        $tanda = $this->envejecer_tanda($tanda, FinalizeEmbeddingRun::MINUTOS_PARA_VENCER + 5);

        Queue::fake();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Event::assertDispatched(
            ArticleEmbeddingsBatchGenerated::class,
            function ($evento) {
                return (int) $evento->generados === 2;
            }
        );

        Queue::assertNotPushed(FinalizeEmbeddingRun::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('vencida', $tanda->status, 'Se cierra como vencida, no como completada: los seis jobs que faltan no llegaron.');
        $this->assertNotNull($tanda->notificado_at);
    }

    /**
     * Caso 7 bis. Una tanda que quedó TRABADA en 'despachando' también vence.
     *
     * 🔴 ESTE CASO SALIÓ DE UN BORDE REAL, encontrado releyendo el código antes de mergear, y
     * está acá para que no vuelva.
     *
     * El comando crea la tanda en 'despachando' y la pasa a 'en_proceso' recién cuando terminó
     * de despachar. Si muere en el medio —un error de base a mitad de un `chunkById`, el proceso
     * del scheduler matado—, la tanda se queda en 'despachando' y NO HAY NADIE que vuelva a
     * mover ese status.
     *
     * La primera versión del finalizador cortaba por 'despachando' ANTES de mirar el
     * vencimiento, así que en ese escenario se re-despachaba cada quince segundos hasta agotar
     * los 120 `tries`: media hora de intentos que no podían terminar en nada. La familia del
     * error es "un guard que corta antes de la salida de emergencia": el corte estaba bien, lo
     * que estaba mal era su orden respecto del vencimiento.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_trabada_en_despachando_tambien_vence()
    {
        $tanda = $this->crear_tanda([
            'status'     => 'despachando',
            'total_jobs' => 0,
            'terminados' => 0,
            'generados'  => 0,
        ]);

        $tanda = $this->envejecer_tanda($tanda, FinalizeEmbeddingRun::MINUTOS_PARA_VENCER + 5);

        Queue::fake();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        // Lo que importa: NO se vuelve a encolar. Con el orden viejo, acá había un re-despacho.
        Queue::assertNotPushed(FinalizeEmbeddingRun::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('vencida', $tanda->status);
        $this->assertNotNull($tanda->notificado_at, 'El candado se escribe igual, así un re-despacho tardío no la reabre.');

        // Y no avisa nada, porque no alcanzó a generar ni un vector.
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);
    }

    /**
     * Caso 7 ter. Una tanda RECIÉN creada en 'despachando' no se cierra: el comando todavía
     * está metiendo jobs y `total_jobs` no es definitivo.
     *
     * Es la contracara del caso de arriba, y hace falta para que el arreglo del vencimiento no
     * se haya llevado puesto el corte original: sin este test, alguien podría sacar el chequeo
     * de 'despachando' entero y el caso seguiría verde.
     *
     * @group whatsapp
     * @test
     */
    public function una_tanda_recien_creada_en_despachando_no_se_cierra()
    {
        $tanda = $this->crear_tanda([
            'status'     => 'despachando',
            'total_jobs' => 0,
            'terminados' => 0,
            'generados'  => 0,
        ]);

        Queue::fake();
        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        /*
         * 🔴 Si esto se cerrara, la comparación `terminados (0) >= total_jobs (0)` daría por
         * terminada una tanda a la que no se le despachó todavía ni un job.
         */
        Queue::assertPushed(FinalizeEmbeddingRun::class);
        Event::assertNotDispatched(ArticleEmbeddingsBatchGenerated::class);

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals('despachando', $tanda->status);
        $this->assertNull($tanda->notificado_at);
    }

    /**
     * Caso 8. 🔴 IDEMPOTENCIA: correr el finalizador dos veces sobre la misma tanda avisa UNA
     * sola vez.
     *
     * El finalizador se re-despacha solo mientras espera, así que llega a correr muchas veces
     * sobre la misma fila. El candado es `notificado_at`.
     *
     * Y el test no se conforma con correrlo dos veces tal cual: entre una corrida y la otra le
     * pisa el status a 'en_proceso', que es exactamente lo que hace la guarda anti-solapamiento
     * del comando cuando marca 'vencida' una tanda vieja. Si alguien "simplifica" la
     * idempotencia condicionándola al status —el error que FinalizeArticleImport ya documenta
     * en rojo—, este caso se pone rojo con dos eventos.
     *
     * @group whatsapp
     * @test
     */
    public function correr_el_finalizador_dos_veces_avisa_una_sola_vez()
    {
        $tanda = $this->crear_tanda([
            'total_jobs' => 2,
            'terminados' => 2,
            'generados'  => 2,
        ]);

        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        // Otro proceso le toca el status, como haría la guarda anti-solapamiento del comando.
        DB::table('embedding_runs')->where('id', $tanda->id)->update(['status' => 'en_proceso']);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Event::assertDispatchedTimes(ArticleEmbeddingsBatchGenerated::class, 1);
    }

    /**
     * Caso 9. Guarda anti-solapamiento: con una tanda abierta y reciente el comando no abre
     * una segunda; con una abierta pero vencida, la marca 'vencida' y sigue.
     *
     * Dos tandas a la vez del mismo comercio serían dos avisos pisándose y jobs duplicados en
     * la cola sobre los mismos artículos.
     *
     * @group whatsapp
     * @test
     */
    public function con_una_tanda_abierta_y_reciente_el_comando_no_abre_otra()
    {
        $this->dar_extension();
        Queue::fake();

        $this->articulo('Pinza pendiente');

        $abierta = $this->crear_tanda(['status' => 'en_proceso', 'total_jobs' => 1]);

        Queue::fake();
        Artisan::call('articles:generate-embeddings');

        $this->assertEquals(
            1,
            EmbeddingRun::where('user_id', $this->comercio->id)->count(),
            'Con una tanda viva no se abre otra.'
        );
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        // Y ahora la misma tanda, pero vieja: deja de bloquear y se la marca vencida.
        $this->envejecer_tanda($abierta, FinalizeEmbeddingRun::MINUTOS_PARA_VENCER + 5);

        Queue::fake();
        Artisan::call('articles:generate-embeddings');

        $this->assertEquals(
            'vencida',
            EmbeddingRun::find($abierta->id)->status,
            'Una tanda abandonada no puede bloquear al comando para siempre.'
        );
        $this->assertEquals(
            2,
            EmbeddingRun::where('user_id', $this->comercio->id)->count(),
            'Y recién ahí se abre la tanda nueva.'
        );
        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
    }

    /**
     * Caso 10. Un job que muere definitivamente suma a `fallados` y no traba el cierre: la
     * tanda cierra con lo que hay y avisa solo por los vectores que se escribieron.
     *
     * `failed()` corre una sola vez, al agotar los tres `tries` — no en cada reintento —, así
     * que sumar ahí es lo único que no cuenta un mismo artículo tres veces.
     *
     * @group whatsapp
     * @test
     */
    public function un_job_que_muere_suma_a_fallados_y_no_traba_el_cierre_de_la_tanda()
    {
        $this->dar_extension();
        Queue::fake();

        $bueno = $this->articulo('Llave que sí se vectoriza');
        $malo  = $this->articulo('Llave que revienta en OpenAI');

        $tanda = $this->crear_tanda(['total_jobs' => 2]);

        $this->fingir_openai();

        (new GenerateArticleEmbeddingJob($bueno->id, $tanda->id))
            ->handle(app(ArticleEmbeddingService::class));

        (new GenerateArticleEmbeddingJob($malo->id, $tanda->id))
            ->failed(new \RuntimeException('OpenAI respondió 500 tres veces seguidas'));

        $tanda = EmbeddingRun::find($tanda->id);

        $this->assertEquals(1, (int) $tanda->generados);
        $this->assertEquals(1, (int) $tanda->fallados);
        $this->assertEquals(2, (int) $tanda->terminados, 'El fallo también cuenta como terminado, si no la tanda espera para siempre.');

        Event::fake([ArticleEmbeddingsBatchGenerated::class]);

        (new FinalizeEmbeddingRun($tanda->id))->handle();

        Event::assertDispatched(
            ArticleEmbeddingsBatchGenerated::class,
            function ($evento) {
                return (int) $evento->generados === 1;
            }
        );

        $this->assertEquals(
            'completada',
            EmbeddingRun::find($tanda->id)->status,
            'Un job muerto no deja la tanda colgada: cierra por contadores, no por vencimiento.'
        );
    }
}
