<?php

namespace Tests\Feature\PruebaGratuita;

use App\Exceptions\CupoEmbeddingsDePruebaException;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\ExtencionEmpresa;
use App\Models\PruebaConfig;
use App\Services\ArticleEmbeddingService;
use App\Services\CupoEmbeddingsDePrueba;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

class CupoEmbeddingsDePruebaTest extends EmpresaTestCase
{
    private const URL = 'https://api.openai.com/v1/embeddings';

    private $dueno;
    private $prueba;
    private $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->dueno = auth()->user();
        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'services.openai.api_key' => 'clave-falsa',
            'services.openai.embeddings_generacion_pausada' => false,
            'services.openai.embeddings_omitir_importacion' => true,
        ]);
        PruebaHelper::olvidar_cache();
        $this->prueba = PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        $this->prueba->created_at = now()->subDay();
        $this->prueba->embeddings_tokens_usados = null;
        $this->prueba->save();
        $this->servicio = app(ArticleEmbeddingService::class);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_recupera_el_consumo_historico_y_concilia_la_reserva_con_openai(): void
    {
        $this->registrar_historico(ArticleEmbeddingService::PROCESO_ARTICULOS, 100, now());
        $this->registrar_historico(ArticleEmbeddingService::PROCESO_ARTICULOS, 10000, now()->subDays(2));
        $this->registrar_historico(ArticleEmbeddingService::PROCESO_BUSQUEDA, 10000, now());
        Http::fake([
            self::URL => function () {
                $this->assertSame(8292, $this->tokens_usados());
                return Http::response($this->respuesta(200));
            },
        ]);

        $this->indexar();

        $this->assertSame(300, $this->tokens_usados());
        Http::assertSentCount(1);
    }

    public function test_no_deja_reservar_dos_llamadas_por_encima_del_tope(): void
    {
        $this->prueba->update(['embeddings_tokens_usados' => 49991808]);
        Http::fake([
            self::URL => function () {
                // Otra indexación pide presupuesto mientras la primera espera a OpenAI.
                try {
                    CupoEmbeddingsDePrueba::comprobar((int) $this->dueno->id, true);
                    $this->fail('La segunda llamada no debería conseguir una reserva.');
                } catch (CupoEmbeddingsDePruebaException $exception) {
                    $this->assertSame(50000000, $this->tokens_usados());
                }

                return Http::response($this->respuesta(200));
            },
        ]);

        $this->indexar();

        $this->assertSame(49992008, $this->tokens_usados());
        Http::assertSentCount(1);
    }

    public function test_devuelve_solo_las_reservas_de_rechazos_definitivos(): void
    {
        Http::fake([
            self::URL => Http::sequence()
                ->push([], 400)
                ->push([], 500)
                ->push(['data' => [['embedding' => [1.0]]]]),
        ]);

        foreach ([400 => 0, 500 => 8192] as $status => $tokens) {
            try {
                $this->indexar();
                $this->fail('Se esperaba un error de OpenAI.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString("HTTP $status", $exception->getMessage());
                $this->assertSame($tokens, $this->tokens_usados());
            }
        }

        $this->indexar();
        $this->assertSame(16384, $this->tokens_usados());
        Http::assertSentCount(3);
    }

    public function test_cupo_agotado_detiene_el_comando_y_saltean_los_jobs_ya_encolados(): void
    {
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'agentes_ia'], ['name' => 'Agentes de IA']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $articulo = Article::create(['name' => 'Artículo nuevo', 'user_id' => $this->dueno->id]);
        $tanda = EmbeddingRun::create([
            'user_id' => $this->dueno->id,
            'origen' => 'importacion',
            'total_jobs' => 1,
        ]);
        $this->prueba->update(['embeddings_tokens_usados' => 50000000]);
        Http::fake();

        (new GenerateArticleEmbeddingJob($articulo->id, $tanda->id))->handle($this->servicio);
        $this->assertSame(1, (int) $tanda->fresh()->salteados);
        $this->assertNull($articulo->fresh()->embedding_generated_at);
        Queue::fake();
        $this->assertSame(0, Artisan::call('articles:generate-embeddings'));
        $this->assertStringContainsString('presupuesto de embeddings', Artisan::output());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_solo_rige_para_la_indexacion_de_la_prueba_vigente(): void
    {
        Http::fake([self::URL => Http::response($this->respuesta(200))]);
        $this->prueba->update(['embeddings_tokens_usados' => 50000000]);
        $this->assertFalse(CupoEmbeddingsDePrueba::omitir_importacion((int) $this->dueno->id));

        $this->servicio->generate_embedding('Consulta de un comprador', $this->dueno->id, ArticleEmbeddingService::PROCESO_BUSQUEDA);
        Http::assertSentCount(1);

        DB::table('users')->where('id', $this->dueno->id)->update(['expired_at' => now()->subMinute()]);
        try {
            $this->indexar();
            $this->fail('Una prueba vencida no puede seguir indexando.');
        } catch (CupoEmbeddingsDePruebaException $exception) {
            $this->assertStringContainsString('ya terminó', $exception->getMessage());
        }
        Http::assertSentCount(1);

        DB::table('users')->where('id', $this->dueno->id)->update(['expired_at' => null]);
        $this->assertFalse(CupoEmbeddingsDePrueba::omitir_importacion((int) $this->dueno->id));
        $this->indexar();
        $this->assertSame(50000000, $this->tokens_usados());
        Http::assertSentCount(2);

        config(['app.FOR_USER' => 'demo']);
        $this->assertTrue(CupoEmbeddingsDePrueba::omitir_importacion((int) $this->dueno->id));
    }

    private function indexar(): void
    {
        $this->servicio->generate_embedding('Descripción de un artículo', $this->dueno->id, ArticleEmbeddingService::PROCESO_ARTICULOS);
    }

    private function tokens_usados(): int
    {
        return (int) PruebaConfig::whereKey($this->prueba->id)->value('embeddings_tokens_usados');
    }

    private function registrar_historico(string $proceso, int $tokens, $fecha): void
    {
        AiTokenUsage::create([
            'user_id' => $this->dueno->id,
            'proveedor' => ArticleEmbeddingService::PROVEEDOR,
            'modelo' => ArticleEmbeddingService::EMBEDDING_MODEL,
            'proceso' => $proceso,
            'input_tokens' => $tokens,
            'created_at' => $fecha,
        ]);
    }

    private function respuesta(int $tokens): array
    {
        return [
            'data' => [['embedding' => [1.0]]],
            'usage' => ['prompt_tokens' => $tokens],
        ];
    }
}
