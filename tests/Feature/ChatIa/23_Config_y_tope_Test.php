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
 * 🔴 Misión agente-memoria-y-sin-techo (30/10/2026), CONTRATO CAMBIADO A PROPÓSITO: una interacción ya no
 * es una fila `chat_mensaje` (una vuelta del loop) sino una RESPUESTA del agente, y el texto del corte
 * dice cuál tope se pasó (el diario de interacciones o el mensual de tokens). Los tests que sembraban
 * filas `chat_mensaje` para simular interacciones ahora siembran respuestas (sembrar_respuestas()), y
 * el corte por interacciones espera MENSAJE_LIMITE_DIARIO. Caso real: Pablo (Ferre Total) leyó «de este
 * mes» por el tope de HOY, con 106 filas `chat_mensaje` (una por vuelta del loop, no por pedido).
 *
 * 🔴 Anthropic con clave nula o Http::fake: los tests jamás salen a la red.
 */
class Config_y_tope_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea el módulo. */
    const SLUG = 'agentes_ia';

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
        $this->sembrar_respuestas(3);

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

        // (500+300)*3 = 2400 tokens; 3 respuestas del agente = 3 interacciones.
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
        $this->sembrar_respuestas(100);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertFalse($estado['supero']);
        $this->assertFalse($estado['cerca']);
    }

    /** @test */
    public function el_tope_supera_y_avisa_cerca()
    {
        // Tope de interacciones = 10; con 8 respuestas está "cerca" (80%) pero no superó.
        $this->comercio->plan_ia_tope_interacciones_diarias = 10;
        $this->comercio->plan_ia_tope_tokens_mensual = null;
        $this->comercio->save();

        $this->sembrar_respuestas(8);
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertTrue($estado['cerca']);
        $this->assertFalse($estado['supero']);

        // Con 2 más (10) superó.
        $this->sembrar_respuestas(2);
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());
        $this->assertTrue($estado['supero']);
        $this->assertTrue($estado['supero_interacciones']);
        $this->assertFalse($estado['supero_tokens']);
    }

    /**
     * Contrato cambiado a propósito (misión agente-memoria-y-sin-techo): el corte por el tope DIARIO de
     * interacciones dice que es el de hoy y que mañana se renueva, no «de este mes».
     *
     * @test
     */
    public function send_message_corta_por_tope_sin_despachar_el_job()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->plan_ia_tope_interacciones_diarias = 5;
        $this->comercio->save();
        $this->sembrar_respuestas(5); // superó

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
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO, $r->json('assistant_message.contenido'));

        // Lo central: NO se gastó una llamada a la API.
        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);

        // Y la respuesta del límite no cuenta como interacción: el consumo no sigue subiendo.
        $this->assertSame(5, TopeDeTokensHelper::estado($this->comercio->fresh())['consumo_interacciones']);
    }

    /**
     * El corte por el tope MENSUAL de tokens sigue diciendo «de este mes» (MENSAJE_LIMITE).
     *
     * @test
     */
    public function send_message_por_el_tope_mensual_dice_de_este_mes()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->plan_ia_tope_tokens_mensual = 1000;
        $this->comercio->plan_ia_tope_interacciones_diarias = 100;
        $this->comercio->save();
        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 1000, 500, 1); // superó el mensual

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
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, $r->json('assistant_message.contenido'));
        Queue::assertNotPushed(ResponderMensajeChatIaJob::class);
    }

    /**
     * 🔴 El corte se mide ANTES de crear la respuesta del turno: con un tope de 2 y una respuesta hecha,
     * el mensaje nuevo se contesta (antes de esta misión la respuesta pendiente recién creada ya contaba
     * y el corte llegaba una respuesta antes).
     *
     * @test
     */
    public function el_corte_se_mide_antes_de_crear_la_respuesta()
    {
        Queue::fake();
        $this->dar_extension();

        $this->comercio->plan_ia_tope_interacciones_diarias = 2;
        $this->comercio->save();
        $this->sembrar_respuestas(1);

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
        $this->assertSame('pendiente', $r->json('assistant_message.estado'));
        Queue::assertPushed(ResponderMensajeChatIaJob::class);

        // La que se está generando ya cuenta: la próxima corta.
        $this->assertSame(2, TopeDeTokensHelper::estado($this->comercio->fresh())['consumo_interacciones']);
    }

    /**
     * 🔴 El caso de Pablo: un pedido que encadena 8 vueltas graba 8 filas `chat_mensaje`, y es UNA
     * interacción, no 8.
     *
     * @test
     */
    public function una_respuesta_con_muchas_vueltas_es_una_sola_interaccion()
    {
        $this->comercio->plan_ia_tope_interacciones_diarias = 100;
        $this->comercio->save();

        $this->sembrar_consumo($this->comercio->id, 'chat_mensaje', 10, 10, 8);
        $this->sembrar_respuestas(1);

        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        $this->assertSame(1, $estado['consumo_interacciones']);
        $this->assertSame(160, $estado['consumo_tokens'], 'Los tokens de las 8 vueltas cuentan enteros contra el mensual.');
    }

    /**
     * Lo que no es una respuesta del agente a la persona no cuenta: los mensajes que escribe el sistema
     * solo (un recordatorio que abre su conversación, un aviso después de una respuesta), las respuestas
     * con el texto del límite, las conversaciones MCP y las respuestas de ayer. Y las de un empleado del
     * negocio sí (el tope es del negocio).
     *
     * @test
     */
    public function solo_cuentan_las_respuestas_de_hoy_a_una_persona()
    {
        $this->sembrar_respuestas(2);

        // Un recordatorio que abre su propia conversación (RecordatoriosDelAsistenteHelper).
        $recordatorio = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id, 'origen' => AiConversation::ORIGEN_WHATSAPP]);
        AiMessage::create(['ai_conversation_id' => $recordatorio->id, 'rol' => 'assistant', 'contenido' => 'Recordatorio: mañana se acredita el cheque 7.', 'estado' => 'listo']);

        // Un aviso después de una respuesta (AvisosDelAsistenteHelper).
        $con_aviso = $this->sembrar_respuestas(1);
        AiMessage::create(['ai_conversation_id' => $con_aviso->id, 'rol' => 'assistant', 'contenido' => 'Terminé de leer la factura.', 'estado' => 'listo']);

        // Las respuestas con el texto del límite (el corte mismo).
        foreach ([TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO] as $limite) {
            $cortada = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);
            AiMessage::create(['ai_conversation_id' => $cortada->id, 'rol' => 'user', 'contenido' => 'Hola', 'estado' => 'listo']);
            AiMessage::create(['ai_conversation_id' => $cortada->id, 'rol' => 'assistant', 'contenido' => $limite, 'estado' => 'listo']);
        }

        // Una conversación MCP (registro técnico de las herramientas de un cliente externo).
        $mcp = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id, 'origen' => AiConversation::ORIGEN_MCP]);
        AiMessage::create(['ai_conversation_id' => $mcp->id, 'rol' => 'user', 'contenido' => 'proponer_gasto', 'estado' => 'listo']);
        AiMessage::create(['ai_conversation_id' => $mcp->id, 'rol' => 'assistant', 'contenido' => '{}', 'estado' => 'listo']);

        // Una respuesta de ayer.
        $de_ayer = $this->sembrar_respuestas(1);
        AiMessage::where('ai_conversation_id', $de_ayer->id)->update(['created_at' => Carbon::now()->subDay()]);

        // Una respuesta a un empleado del negocio: cuenta.
        $this->sembrar_respuestas(1, $this->empleado->id);

        $this->assertSame(4, TopeDeTokensHelper::estado($this->comercio->fresh())['consumo_interacciones'], '2 + 1 (la del aviso) + 1 (la del empleado).');
    }

    /**
     * El texto del límite dice cuál tope se pasó: el diario de interacciones o el mensual de tokens (que
     * gana si se pasaron los dos: mañana no se arregla). Con un estado sin las claves nuevas, el de siempre.
     *
     * @test
     */
    public function el_texto_del_limite_dice_cual_tope_se_paso()
    {
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO, TopeDeTokensHelper::mensaje_limite(['supero' => true, 'supero_tokens' => false, 'supero_interacciones' => true]));
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite(['supero' => true, 'supero_tokens' => true, 'supero_interacciones' => false]));
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite(['supero' => true, 'supero_tokens' => true, 'supero_interacciones' => true]));
        $this->assertSame(TopeDeTokensHelper::MENSAJE_LIMITE, TopeDeTokensHelper::mensaje_limite(['supero' => true]));

        $this->assertSame('Llegaste al límite de mensajes de hoy de tu plan. Mañana se renueva; si necesitás más, escribinos para ampliarlo.', TopeDeTokensHelper::MENSAJE_LIMITE_DIARIO);
        $this->assertSame('Llegaste al límite de tu plan de este mes. Escribinos para ampliarlo.', TopeDeTokensHelper::MENSAJE_LIMITE, 'La constante de siempre queda.');

        // estado() suma las dos claves sin sacar ninguna de las de antes.
        $estado = TopeDeTokensHelper::estado($this->comercio->fresh());

        foreach (['consumo_tokens', 'consumo_interacciones', 'tope_tokens', 'tope_interacciones', 'supero', 'cerca', 'supero_tokens', 'supero_interacciones'] as $clave) {
            $this->assertArrayHasKey($clave, $estado);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function provider_pensamiento_y_modelo(): array
    {
        return [
            'agil (default del sistema)' => ['agil', 'modelo-agil-test'],
            'equilibrado'                => ['equilibrado', 'modelo-equilibrado-test'],
            'profundo'                   => ['profundo', 'modelo-profundo-test'],
        ];
    }

    /**
     * @test
     * @dataProvider provider_pensamiento_y_modelo
     */
    public function el_service_elige_el_modelo_por_la_preferencia_del_dueno($pensamiento, $modelo_esperado)
    {
        config([
            'services.anthropic.api_key'           => 'clave-de-prueba',
            'services.anthropic.model_agil'        => 'modelo-agil-test',
            'services.anthropic.model_equilibrado' => 'modelo-equilibrado-test',
            'services.anthropic.model_profundo'    => 'modelo-profundo-test',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'listo']],
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
                'model'       => 'lo-que-sea',
            ], 200),
        ]);

        $this->comercio->agente_pensamiento = $pensamiento;
        $this->comercio->save();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new AsistenteIaService())->responder($conversation, $assistant);

        Http::assertSent(function ($request) use ($modelo_esperado) {
            $body = json_decode($request->body(), true);
            return isset($body['model']) && $body['model'] === $modelo_esperado;
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
     * Siembra $veces respuestas del agente de hoy (misión agente-memoria-y-sin-techo: la unidad del tope
     * de interacciones): una conversación del comercio con $veces pares mensaje de la persona → respuesta.
     *
     * @param  int  $veces
     * @param  int|null  $auth_user_id  La persona de la conversación (null = el dueño).
     * @return AiConversation
     */
    protected function sembrar_respuestas($veces, $auth_user_id = null)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => is_null($auth_user_id) ? $this->comercio->id : $auth_user_id,
        ]);

        for ($i = 0; $i < $veces; $i++) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => 'user',
                'contenido'          => 'Pedido ' . $i,
                'estado'             => 'listo',
            ]);

            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => 'assistant',
                'contenido'          => 'Respuesta ' . $i,
                'estado'             => 'listo',
            ]);
        }

        return $conversation;
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
