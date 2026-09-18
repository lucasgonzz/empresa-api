<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\TopeDeTokensHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión foto-sucursal-y-asistente-configurable — config del agente, consumo y corte por tope.
 *
 * Protege: la config del agente (confianza/pensamiento) se guarda y se lee del DUEÑO, valida los
 * enums y está detrás del gate; GET mi-consumo-ia devuelve las claves del contrato; TopeDeTokensHelper
 * corta solo con un tope > 0; y el corte por tope de send_message contesta con el texto de límite sin
 * despachar el job.
 *
 * 🔴 Anthropic con clave nula o Http::fake: los tests jamás salen a la red.
 */
class Config_y_tope_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio config P23',
            'company_name' => 'Ferreteria P23',
            'email'        => 'config-p23-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado config P23',
            'email'    => 'config-p23-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * Asigna la extensión al comercio (el gate resuelve al dueño).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /** @test */
    public function guarda_y_lee_la_config_del_dueno()
    {
        $this->dar_extension();

        $r = $this->actingAs($this->comercio, 'sanctum')
                  ->putJson('api/user/asistente-config', ['confianza' => 'cauteloso', 'pensamiento' => 'profundo']);

        $r->assertStatus(200)->assertJson(['confianza' => 'cauteloso', 'pensamiento' => 'profundo']);

        $this->assertSame('cauteloso', (string) $this->comercio->fresh()->agente_confianza);
        $this->assertSame('profundo', (string) $this->comercio->fresh()->agente_pensamiento);

        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(200)
             ->assertJson(['confianza' => 'cauteloso', 'pensamiento' => 'profundo']);
    }

    /** @test */
    public function un_enum_invalido_corta_con_422_sin_guardar()
    {
        $this->dar_extension();

        $this->actingAs($this->comercio, 'sanctum')
             ->putJson('api/user/asistente-config', ['confianza' => 'super_suelto', 'pensamiento' => 'agil'])
             ->assertStatus(422);

        // No se tocó nada: la columna sigue con su default.
        $this->assertNotSame('super_suelto', (string) $this->comercio->fresh()->agente_confianza);
    }

    /** @test */
    public function la_config_es_del_dueno_aunque_la_guarde_un_admin_access()
    {
        $this->dar_extension();
        $this->empleado->admin_access = 1;
        $this->empleado->save();

        $this->actingAs($this->empleado, 'sanctum')
             ->putJson('api/user/asistente-config', ['confianza' => 'resuelto', 'pensamiento' => 'profundo'])
             ->assertStatus(200);

        // Se guardó en el DUEÑO, no en el empleado.
        $this->assertSame('profundo', (string) $this->comercio->fresh()->agente_pensamiento);
    }

    /** @test */
    public function sin_extension_el_gate_corta()
    {
        // Sin dar_extension(): check_extencion_empresa corta.
        $this->actingAs($this->comercio, 'sanctum')
             ->getJson('api/user/asistente-config')
             ->assertStatus(403);
    }

    /** @test */
    public function mi_consumo_devuelve_las_claves_del_contrato()
    {
        $this->dar_extension();
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->plan_ia_nombre = 'Básico';
        $this->comercio->plan_ia_tope_tokens_mensual = 1000000;
        $this->comercio->plan_ia_tope_interacciones_diarias = 20;
        $this->comercio->save();

        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 500, 300, 3);

        $r = $this->actingAs($this->comercio, 'sanctum')->getJson('api/mi-consumo-ia');

        $r->assertStatus(200)
          ->assertJsonStructure([
              'consumo_mes' => ['tokens', 'interacciones'],
              'plan'        => ['nombre', 'tope_tokens_mensual', 'tope_interacciones_diarias'],
              'cerca', 'supero', 'pensamiento', 'confianza',
          ])
          ->assertJson([
              'plan'        => ['nombre' => 'Básico', 'tope_tokens_mensual' => 1000000, 'tope_interacciones_diarias' => 20],
              'pensamiento' => 'agil',
              'confianza'   => 'resuelto',
          ]);

        // 3 filas chat_mensaje = 3 interacciones; (500+300)*3 = 2400 tokens.
        $this->assertSame(2400, $r->json('consumo_mes.tokens'));
        $this->assertSame(3, $r->json('consumo_mes.interacciones'));
    }

    /** @test */
    public function el_tope_no_corta_cuando_no_esta_definido()
    {
        $this->comercio->plan_ia_tope_tokens_mensual = null;
        $this->comercio->plan_ia_tope_interacciones_diarias = null;
        $this->comercio->save();

        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 999999, 999999, 100);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertFalse($estado['supero']);
        $this->assertFalse($estado['cerca']);
    }

    /** @test */
    public function el_tope_supera_y_avisa_cerca()
    {
        // Tope de interacciones = 10; con 8 filas está "cerca" (80%) pero no superó.
        $this->comercio->plan_ia_tope_interacciones_diarias = 10;
        $this->comercio->plan_ia_tope_tokens_mensual = null;
        $this->comercio->save();

        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 1, 1, 8);
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertTrue($estado['cerca']);
        $this->assertFalse($estado['supero']);

        // Con 2 más (10) superó.
        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 1, 1, 2);
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertTrue($estado['supero']);
    }

    /** @test */
    public function send_message_corta_por_tope_sin_despachar_el_job()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->plan_ia_tope_interacciones_diarias = 5;
        $this->comercio->save();
        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 10, 10, 5); // superó

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $r = $this->actingAs($this->comercio, 'sanctum')
                  ->postJson('api/ai-conversations/' . $conversation->id . '/messages', [
                      'contenido' => '¿Cómo venís hoy?',
                      'acciones'  => true,
                  ]);

        $r->assertStatus(201);
        $this->assertSame('listo', $r->json('assistant_message.estado'));
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, $r->json('assistant_message.contenido'));

        // Lo central: NO se gastó una llamada a la API.
        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }

    /** @test */
    public function el_service_elige_el_modelo_por_la_preferencia_del_dueno()
    {
        config([
            'services.anthropic.api_key'        => 'clave-de-prueba',
            'services.anthropic.model_agil'     => 'modelo-agil-test',
            'services.anthropic.model_profundo' => 'modelo-profundo-test',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'listo']],
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
                'model'       => 'lo-que-sea',
            ], 200),
        ]);

        $this->comercio->agente_pensamiento = 'profundo';
        $this->comercio->save();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new AsistenteIaService())->responder($conversation, $assistant);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            return isset($body['model']) && $body['model'] === 'modelo-profundo-test';
        });
    }

    /**
     * Siembra $veces filas de ai_token_usages del proceso dado, en el día de hoy.
     *
     * @param  int  $owner_id
     * @param  string  $proceso
     * @param  int  $input
     * @param  int  $output
     * @param  int  $veces
     * @return void
     */
    protected function sembrar_consumo($owner_id, $proceso, $input, $output, $veces)
    {
        for ($i = 0; $i < $veces; $i++) {
            AiTokenUsage::create([
                'user_id'                     => $owner_id,
                'auth_user_id'                => $owner_id,
                'proceso'                     => $proceso,
                'proveedor'                   => 'anthropic',
                'modelo'                      => 'claude-sonnet-5',
                'input_tokens'                => $input,
                'output_tokens'               => $output,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
                'created_at'                  => Carbon::now(),
                'updated_at'                  => Carbon::now(),
            ]);
        }
    }

    /**
     * Conversación del dueño con un pedido y el assistant pendiente.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Hola',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        return [$conversation, $assistant];
    }
}
