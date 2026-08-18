<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión personalizacion-agente-whatsapp — Parte A: habilidades del agente (`agent_skills`).
 *
 * Mismo criterio que la personalidad configurable (13_Vision_del_agente_Test.php es la
 * referencia de setup y del patrón de captura del payload de Anthropic), con una diferencia
 * a propósito: las habilidades NO tienen un default fijo. Sin habilidades configuradas el
 * prompt tiene que quedar byte por byte igual al de antes de esta misión — es la garantía de
 * no-regresión para las empresas que no configuren nada.
 *
 * 🔴 Lo más importante que protege este archivo es que guardar `agent_skills` no pise
 * `agent_personality` ni las tres credenciales técnicas, y viceversa: las dos pantallas del
 * front (Conexión y Agente) pegan al mismo endpoint mandando cada una sus propios campos, y
 * agrupar dos campos en un mismo `if ($request->has(...))` hace que la pantalla que manda uno
 * le escriba `null`/`false` al otro.
 */
class Habilidades_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Personalidad cargada por el dueño; ninguna edición de las habilidades la puede pisar. */
    const PERSONALIDAD = 'Sos el vendedor de la ferretería del barrio, tuteás y sos breve.';

    /** Habilidades cargadas por el dueño. */
    const HABILIDADES = 'Sos experto en ferretería y bulonería. Manejás medidas en pulgadas y milímetros.';

    /** Fragmento estable de las reglas fijas del system prompt (WhatsappBotAiService::FIXED_RULES). */
    const FRAGMENTO_REGLAS_FIJAS = 'Reglas fijas que nunca se pueden saltear';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    /** Payload que viajó a Anthropic (lo captura el stub). */
    protected $payload_anthropic = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp habilidades',
            'company_name' => 'Ferreteria habilidades',
            'email'        => 'whatsapp-habilidades-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-habilidades',
            'phone_number_id'    => '5491100000016',
            'webhook_secret'     => 'secreto-habilidades',
            'is_active'          => true,
            'ai_enabled_default' => true,
            'agent_personality'  => self::PERSONALIDAD,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'phone'                  => '5493416013316',
            'ai_enabled'             => true,
            'unread_count'           => 0,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 0,
        ]);
    }

    /**
     * Stub de las dos APIs externas, capturando lo que viaja a Anthropic (system incluido).
     *
     * @return void
     */
    protected function fakes_de_red()
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payload_anthropic = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => 'Sí, tenemos ese producto.']],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /**
     * Mensaje entrante de texto plano, para que haya historial y dispare la generación.
     *
     * @param string $body
     * @return WhatsappChatMessage
     */
    protected function entrante_de_texto($body)
    {
        return WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => $body,
        ]);
    }

    /**
     * Dispara la generación de la respuesta con la config vigente y devuelve el `system`
     * capturado en `$this->payload_anthropic`.
     *
     * @return string
     */
    protected function generar_y_obtener_system()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config->fresh());

        $this->assertNotNull($this->payload_anthropic, 'La llamada a Anthropic tiene que haber salido.');

        return (string) ($this->payload_anthropic['system'] ?? '');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_columna_agent_skills_existe_y_es_nullable()
    {
        $this->assertTrue(Schema::hasColumn('whatsapp_bot_configs', 'agent_skills'));

        // Nullable: una fila sin habilidades cargadas no puede reventar en MySQL estricto.
        $sin_habilidades = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id + 1000000,
            'kapso_api_key'   => 'kapso-sin-habilidades',
            'phone_number_id' => '5491100000099',
            'webhook_secret'  => 'secreto-sin-habilidades',
            'is_active'       => false,
        ]);

        $this->assertNull($sin_habilidades->fresh()->agent_skills);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_dueno_guarda_las_habilidades_y_las_recupera()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->comercio, 'web');

        $this->putJson('api/whatsapp-bot/config', ['agent_skills' => self::HABILIDADES])
            ->assertStatus(200)
            ->assertJsonPath('model.agent_skills', self::HABILIDADES);

        $respuesta = $this->getJson('api/whatsapp-bot/config')->assertStatus(200);
        $this->assertEquals(self::HABILIDADES, $respuesta->json('model.agent_skills'));
    }

    /**
     * 🔴 El caso más importante del archivo: guardar SOLO agent_skills no puede pisar la
     * personalidad ni las tres credenciales técnicas que ya estaban cargadas.
     *
     * @group whatsapp
     * @test
     */
    public function un_guardado_de_habilidades_no_pisa_la_personalidad_ni_las_credenciales()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->comercio, 'web');

        // Precondición: personalidad + las tres credenciales técnicas ya cargadas (en setUp).
        $this->assertEquals(self::PERSONALIDAD, $this->config->fresh()->agent_personality);

        $this->putJson('api/whatsapp-bot/config', ['agent_skills' => self::HABILIDADES])
            ->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertEquals(self::HABILIDADES, $guardada->agent_skills, 'Las habilidades sí se tienen que haber guardado.');
        $this->assertEquals(self::PERSONALIDAD, $guardada->agent_personality, 'La personalidad no se toca.');
        $this->assertEquals('kapso-habilidades', $guardada->kapso_api_key, 'La credencial kapso_api_key no se toca.');
        $this->assertEquals('5491100000016', $guardada->phone_number_id, 'La credencial phone_number_id no se toca.');
        $this->assertEquals('secreto-habilidades', $guardada->webhook_secret, 'La credencial webhook_secret no se toca.');
    }

    /**
     * La contracara del caso anterior: guardar las credenciales técnicas (la pantalla de
     * Conexión) no puede borrar las habilidades ya cargadas (la pantalla de Agente).
     *
     * @group whatsapp
     * @test
     */
    public function un_guardado_de_credenciales_no_borra_las_habilidades()
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->actingAs($this->comercio, 'web');

        $this->config->agent_skills = self::HABILIDADES;
        $this->config->save();

        $this->putJson('api/whatsapp-bot/config', [
            'kapso_api_key'   => 'kapso-nueva',
            'phone_number_id' => '5491100099999',
            'webhook_secret'  => 'secreto-nuevo',
        ])->assertStatus(200);

        $guardada = $this->config->fresh();
        $this->assertEquals('kapso-nueva', $guardada->kapso_api_key);
        $this->assertEquals(self::HABILIDADES, $guardada->agent_skills, 'Guardar las credenciales NO puede borrar las habilidades.');
    }

    /**
     * Las habilidades viajan como capa propia del system prompt, después de la personalidad
     * y antes de las reglas fijas (que siguen ganando ante cualquier conflicto).
     *
     * @group whatsapp
     * @test
     */
    public function las_habilidades_viajan_en_el_system_prompt_despues_de_la_personalidad_y_antes_de_las_reglas_fijas()
    {
        $this->fakes_de_red();

        $this->config->agent_skills = self::HABILIDADES;
        $this->config->save();

        $this->entrante_de_texto('¿tenés tornillos de 3 pulgadas?');

        $system = $this->generar_y_obtener_system();

        $pos_personalidad = mb_strpos($system, self::PERSONALIDAD);
        $pos_habilidades  = mb_strpos($system, self::HABILIDADES);
        $pos_reglas_fijas = mb_strpos($system, self::FRAGMENTO_REGLAS_FIJAS);

        $this->assertNotFalse($pos_personalidad, 'La personalidad tiene que estar en el system prompt.');
        $this->assertNotFalse($pos_habilidades, 'Las habilidades tienen que estar en el system prompt.');
        $this->assertNotFalse($pos_reglas_fijas, 'Las reglas fijas tienen que estar en el system prompt.');

        $this->assertTrue($pos_personalidad < $pos_habilidades, 'La personalidad va antes que las habilidades.');
        $this->assertTrue($pos_habilidades < $pos_reglas_fijas, 'Las habilidades van antes que las reglas fijas.');
    }

    /**
     * 🔴 Garantía de no-regresión: con `agent_skills` vacío, el prompt queda IDÉNTICO al que se
     * generaba antes de esta misión — nada de encabezado huérfano ni de separador de más.
     *
     * Con `implode("\n\n", $blocks)`, agregar un bloque vacío en vez de omitirlo dejaría un
     * `"\n\n\n\n"` entre la personalidad y las reglas fijas (dos separadores seguidos, con
     * nada en el medio): es la forma concreta que tomaría el bug si alguien sacara el `if`
     * que guarda el bloque de habilidades.
     *
     * @group whatsapp
     * @test
     */
    public function con_agent_skills_vacio_el_prompt_queda_identico_al_de_antes_sin_encabezado_huerfano()
    {
        $this->fakes_de_red();

        // agent_skills nunca se cargó (queda null, como en setUp).
        $this->assertNull($this->config->fresh()->agent_skills);

        $this->entrante_de_texto('¿tenés tornillos de 3 pulgadas?');

        $system = $this->generar_y_obtener_system();

        $this->assertStringStartsWith(self::PERSONALIDAD, $system);
        $this->assertStringNotContainsString(
            "\n\n\n\n",
            $system,
            'Sin habilidades no puede quedar un bloque vacío colado entre la personalidad y las reglas fijas.'
        );

        // Justo después de la personalidad y su separador tiene que venir directo el fragmento
        // de las reglas fijas: nada de un bloque de habilidades vacío en el medio.
        $resto = mb_substr($system, mb_strlen(self::PERSONALIDAD) + mb_strlen("\n\n"));
        $this->assertStringStartsWith(self::FRAGMENTO_REGLAS_FIJAS, $resto);
    }
}
