<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P4: el servicio del chat (prompt, historial,
 * tools y loop de tool use).
 *
 * Lo crítico acá es el loop copiado de admin-api: que el segundo request
 * reenvíe el bloque tool_use con `input` como OBJETO JSON (json_decode
 * convierte input:{} en array vacío y Anthropic rechaza el request con un
 * 400 críptico), que el tool_result lleve el tool_use_id correcto, que los
 * errores transitorios salgan con el mensaje amigable de la casa y que cada
 * iteración registre su propio consumo de tokens.
 */
class Asistente_service_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: los tests jamás salen a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P4',
            'company_name' => 'Ferreteria P4',
            'email'        => 'chat-ia-p4-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado chat-ia P4',
            'email'    => 'chat-ia-p4-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->service = new AsistenteIaService();
    }

    /**
     * Conversación de la persona con un user 'listo' y un assistant 'pendiente'.
     *
     * @param string $texto_usuario
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente($texto_usuario)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $texto_usuario,
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_end_turn_directo_devuelve_el_texto_y_graba_los_tokens()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [
                    ['type' => 'text', 'text' => 'Tenés 12 unidades de tornillos.'],
                ],
                'usage'       => ['input_tokens' => 500, 'output_tokens' => 30],
            ], 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('¿Cuántos tornillos tengo?');

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertEquals('Tenés 12 unidades de tornillos.', $texto);

        $filas = AiTokenUsage::where('ai_conversation_id', $conversation->id)->get();

        $this->assertCount(1, $filas, 'Una sola llamada a la API deja una sola fila de consumo.');
        $this->assertEquals('chat_mensaje', $filas[0]->proceso);
        $this->assertEquals(500, (int) $filas[0]->input_tokens);
        $this->assertEquals($this->comercio->id, (int) $filas[0]->user_id);
        $this->assertEquals($this->empleado->id, (int) $filas[0]->auth_user_id);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_loop_de_tool_use_reenvia_input_como_objeto_y_el_tool_result_con_su_id()
    {
        // Artículo real: la tool ejecuta la query de verdad contra la base.
        Article::create([
            'name'    => 'Tornillo P4 loop',
            'user_id' => $this->comercio->id,
            'stock'   => 7,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                // 1ª respuesta: Claude pide la tool de stock.
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [
                        ['type' => 'text', 'text' => 'Voy a consultar el stock.'],
                        [
                            'type'  => 'tool_use',
                            'id'    => 'toolu_test_01',
                            'name'  => 'consultar_stock_de_articulos',
                            'input' => ['busqueda' => 'Tornillo P4 loop'],
                        ],
                    ],
                    'usage'       => ['input_tokens' => 600, 'output_tokens' => 40],
                ], 200)
                // 2ª respuesta: con el tool_result ya puede contestar.
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'end_turn',
                    'content'     => [
                        ['type' => 'text', 'text' => 'Tenés 7 unidades de Tornillo P4 loop.'],
                    ],
                    'usage'       => ['input_tokens' => 700, 'output_tokens' => 25],
                ], 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('¿Cuántos tornillos P4 tengo?');

        $texto = $this->service->responder($conversation, $assistant);

        $this->assertEquals('Tenés 7 unidades de Tornillo P4 loop.', $texto);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded, 'El loop tiene que haber hecho exactamente dos llamadas.');

        // El segundo request se mira en el body CRUDO: es lo que Anthropic ve.
        $segundo_body = $recorded[1][0]->body();

        // El tool_result apunta al tool_use que lo originó...
        $this->assertStringContainsString('"tool_use_id":"toolu_test_01"', $segundo_body);
        // ...y lleva el resultado real de la query adentro.
        $this->assertStringContainsString('Tornillo P4 loop', $segundo_body);

        // El bloque tool_use reenviado tiene input como OBJETO JSON (llaves, no corchetes).
        $this->assertStringContainsString('"input":{"busqueda":"Tornillo P4 loop"}', $segundo_body);

        // Cada iteración del loop grabó su propia fila de consumo.
        $filas = AiTokenUsage::where('ai_conversation_id', $conversation->id)->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertEquals(600, (int) $filas[0]->input_tokens);
        $this->assertEquals(700, (int) $filas[1]->input_tokens);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_input_vacio_del_tool_use_se_reenvia_como_objeto_y_no_como_array()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                /*
                 * Claude llama a la tool SIN argumentos (no tiene obligatorios):
                 * el input:{} de la API llega a PHP como [] vía json_decode, y
                 * el reenvío tiene que castearlo de vuelta a {} o Anthropic
                 * rechaza el request. Este es el gotcha R12 del plan.
                 */
                ->push([
                    'stop_reason' => 'tool_use',
                    'content'     => [
                        [
                            'type'  => 'tool_use',
                            'id'    => 'toolu_test_02',
                            'name'  => 'consultar_articulos_mas_vendidos',
                            'input' => [],
                        ],
                    ],
                    'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
                ], 200)
                ->push([
                    'stop_reason' => 'end_turn',
                    'content'     => [
                        ['type' => 'text', 'text' => 'No hubo ventas en los últimos 30 días.'],
                    ],
                    'usage'       => ['input_tokens' => 120, 'output_tokens' => 12],
                ], 200),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('¿Qué es lo que más vendo?');

        $this->service->responder($conversation, $assistant);

        $segundo_body = Http::recorded()[1][0]->body();

        $this->assertStringContainsString(
            '"input":{}',
            $segundo_body,
            'El input vacío tiene que viajar como objeto {} y no como array []: Anthropic rechaza el array.'
        );
        $this->assertStringNotContainsString('"input":[]', $segundo_body);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_529_lanza_el_mensaje_amigable_de_error_transitorio()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente('¿Estás ahí?');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.'
        );

        $this->service->responder($conversation, $assistant);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_historial_funde_turnos_del_mismo_rol_y_descarta_assistant_inicial()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        $mensajes = [
            ['rol' => 'assistant', 'contenido' => 'Resumen de bienvenida', 'estado' => 'listo'],
            ['rol' => 'user',      'contenido' => 'hola',                  'estado' => 'listo'],
            ['rol' => 'user',      'contenido' => '¿estás?',               'estado' => 'listo'],
            ['rol' => 'assistant', 'contenido' => 'sí, acá estoy',         'estado' => 'listo'],
            ['rol' => 'assistant', 'contenido' => null,                    'estado' => 'error'],
            ['rol' => 'user',      'contenido' => 'dale, consultame algo', 'estado' => 'listo'],
            ['rol' => 'assistant', 'contenido' => null,                    'estado' => 'pendiente'],
        ];

        foreach ($mensajes as $mensaje) {
            AiMessage::create(array_merge(['ai_conversation_id' => $conversation->id], $mensaje));
        }

        $payload = $this->service->build_messages_payload($conversation);

        /*
         * El assistant inicial se descarta (la API exige arrancar en user),
         * los dos user seguidos se funden, y ni el error ni el pendiente
         * viajan. Queda: user (hola + ¿estás?), assistant, user.
         */
        $this->assertCount(3, $payload);
        $this->assertEquals('user', $payload[0]['role']);
        $this->assertEquals("hola\n¿estás?", $payload[0]['content']);
        $this->assertEquals('assistant', $payload[1]['role']);
        $this->assertEquals('sí, acá estoy', $payload[1]['content']);
        $this->assertEquals('user', $payload[2]['role']);
        $this->assertEquals('dale, consultame algo', $payload[2]['content']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_historial_respeta_el_techo_de_caracteres_protegiendo_lo_mas_nuevo()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        // Cuatro mensajes de 9000 caracteres y un último corto: 24k de techo
        // deja adentro el corto + dos grandes (9000 + 9000 + ~30 < 24000).
        $roles = ['user', 'assistant', 'user', 'assistant'];
        foreach ($roles as $indice => $rol) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => $rol,
                'contenido'          => 'M' . ($indice + 1) . ' ' . str_repeat('x', 8995),
            ]);
        }
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'M5 el ultimo mensaje corto',
        ]);

        $payload = $this->service->build_messages_payload($conversation);

        // Sobreviven M3 (user), M4 (assistant) y M5 (user): M2 hacía pasar el techo.
        $this->assertCount(3, $payload);
        $this->assertStringStartsWith('M3', $payload[0]['content']);
        $this->assertEquals('user', $payload[0]['role']);
        $this->assertStringStartsWith('M4', $payload[1]['content']);
        $this->assertEquals('M5 el ultimo mensaje corto', $payload[2]['content']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_historial_respeta_el_techo_de_treinta_mensajes()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        // 33 mensajes alternados arrancando en user: los últimos 30 son m4..m33.
        for ($i = 1; $i <= 33; $i++) {
            AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => ($i % 2 === 1) ? 'user' : 'assistant',
                'contenido'          => 'msj ' . $i,
            ]);
        }

        $payload = $this->service->build_messages_payload($conversation);

        /*
         * De los últimos 30 (m4..m33), m4 es assistant y se descarta para
         * arrancar en user: quedan 29 turnos, de msj 5 a msj 33.
         */
        $this->assertCount(29, $payload);
        $this->assertEquals('msj 5', $payload[0]['content']);
        $this->assertEquals('user', $payload[0]['role']);
        $this->assertEquals('msj 33', $payload[28]['content']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_system_lleva_la_prohibicion_de_markdown_y_el_contexto_cuando_existe()
    {
        $sin_contexto = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        $payload = $this->service->build_system_payload($sin_contexto, $this->comercio);

        $this->assertCount(1, $payload, 'Sin contexto viaja un solo bloque system.');
        $this->assertEquals(['type' => 'ephemeral'], $payload[0]['cache_control']);
        $this->assertStringContainsString('Ferreteria P4', $payload[0]['text']);
        $this->assertStringContainsString('Está prohibido el markdown', $payload[0]['text']);
        $this->assertStringContainsString('Solo podés LEER', $payload[0]['text']);
        $this->assertStringContainsString(now()->format('d/m/Y'), $payload[0]['text']);

        $con_contexto = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
            'origen'       => 'sugerencia_stock',
            'contexto'     => "Datos ya calculados:\n- Lineas de traslado sugeridas: 3",
        ]);

        $payload = $this->service->build_system_payload($con_contexto, $this->comercio);

        $this->assertCount(2, $payload, 'Con contexto viajan dos bloques system.');

        // El bloque 2 lleva el contexto SIN cache_control: el prefijo cacheable es solo el bloque 1.
        $this->assertArrayNotHasKey('cache_control', $payload[1]);
        $this->assertStringStartsWith(
            'Contexto de fondo de esta conversación (datos ya calculados por el sistema, no los recalcules):',
            $payload[1]['text']
        );
        $this->assertStringContainsString('Lineas de traslado sugeridas: 3', $payload[1]['text']);
    }
}
