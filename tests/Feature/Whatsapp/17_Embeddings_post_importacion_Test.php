<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\FinalizeArticleImport;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use App\Models\User;
use App\Observers\ArticleObserver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión embeddings-por-lote-y-semilla — F/17: la vectorización que se dispara apenas
 * termina una importación de Excel.
 *
 * El agujero que tapa: mientras dura una importación los dos observers de embeddings se
 * saltean a propósito cada artículo que se guarda (`ArticleObserver::debe_generar_embedding()`
 * corta si hay un `ImportStatus` en 'en_proceso'), porque encolar un job por cada fila de un
 * Excel de 20.000 sería una tormenta sobre artículos que además siguen cambiando. La única
 * red que levantaba eso era el comando agendado cada treinta minutos. O sea que un lead que
 * importaba su catálogo y se iba derecho a probar el agente de WhatsApp podía preguntar por
 * un artículo recién importado y que el agente no lo encontrara.
 *
 * 🔴 EL CASO DEL MEDIO ES EL QUE HAY QUE LEER: `--ignorar-importacion-en-curso`.
 *
 * El gate del comando mira TODOS los `ImportStatus` del usuario, no el de la importación que
 * acaba de terminar. Un registro huérfano en 'en_proceso' de una corrida que murió sin pasar
 * por `ImportFailureHandler` (worker matado, OOM crudo) deja el comando mudo PARA SIEMPRE —
 * nadie limpia esas filas: `DetectarImportacionesColgadas` filtra por `ImportHistory`, no por
 * `ImportStatus`.
 *
 * La opción existe para un solo llamador. El caso
 * `el_import_status_huerfano_solo_lo_ignora_el_llamador_con_la_opcion` fija las DOS mitades
 * en el mismo escenario —sin la opción no pasa nada, con la opción corre igual—, que es lo
 * único que impide que alguien "simplifique" la opción de vuelta y rompa el caso sin que
 * ningún test se queje.
 *
 * Estilo calcado de `6_Embeddings_observer_Test.php`: `DatabaseTransactions`, claves de API en
 * null, broadcast en null y `ArticleObserver::resetear_cache_gate()` cada vez que cambian las
 * condiciones (el memo del gate es estático y sobrevive entre casos del mismo proceso).
 */
class Embeddings_post_importacion_Test extends TestCase
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
        config(['broadcasting.default' => 'null']);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio embeddings importacion',
            'company_name' => 'Ferreteria importacion',
            'email'        => 'embeddings-import-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando resuelve el dueño por config y NO por el user_id del job, igual que el
        // scheduler. Si esto no está seteado, el comando sale en 0 sin hacer nada.
        config(['app.USER_ID' => $this->comercio->id]);

        // Ninguna red, ni siquiera por accidente: si algún camino intentara salir, esto lo
        // corta y `Http::assertNothingSent()` lo denunciaría.
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    /**
     * Asigna la extensión al comercio y olvida el memo del gate.
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
     * Estado de importación del comercio.
     *
     * @param string $status           'pendiente' | 'en_proceso' | 'completado' | 'fallo'.
     * @param int    $total_chunks
     * @param int    $processed_chunks
     * @return ImportStatus
     */
    protected function import_status($status, $total_chunks = 1, $processed_chunks = 1)
    {
        return ImportStatus::create([
            'user_id'          => $this->comercio->id,
            'status'           => $status,
            'total_chunks'     => $total_chunks,
            'processed_chunks' => $processed_chunks,
        ]);
    }

    /**
     * @return ImportHistory
     */
    protected function import_history()
    {
        return ImportHistory::create([
            'user_id'    => $this->comercio->id,
            'model_name' => 'Article',
        ]);
    }

    /**
     * Caso 1. Terminada la importación, `FinalizeArticleImport` abre una tanda de embeddings
     * con `origen = 'importacion'` en vez de dejar al lead esperando hasta media hora.
     *
     * Se engancha ahí y no en el controller porque es el último eslabón del `Bus::chain` que
     * arma `InitExcelImport`: corre una sola vez y, si faltaban chunks, ya se re-despachó solo
     * mucho antes de llegar.
     *
     * @group whatsapp
     * @test
     */
    public function finalizar_una_importacion_abre_una_tanda_de_embeddings_con_origen_importacion()
    {
        $this->dar_extension();
        Queue::fake();

        // El artículo se crea con la importación ya cerrada: lo que importa es que quede
        // pendiente de vectorizar (embedding IS NULL).
        $this->articulo('Artículo recién importado');

        $import_status  = $this->import_status('completado', 2, 2);
        $import_history = $this->import_history();

        Queue::fake();

        (new FinalizeArticleImport(
            $this->comercio->id,
            $import_history->id,
            $import_status->id
        ))->handle();

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();

        $this->assertNotNull($tanda, 'Terminada la importación se abre la tanda, sin esperar al scheduler.');
        $this->assertEquals('importacion', $tanda->origen, 'El origen viaja hasta el aviso, así que no es solo auditoría.');
        $this->assertEquals('en_proceso', $tanda->status);
        $this->assertEquals(1, (int) $tanda->total_jobs);

        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
    }

    /**
     * Caso 2. 🔴 EL CANDADO DE LA OPCIÓN.
     *
     * Mismo escenario las dos veces —un `ImportStatus` huérfano en 'en_proceso' de una corrida
     * muerta— y las dos mitades del comportamiento:
     *
     * 1. Sin `--ignorar-importacion-en-curso`, el comando no hace absolutamente nada. Es el
     *    modo de falla real: un registro huérfano que nadie limpia dejando la vectorización
     *    muda para siempre.
     * 2. Con la opción, corre igual y abre su tanda.
     *
     * El orden importa: la mitad "sin la opción" va PRIMERO, porque si fuera al revés la
     * tanda que abre la primera mitad activaría la guarda anti-solapamiento y la segunda
     * mitad daría verde por el motivo equivocado.
     *
     * @group whatsapp
     * @test
     */
    public function el_import_status_huerfano_solo_lo_ignora_el_llamador_con_la_opcion()
    {
        $this->dar_extension();

        // Basura de una corrida que murió: nadie la va a limpiar nunca.
        $this->import_status('en_proceso', 3, 1);

        ArticleObserver::resetear_cache_gate();
        Queue::fake();

        $this->articulo('Artículo que nadie vectoriza');

        // Mitad 1: como lo llama el scheduler. El gate corta y no pasa nada.
        Queue::fake();
        Artisan::call('articles:generate-embeddings');

        $this->assertEquals(
            0,
            EmbeddingRun::where('user_id', $this->comercio->id)->count(),
            'Sin la opción, un ImportStatus huérfano deja el comando mudo. Esa es la falla que la opción viene a tapar.'
        );
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        // Mitad 2: como lo llama FinalizeArticleImport, y solo él.
        Queue::fake();
        Artisan::call('articles:generate-embeddings', [
            '--origen'                       => 'importacion',
            '--ignorar-importacion-en-curso' => true,
        ]);

        $tanda = EmbeddingRun::where('user_id', $this->comercio->id)->first();

        $this->assertNotNull($tanda, 'Con la opción el comando corre igual, que es todo el punto.');
        $this->assertEquals('importacion', $tanda->origen);
        $this->assertEquals(1, (int) $tanda->total_jobs);
        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
    }

    /**
     * Caso 3. Con chunks pendientes, `FinalizeArticleImport` se re-despacha y no toca los
     * embeddings.
     *
     * Vectorizar a mitad de importación sería exactamente lo que el gate de los observers
     * evita: artículos que todavía están cambiando.
     *
     * @group whatsapp
     * @test
     */
    public function si_faltan_chunks_la_importacion_se_redespacha_y_no_dispara_embeddings()
    {
        $this->dar_extension();
        Queue::fake();

        $this->articulo('Artículo a medio importar');

        $import_status  = $this->import_status('en_proceso', 3, 1);
        $import_history = $this->import_history();

        ArticleObserver::resetear_cache_gate();
        Queue::fake();

        (new FinalizeArticleImport(
            $this->comercio->id,
            $import_history->id,
            $import_status->id
        ))->handle();

        Queue::assertPushed(FinalizeArticleImport::class, 1);

        $this->assertEquals(
            0,
            EmbeddingRun::where('user_id', $this->comercio->id)->count(),
            'Todavía faltan chunks: no se abre ninguna tanda.'
        );
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        Http::assertNothingSent();
    }
}
