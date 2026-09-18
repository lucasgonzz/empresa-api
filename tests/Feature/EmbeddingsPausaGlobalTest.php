<?php

namespace Tests\Feature;

use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Observers\ArticleObserver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión busqueda-lenta-y-pausa-embeddings (14/9/2026) — EMBEDDINGS_GENERACION_PAUSADA corta
 * `articles:generate-embeddings` (el comando agendado, y el disparo post-importación que usa
 * FinalizeArticleImport) sin depender de la extensión whatsapp_ia de cada cliente.
 *
 * Es un interruptor DISTINTO de whatsapp_ia (que sigue siendo por cliente, y que además habilita
 * la búsqueda del bot de WhatsApp -- apagarla apagaría las dos cosas) y DISTINTO de
 * EMBEDDINGS_OMITIR_IMPORTACION (pensada para instancias de demo, no para cortar el parque
 * entero). El caso de uso: poder frenar la generación en TODO el parque de un saque si el
 * performance vuelve a ser un problema, o como red antes de que el arreglo de sinEmbedding()
 * (ArticleController/SearchController, misma misión) esté probado en producción.
 *
 * El chequeo de la pausa vive ANTES del gate de la extensión (a diferencia del de
 * OPENAI_API_KEY, que va después): ver EmbeddingsPausaGlobalTest::test_la_pausa_corta_incluso_sin_la_extension.
 *
 * Mismo andamiaje que EmbeddingsSinApiKeyTest: comercio propio, extensión whatsapp_ia,
 * app.USER_ID apuntando al comercio, y ArticleObserver::resetear_cache_gate() porque el memo del
 * gate es estático y compartido con el observer.
 *
 * La variable se lee con config('services.openai.embeddings_generacion_pausada'), NUNCA con
 * env() directo en el código de aplicación (chequeo independiente, 14/9/2026: con config:cache
 * activo en producción env() fuera de config/ devuelve el default, mismo bug que ya rompió
 * DURACION_REPORTES en Fenix -- ver config/services.php). Por eso el helper de abajo prende y
 * apaga con config(), no con $_SERVER/$_ENV/putenv: eso probaría que env() cambió, no que el
 * código lee lo que config:cache serviría en producción.
 */
class EmbeddingsPausaGlobalTest extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'whatsapp_ia';

    /** Variable de la pausa global. */
    const VARIABLE_PAUSA = 'EMBEDDINGS_GENERACION_PAUSADA';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca las claves reales del .env.testing: ningún caso de acá sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        // Por si un test anterior de este proceso quedó con la pausa prendida.
        $this->activar_pausa_global(false);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio pausa global',
            'company_name' => 'Ferreteria pausa global',
            'email'        => 'embeddings-pausa-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El comando resuelve el dueño de la instancia por acá, igual que el scheduler.
        config(['app.USER_ID' => $this->comercio->id]);

        // Clave válida por default en esta suite: lo único que se quiere frenar es la pausa, no
        // el gate de OPENAI_API_KEY (ese ya lo prueba EmbeddingsSinApiKeyTest aparte).
        config(['services.openai.api_key' => 'clave-de-prueba']);
    }

    protected function tearDown(): void
    {
        $this->activar_pausa_global(false);
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    /**
     * Prende o apaga la pausa donde el código realmente la lee: la clave de config, no la
     * variable de entorno (ver el docblock de la clase).
     *
     * @param  bool  $encendida
     * @return void
     */
    protected function activar_pausa_global($encendida)
    {
        config(['services.openai.embeddings_generacion_pausada' => $encendida]);
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
     * Un artículo pendiente de vectorizar (sin embedding). Se crea con Queue::fake() puesto para
     * que el despacho del observer no cuente, y después se vuelve a fakear la cola.
     *
     * @return Article
     */
    protected function articulo_pendiente()
    {
        Queue::fake();

        $articulo = Article::create([
            'name'    => 'Amoladora pausa global',
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ]);

        Queue::fake();

        return $articulo;
    }

    /**
     * Con la pausa prendida: el comando avisa, sale con 0, no despacha nada y no abre tanda --
     * aunque la extensión esté activa y la clave de OpenAI sea válida. Cubre también el disparo
     * post-importación (--ignorar-importacion-en-curso), que llama al mismo comando.
     *
     * @return void
     */
    public function test_con_la_pausa_prendida_el_comando_avisa_y_no_despacha_ningun_job()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        $this->activar_pausa_global(true);

        $exit = Artisan::call('articles:generate-embeddings');

        $this->assertSame(0, $exit, 'La pausa no es un error del comando: sale limpio para no ensuciar el log del scheduler.');
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertNotPushed(FinalizeEmbeddingRun::class);
        $this->assertSame(0, EmbeddingRun::where('user_id', $this->comercio->id)->count(), 'Con la pausa prendida no se abre ninguna tanda.');
        $this->assertStringContainsString(self::VARIABLE_PAUSA, Artisan::output());
    }

    /**
     * El mismo escenario con la pausa apagada despacha: la pausa era lo único que frenaba.
     *
     * @return void
     */
    public function test_con_la_pausa_apagada_el_mismo_escenario_despacha()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        $this->activar_pausa_global(false);

        Artisan::call('articles:generate-embeddings');

        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);
        $this->assertSame(1, EmbeddingRun::where('user_id', $this->comercio->id)->count());
    }

    /**
     * El chequeo de la pausa va ANTES del gate de la extensión (a diferencia del de
     * OPENAI_API_KEY, que va después a propósito -- ver ese bloque en
     * GenerateArticleEmbeddings::handle()): con la pausa prendida el comando corta aunque el
     * comercio ni siquiera tenga whatsapp_ia. Fija la decisión de orden para que no se mueva sin
     * querer.
     *
     * @return void
     */
    public function test_la_pausa_corta_incluso_sin_la_extension()
    {
        // Sin dar_extension(): este comercio no tiene whatsapp_ia.
        $this->articulo_pendiente();

        $this->activar_pausa_global(true);

        $exit = Artisan::call('articles:generate-embeddings');

        $this->assertSame(0, $exit);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        $this->assertStringContainsString(self::VARIABLE_PAUSA, Artisan::output());
    }

    /**
     * La guarda cubre también el disparo post-importación de FinalizeArticleImport, que llama al
     * mismo comando con --ignorar-importacion-en-curso.
     *
     * @return void
     */
    public function test_la_pausa_cubre_el_disparo_post_importacion()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        $this->activar_pausa_global(true);

        Artisan::call('articles:generate-embeddings', [
            '--origen'                        => 'importacion',
            '--ignorar-importacion-en-curso'  => true,
        ]);

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertNotPushed(FinalizeEmbeddingRun::class);
        $this->assertStringContainsString(self::VARIABLE_PAUSA, Artisan::output());
    }
}
