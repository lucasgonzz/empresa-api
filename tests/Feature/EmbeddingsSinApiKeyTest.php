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
 * Misión optimizacion-vps-fase1 (10/9/2026, release 4.0.24) — articles:generate-embeddings no
 * despacha nada sin OPENAI_API_KEY.
 *
 * El caso real: el segundo frente de ferretotal se instaló sin la clave y el comando siguió
 * encolando cada media hora; 35.324 jobs fallidos. Lo que se fija acá es que con la clave vacía
 * (o de solo espacios) el comando avisa y sale sin abrir tanda ni despachar un job, que con la
 * clave puesta el mismo escenario sí despacha (o sea, la guarda es lo único que lo frenó), que
 * sin la extensión sigue en silencio como siempre, y que la guarda cubre también el disparo
 * post-importación (--ignorar-importacion-en-curso).
 *
 * Mismo andamiaje que Whatsapp/16: comercio propio, extensión whatsapp_ia, app.USER_ID apuntando
 * al comercio, y `ArticleObserver::resetear_cache_gate()` porque el memo del gate es estático.
 */
class EmbeddingsSinApiKeyTest extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'whatsapp_ia';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca las claves reales del .env.testing: ningún caso de acá sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio sin clave',
            'company_name' => 'Ferreteria sin clave',
            'email'        => 'embeddings-sin-clave-' . uniqid() . '@test.local',
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
            'name'    => 'Amoladora sin clave',
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ]);

        Queue::fake();

        return $articulo;
    }

    /**
     * Con la clave vacía: warn, exit 0, ningún job, ninguna tanda.
     *
     * @return void
     */
    public function test_sin_clave_el_comando_avisa_y_no_despacha_ningun_job()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        config(['services.openai.api_key' => '']);

        $exit = Artisan::call('articles:generate-embeddings');

        $this->assertSame(0, $exit, 'Sin clave no es un error del comando: sale limpio para no ensuciar el log del scheduler.');
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertNotPushed(FinalizeEmbeddingRun::class);
        $this->assertSame(0, EmbeddingRun::where('user_id', $this->comercio->id)->count(), 'Sin clave no se abre ninguna tanda.');
        $this->assertStringContainsString('OPENAI_API_KEY', Artisan::output());
    }

    /**
     * Una clave de solo espacios es una clave vacía (trim).
     *
     * @return void
     */
    public function test_una_clave_de_solo_espacios_cuenta_como_vacia()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        config(['services.openai.api_key' => '   ']);

        Artisan::call('articles:generate-embeddings');

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        $this->assertStringContainsString('OPENAI_API_KEY', Artisan::output());
    }

    /**
     * El mismo escenario con una clave cargada despacha: la guarda es lo único que frenaba.
     *
     * @return void
     */
    public function test_con_clave_el_mismo_escenario_despacha()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        config(['services.openai.api_key' => 'clave-de-prueba']);

        Artisan::call('articles:generate-embeddings');

        Queue::assertPushed(GenerateArticleEmbeddingJob::class, 1);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);
        $this->assertSame(1, EmbeddingRun::where('user_id', $this->comercio->id)->count());
        $this->assertStringNotContainsString('OPENAI_API_KEY', Artisan::output());
    }

    /**
     * Sin la extensión el comando sigue en silencio, aunque tampoco haya clave: la guarda va
     * después del gate de la extensión para no avisarle cada 30 minutos a quien no tiene el módulo.
     *
     * @return void
     */
    public function test_sin_la_extension_sigue_en_silencio_aunque_falte_la_clave()
    {
        $this->articulo_pendiente();

        config(['services.openai.api_key' => '']);

        $exit = Artisan::call('articles:generate-embeddings');

        $this->assertSame(0, $exit);
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        $this->assertStringNotContainsString('OPENAI_API_KEY', Artisan::output());
    }

    /**
     * La guarda cubre también el disparo post-importación de FinalizeArticleImport, que llama al
     * mismo comando con --ignorar-importacion-en-curso.
     *
     * @return void
     */
    public function test_la_guarda_cubre_el_disparo_post_importacion()
    {
        $this->dar_extension();
        $this->articulo_pendiente();

        config(['services.openai.api_key' => '']);

        Artisan::call('articles:generate-embeddings', [
            '--origen'                        => 'importacion',
            '--ignorar-importacion-en-curso'  => true,
        ]);

        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);
        Queue::assertNotPushed(FinalizeEmbeddingRun::class);
        $this->assertStringContainsString('OPENAI_API_KEY', Artisan::output());
    }
}
