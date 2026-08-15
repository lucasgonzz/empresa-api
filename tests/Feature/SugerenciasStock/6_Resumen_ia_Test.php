<?php

namespace Tests\Feature\SugerenciasStock;

use App\Jobs\GenerarResumenSugerenciaJob;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\Address;
use App\Models\Article;
use App\Models\StockSuggestion;
use App\Models\User;
use App\Services\StockSuggestion\ResumenIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión sugerencias-inteligentes-stock — P7: el resumen IA que nunca bloquea.
 *
 * Se prueban los TRES caminos, no solo el feliz: respuesta válida (listo),
 * API caída con 529 (error, pero la sugerencia sigue terminada y usable), y
 * sin ANTHROPIC_API_KEY (el job ni se despacha, estado null — no tener IA
 * contratada no es un error y no debe mostrarse como tal).
 */
class Resumen_ia_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $origen;

    /** @var Address */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->comercio = User::create([
            'name'     => 'Comercio sugerencias P7',
            'email'    => 'sugerencias-p7-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito central P7',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal quiebre P7',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Artículo con déficit para que el pipeline genere al menos una línea.
     *
     * @param string $nombre
     * @return Article
     */
    protected function articulo_en_deficit($nombre)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        $article->addresses()->attach($this->origen->id, [
            'amount' => 100, 'stock_min' => 10, 'stock_max' => 20,
        ]);
        $article->addresses()->attach($this->destino->id, [
            'amount' => 2, 'stock_min' => 10, 'stock_max' => 20,
        ]);

        return $article;
    }

    /**
     * Corre el pipeline completo (sync) y devuelve la sugerencia fresca.
     *
     * @return StockSuggestion
     */
    protected function correr_sugerencia()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        (new GenerateStockSuggestionChunksJob($suggestion->id))->handle();

        return $suggestion->fresh();
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_respuesta_valida_el_resumen_queda_listo()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $this->articulo_en_deficit('Martillo resumen feliz');
        $suggestion = $this->correr_sugerencia();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Convendría mover stock del depósito central a la sucursal antes del quiebre.'],
                ],
            ], 200),
        ]);

        (new GenerarResumenSugerenciaJob($suggestion->id))->handle();

        $suggestion->refresh();

        $this->assertEquals('listo', $suggestion->resumen_ia_estado);
        $this->assertEquals(
            'Convendría mover stock del depósito central a la sucursal antes del quiebre.',
            $suggestion->resumen_ia
        );
        $this->assertNull($suggestion->resumen_ia_error);
        $this->assertEquals('terminado', $suggestion->status);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_529_el_resumen_queda_en_error_pero_la_sugerencia_sigue_terminada()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $this->articulo_en_deficit('Martillo resumen caido');
        $suggestion = $this->correr_sugerencia();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        (new GenerarResumenSugerenciaJob($suggestion->id))->handle();

        $suggestion->refresh();

        $this->assertEquals('error', $suggestion->resumen_ia_estado);
        $this->assertNull($suggestion->resumen_ia);
        $this->assertNotEmpty($suggestion->resumen_ia_error);
        $this->assertEquals(
            'terminado',
            $suggestion->status,
            'Ninguna falla de Anthropic puede impedir ver la tabla: la sugerencia tiene que seguir terminada.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function sin_clave_el_job_no_se_despacha_y_el_estado_queda_null()
    {
        config(['services.anthropic.api_key' => '']);
        Queue::fake();

        $this->articulo_en_deficit('Martillo sin IA');
        $suggestion = $this->correr_sugerencia();

        Queue::assertNotPushed(GenerarResumenSugerenciaJob::class);

        $this->assertNull(
            $suggestion->resumen_ia_estado,
            'Sin ANTHROPIC_API_KEY el estado tiene que quedar null: no tener IA contratada no es un error.'
        );
        $this->assertEquals('terminado', $suggestion->status);
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function con_clave_el_job_se_despacha_y_el_estado_arranca_pendiente()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $this->articulo_en_deficit('Martillo resumen pendiente');
        $suggestion = $this->correr_sugerencia();

        Queue::assertPushed(GenerarResumenSugerenciaJob::class);

        $this->assertEquals(
            'pendiente',
            $suggestion->resumen_ia_estado,
            'El estado tiene que quedar pendiente desde el despacho, para que la vista muestre el spinner sin esperar al worker.'
        );
    }

    /**
     * @group sugerencias-stock
     * @test
     */
    public function el_prompt_lleva_los_numeros_calculados_y_la_orden_de_no_recalcular()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $this->articulo_en_deficit('Martillo del prompt');
        $suggestion = $this->correr_sugerencia();

        $prompt = (new ResumenIaService())->armar_prompt($suggestion);

        // Los datos ya calculados viajan en el prompt...
        $this->assertStringContainsString('Martillo del prompt', $prompt);
        $this->assertStringContainsString('Deposito central P7', $prompt);
        $this->assertStringContainsString('Sucursal quiebre P7', $prompt);
        // needed = stock_min (10) - amount (2) = 8 unidades a mover.
        $this->assertStringContainsString('8 unidades', $prompt);
        $this->assertStringContainsString('Lineas de traslado sugeridas: 1', $prompt);

        // ...y las reglas duras que le prohíben inventar números.
        $this->assertStringContainsString('No recalcules ni inventes cantidades', $prompt);
        $this->assertStringContainsString('Maximo 6 oraciones', $prompt);
        $this->assertStringContainsString('Sin listas ni markdown', $prompt);
    }
}
