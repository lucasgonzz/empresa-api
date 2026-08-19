<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Models\Address;
use App\Models\AiTokenUsage;
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
 * Misión chat-ia-y-modulo-ia — P3: metering de tokens y retrofit del resumen.
 *
 * Tres garantías: pedir_resumen() con user_id graba el consumo leído del
 * `usage` de la respuesta (y sin user_id no graba nada); el refactor que
 * extrajo armar_datos() no movió ni un byte del prompt del resumen (mismas
 * aserciones que el test 6 de sugerencias); y el helper de registro NUNCA
 * lanza, ni siquiera con datos rotos.
 */
class Metering_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // 🔴 Nunca la clave real del .env.testing: la cola sync saldría a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'     => 'Comercio chat-ia P3',
            'email'    => 'chat-ia-p3-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Sugerencia terminada con al menos una línea, corriendo el pipeline real.
     *
     * @return StockSuggestion
     */
    protected function correr_sugerencia()
    {
        $origen = Address::create([
            'street'  => 'Deposito central P3',
            'user_id' => $this->comercio->id,
        ]);
        $destino = Address::create([
            'street'  => 'Sucursal quiebre P3',
            'user_id' => $this->comercio->id,
        ]);

        $article = Article::create([
            'name'    => 'Martillo del metering P3',
            'user_id' => $this->comercio->id,
        ]);
        $article->addresses()->attach($origen->id, [
            'amount' => 100, 'stock_min' => 10, 'stock_max' => 20,
        ]);
        $article->addresses()->attach($destino->id, [
            'amount' => 2, 'stock_min' => 10, 'stock_max' => 20,
        ]);

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
     * @group chat-ia
     * @test
     */
    public function pedir_resumen_con_user_id_graba_una_fila_con_los_tokens_del_usage()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'   => 'claude-modelo-de-prueba',
                'content' => [
                    ['type' => 'text', 'text' => 'Resumen de prueba del metering.'],
                ],
                'usage'   => [
                    'input_tokens'                => 321,
                    'output_tokens'               => 45,
                    'cache_creation_input_tokens' => 10,
                    'cache_read_input_tokens'     => 5,
                ],
            ], 200),
        ]);

        $texto = (new ResumenIaService())->pedir_resumen(
            'Prompt de prueba del metering.',
            $this->comercio->id,
            777001
        );

        $this->assertEquals('Resumen de prueba del metering.', $texto);

        $filas = AiTokenUsage::where('user_id', $this->comercio->id)->get();

        $this->assertCount(1, $filas, 'Una llamada con user_id tiene que dejar exactamente una fila de consumo.');

        $fila = $filas[0];
        $this->assertEquals('resumen_sugerencia_stock', $fila->proceso);
        $this->assertEquals(321, (int) $fila->input_tokens);
        $this->assertEquals(45, (int) $fila->output_tokens);
        $this->assertEquals(10, (int) $fila->cache_creation_input_tokens);
        $this->assertEquals(5, (int) $fila->cache_read_input_tokens);
        $this->assertEquals(777001, (int) $fila->referencia_id);
        $this->assertNull($fila->auth_user_id, 'El resumen lo dispara un job sin persona: auth_user_id queda null.');
        $this->assertNotEquals('', (string) $fila->modelo);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function pedir_resumen_sin_user_id_no_graba_nada()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Resumen sin metering.'],
                ],
                'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
        ]);

        $antes = AiTokenUsage::count();

        (new ResumenIaService())->pedir_resumen('Prompt sin user_id.');

        $this->assertEquals(
            $antes,
            AiTokenUsage::count(),
            'Sin user_id no hay a quién imputar el gasto: no se graba ninguna fila.'
        );
    }

    /**
     * @group chat-ia
     * @test
     */
    public function una_falla_del_registro_no_lanza_jamas()
    {
        // Datos rotos a propósito: sin user_id, sin proceso, usage basura.
        AiTokenUsageHelper::registrar([]);
        AiTokenUsageHelper::registrar(['user_id' => 0, 'proceso' => '']);
        AiTokenUsageHelper::registrar([
            'user_id' => $this->comercio->id,
            'proceso' => 'chat_mensaje',
            'usage'   => 'esto-no-es-un-array',
            'body'    => 'esto-tampoco',
        ]);

        // La última llamada es válida (usage inválido degrada a contadores en 0).
        $fila = AiTokenUsage::where('user_id', $this->comercio->id)->first();
        $this->assertNotNull($fila, 'Con user_id y proceso válidos, la fila se graba aunque el usage venga roto.');
        $this->assertEquals(0, (int) $fila->input_tokens);

        // Y las dos primeras no dejaron basura sin dueño.
        $this->assertEquals(0, AiTokenUsage::where('user_id', 0)->count());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_refactor_no_movio_el_prompt_y_armar_datos_es_solo_los_numeros()
    {
        Queue::fake();

        $suggestion = $this->correr_sugerencia();
        $service = new ResumenIaService();

        $prompt = $service->armar_prompt($suggestion);

        // Las mismas aserciones que el test 6 de sugerencias: el refactor no movió el texto.
        $this->assertStringContainsString('Martillo del metering P3', $prompt);
        $this->assertStringContainsString('Deposito central P3', $prompt);
        $this->assertStringContainsString('Sucursal quiebre P3', $prompt);
        $this->assertStringContainsString('8 unidades', $prompt);
        $this->assertStringContainsString('Lineas de traslado sugeridas: 1', $prompt);
        $this->assertStringContainsString('No recalcules ni inventes cantidades', $prompt);
        $this->assertStringContainsString('Maximo 6 oraciones', $prompt);
        $this->assertStringContainsString('Sin listas ni markdown', $prompt);

        $datos = $service->armar_datos($suggestion);

        // armar_datos() es EXACTAMENTE el bloque de datos que viaja adentro del prompt...
        $this->assertStringContainsString($datos, $prompt, 'armar_prompt() tiene que componerse de instrucciones + armar_datos().');
        $this->assertStringContainsString('Datos ya calculados:', $datos);
        $this->assertStringContainsString('8 unidades', $datos);

        // ...sin ninguna instrucción de redacción: eso queda en armar_prompt().
        $this->assertStringNotContainsString('Sos el encargado de dep', $datos);
        $this->assertStringNotContainsString('Responde solo con el texto plano', $datos);
        $this->assertStringNotContainsString('Maximo 6 oraciones', $datos);
    }
}
