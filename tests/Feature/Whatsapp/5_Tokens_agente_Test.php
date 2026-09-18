<?php

namespace Tests\Feature\Whatsapp;

use App\Models\AiTokenUsage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión whatsapp-agente — F8/5: registro del consumo de tokens del agente.
 *
 * Hasta esta misión, todo lo que el agente de WhatsApp gastaba en Anthropic no quedaba
 * registrado en ningún lado. Ahora las tres puertas de entrada graban en
 * `ai_token_usages` con procesos distintos (D6), porque son la misma llamada HTTP pero
 * con dueños del gasto diferentes:
 *
 *   - `whatsapp_respuesta`  → la respuesta automática (job). Sin persona: auth_user_id null.
 *   - `whatsapp_sugerencia` → el botón "Sugerir respuesta". Lo pidió alguien.
 *   - `whatsapp_resumen`    → el resumen de la conversación. Lo pidió alguien.
 *
 * También se protege que el metering nunca cambie el resultado visible: una respuesta sin
 * bloque `usage` graba la fila en cero y devuelve el texto igual, y una llamada fallida a
 * Anthropic no graba SU fila (no hay consumo que imputarle).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * 🔴 DESDE EL 17/9/2026 (misión tokens-por-cliente) UNA RESPUESTA DEL AGENTE SON DOS FILAS,
 * NO UNA. NO ES UN DOBLE CONTEO Y NO SE "ARREGLA" SUMÁNDOLAS.
 *
 * Esta suite nació asumiendo "una respuesta = una llamada paga", y esa cuenta era incompleta:
 * antes de hablarle a Anthropic, `generate_response_with_photo()` llama a OpenAI para la
 * búsqueda semántica que arma el contexto del catálogo (`search_similar_articles()`, el RAG).
 * Esa llamada SIEMPRE se pagó; lo único que pasaba es que nadie la anotaba. Así que ahora
 * cada respuesta deja:
 *
 *   - una fila `whatsapp_respuesta` / `whatsapp_sugerencia`, proveedor `anthropic`;
 *   - una fila `embeddings_busqueda`, proveedor `openai`, sin persona (lo dispara el RAG).
 *
 * Son dos proveedores distintos, con precios distintos, y el admin los costea por separado:
 * fusionarlas perdería justamente el número que esta misión vino a hacer visible — que el RAG
 * corre en CADA respuesta de WhatsApp y es el gasto que escala con el tráfico.
 *
 * Por eso las aserciones filtran por `proceso` en vez de contar la tabla entera. El caso de la
 * llamada fallida es el más elocuente: cuando Anthropic se cae, igual queda una fila, porque
 * el embedding ya se había pagado.
 *
 * `generate_summary()` NO dispara el RAG (el resumen se arma solo con el historial del chat),
 * así que sus dos tests siguen contando la tabla entera a propósito: esa cuenta de 1 es lo que
 * protege que el resumen no empiece a pagar una búsqueda que no necesita.
 * ─────────────────────────────────────────────────────────────────────────────────────────
 */
class Tokens_agente_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea los endpoints del módulo. */
    const SLUG = 'whatsapp';

    /** Los `prompt_tokens` que devuelve el fake de OpenAI para la búsqueda semántica. */
    const TOKENS_DEL_EMBEDDING = 42;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp F8-5',
            'company_name' => 'Ferreteria F8-5',
            'email'        => 'whatsapp-f8-5-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado whatsapp F8-5',
            'email'    => 'whatsapp-f8-5-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-f8-5',
            'phone_number_id' => '5491100000005',
            'webhook_secret'  => 'secreto-f8-5',
            'is_active'       => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416003344',
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Tenés tornillos de 3 pulgadas?',
        ]);
    }

    /**
     * Asigna la extensión al comercio (creándola si la base del slot no la tiene sembrada).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Stubs de red. El `'*'` final evita que una request sin stub salga de verdad.
     *
     * @param array $respuesta_anthropic Body con el que responde api.anthropic.com.
     * @param int   $status
     * @return void
     */
    protected function fakes_de_red(array $respuesta_anthropic, $status = 200)
    {
        Http::fake([
            /*
             * El `usage` de OpenAI va con la forma REAL de /v1/embeddings: `prompt_tokens` y
             * `total_tokens`, sin `output_tokens` ni caché. Sin él, la fila del embedding se
             * grabaría en cero y estos tests no verían la diferencia entre "se registró bien"
             * y "se registró vacío", que es justo lo que hay que poder distinguir.
             */
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => self::TOKENS_DEL_EMBEDDING, 'total_tokens' => self::TOKENS_DEL_EMBEDDING],
            ], 200),
            'api.anthropic.com/*' => Http::response($respuesta_anthropic, $status),
            '*'                   => Http::response([], 200),
        ]);
    }

    /**
     * Body típico de Anthropic con bloque `usage` completo.
     *
     * @param string $texto
     * @return array
     */
    protected function respuesta_con_usage($texto)
    {
        return [
            'model'   => 'claude-modelo-de-prueba',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage'   => [
                'input_tokens'                => 321,
                'output_tokens'               => 45,
                'cache_creation_input_tokens' => 10,
                'cache_read_input_tokens'     => 5,
            ],
        ];
    }

    /**
     * Filas de consumo del comercio del test; con `$proceso`, solo las de ese proceso.
     *
     * Filtrar por proceso es lo que vuelve a estas aserciones MÁS específicas desde que una
     * respuesta paga dos llamadas (ver el bloque rojo de la clase): contar la tabla entera ya
     * no distingue "se grabó la de Anthropic" de "se grabó la del embedding".
     *
     * @param  string|null $proceso
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function consumos($proceso = null)
    {
        $query = AiTokenUsage::where('user_id', $this->comercio->id);

        if (! is_null($proceso)) {

            $query->where('proceso', $proceso);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Afirma que la búsqueda semántica del RAG dejó su fila, con lo que efectivamente costó.
     *
     * @return \App\Models\AiTokenUsage
     */
    protected function assertEmbeddingRegistrado()
    {
        $filas = $this->consumos('embeddings_busqueda');

        $this->assertCount(1, $filas, 'Cada respuesta del agente paga además su búsqueda semántica en OpenAI.');

        $fila = $filas[0];
        $this->assertEquals('openai', $fila->proveedor, 'El RAG no le paga a Anthropic: el proveedor se guarda, no se adivina.');
        $this->assertEquals(self::TOKENS_DEL_EMBEDDING, (int) $fila->input_tokens);
        $this->assertEquals(0, (int) $fila->output_tokens, 'Un embedding no genera texto.');
        $this->assertNull($fila->auth_user_id, 'El RAG lo dispara el armado del contexto, no una persona.');
        $this->assertEquals($this->comercio->id, (int) $fila->user_id);

        return $fila;
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_respuesta_automatica_graba_una_fila_con_los_tokens_del_usage()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Sí, tenemos.'));

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Sí, tenemos.', $texto);

        $filas = $this->consumos('whatsapp_respuesta');
        $this->assertCount(1, $filas, 'La llamada a Anthropic deja exactamente una fila de consumo.');

        $fila = $filas[0];
        $this->assertEquals('anthropic', $fila->proveedor);
        $this->assertEquals(321, (int) $fila->input_tokens);
        $this->assertEquals(45, (int) $fila->output_tokens);
        $this->assertEquals(10, (int) $fila->cache_creation_input_tokens);
        $this->assertEquals(5, (int) $fila->cache_read_input_tokens);
        $this->assertEquals($this->chat->id, (int) $fila->referencia_id);
        $this->assertEquals($this->comercio->id, (int) $fila->user_id, 'El gasto se le imputa al dueño de la cuenta.');
        $this->assertNull($fila->auth_user_id, 'La respuesta automática no la pidió nadie: auth_user_id queda null.');
        $this->assertNotEquals('', (string) $fila->modelo);

        /* La otra mitad de lo que cuesta una respuesta: la búsqueda semántica del catálogo. */
        $this->assertEmbeddingRegistrado();

        /* Y nada más que eso: dos llamadas pagas, dos filas. */
        $this->assertCount(2, $this->consumos(), 'Una respuesta del agente son exactamente dos llamadas pagas.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_resumen_graba_su_propio_proceso()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('El cliente preguntó por tornillos.'));

        $texto = (new WhatsappBotAiService())->generate_summary($this->chat);

        $this->assertEquals('El cliente preguntó por tornillos.', $texto);

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals('whatsapp_resumen', $filas[0]->proceso);
        $this->assertEquals($this->chat->id, (int) $filas[0]->referencia_id);
    }

    /**
     * D6: la sugerencia es la misma llamada HTTP que la respuesta automática, pero el
     * gasto tiene otro dueño. Sin procesos separados, Lucas no puede distinguir lo que
     * gasta el bot solo de lo que le piden a mano.
     *
     * @group whatsapp
     * @test
     */
    public function el_endpoint_de_sugerencia_imputa_el_gasto_a_la_persona_que_lo_pidio()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Te sugiero contestar esto.'));

        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $this->chat->id . '/suggest');

        $response->assertStatus(200);
        $this->assertEquals('Te sugiero contestar esto.', $response->json('suggestion'));

        $filas = $this->consumos('whatsapp_sugerencia');
        $this->assertCount(1, $filas);
        $this->assertEquals('anthropic', $filas[0]->proveedor);
        $this->assertEquals($this->comercio->id, (int) $filas[0]->user_id, 'La cuenta es la del dueño...');
        $this->assertEquals($this->empleado->id, (int) $filas[0]->auth_user_id, '...pero el pedido fue de la persona.');

        /*
         * El embedding de esa misma sugerencia se le imputa al comercio PERO sin persona: lo
         * dispara el armado del contexto, no el click. Si algún día se le quisiera colgar el
         * auth_user_id, es una decisión a tomar a propósito, no algo que deba pasar solo.
         */
        $this->assertEmbeddingRegistrado();

        $this->assertCount(2, $this->consumos());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_endpoint_de_resumen_imputa_el_gasto_a_la_persona_que_lo_pidio()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red($this->respuesta_con_usage('Resumen pedido a mano.'));

        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $this->chat->id . '/summary');

        $response->assertStatus(200);
        $this->assertEquals('Resumen pedido a mano.', $response->json('summary'));

        $filas = $this->consumos();
        $this->assertCount(1, $filas);
        $this->assertEquals('whatsapp_resumen', $filas[0]->proceso);
        $this->assertEquals($this->empleado->id, (int) $filas[0]->auth_user_id);
    }

    /**
     * El metering es contabilidad de fondo: no puede cambiar lo que ve el cliente. Si la
     * respuesta viene sin bloque `usage`, la fila se graba igual en cero y el texto llega
     * intacto.
     *
     * @group whatsapp
     * @test
     */
    public function una_respuesta_sin_bloque_usage_graba_la_fila_en_cero_y_devuelve_el_texto()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red([
            'model'   => 'claude-modelo-de-prueba',
            'content' => [['type' => 'text', 'text' => 'Respuesta sin usage.']],
        ]);

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Respuesta sin usage.', $texto, 'El texto tiene que llegar igual.');

        $filas = $this->consumos('whatsapp_respuesta');
        $this->assertCount(1, $filas);
        $this->assertEquals(0, (int) $filas[0]->input_tokens);
        $this->assertEquals(0, (int) $filas[0]->output_tokens);
        $this->assertEquals('anthropic', $filas[0]->proveedor);

        /*
         * Y el cero es SOLO de Anthropic: el embedding de la misma respuesta sigue trayendo sus
         * tokens. Sin esta aserción, un bug que dejara los dos registros en cero se vería igual
         * que este caso, que es legítimo.
         */
        $this->assertEmbeddingRegistrado();
    }

    /**
     * 🔴 ESTE CASO ES EL QUE MEJOR MUESTRA POR QUÉ SON DOS FILAS Y NO UNA.
     *
     * Cuando Anthropic se cae, su fila NO se graba —no hubo consumo que imputarle— pero la del
     * embedding SÍ queda, porque el RAG corre ANTES y esa llamada ya se pagó. Contar la tabla
     * entera y esperar cero daría un test verde solo mientras el gasto del RAG siguiera
     * invisible, que es exactamente lo que esta misión vino a corregir.
     *
     * Dicho de otro modo: una respuesta que falla igual cuesta plata, y el cliente la paga.
     *
     * @group whatsapp
     * @test
     */
    public function una_llamada_fallida_a_anthropic_no_graba_su_fila_pero_si_la_del_embedding_ya_pagado()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $this->fakes_de_red(['error' => ['message' => 'se cayó todo']], 500);

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('', $texto);

        $this->assertCount(
            0,
            $this->consumos('whatsapp_respuesta'),
            'Sin respuesta válida no hay consumo que imputarle a Anthropic: esa fila no se graba.'
        );

        $this->assertEmbeddingRegistrado();

        $this->assertCount(
            1,
            $this->consumos(),
            'Queda una sola fila: la del embedding, que se pagó antes de que Anthropic fallara.'
        );
    }

    /**
     * Misión whatsapp-mejoras-interfaz (15/9/2026). Antes de este cambio, `suggest()` devolvía
     * 200 con `suggestion: ''` para CUALQUIER motivo de fallo interno del service —sin
     * conexión configurada, la API caída, sin historial— indistinguibles entre sí y del front,
     * que se quedaba sin ninguna sugerencia y sin ningún error a la vista. Este test fija el
     * caso más fácil de reproducir sin mockear Anthropic: un chat que todavía no tiene ningún
     * mensaje. Los otros motivos (`sin_configurar`, `error_api`, `excepcion`, `solo_foto`)
     * comparten el mismo camino de vuelta en el controller; no hace falta un test por cada uno.
     *
     * @group whatsapp
     * @test
     */
    public function sugerir_respuesta_sin_historial_devuelve_422_con_mensaje_explicito()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        // No debería llegar a pegarle a la red (el corte es antes), pero se fakea igual:
        // sin esto, un request sin stub sale a la red de verdad si el orden cambia mañana.
        $this->fakes_de_red($this->respuesta_con_usage('no debería usarse'));

        $chat_sin_mensajes = WhatsappChat::create([
            'user_id'    => $this->comercio->id,
            'phone'      => '5493416003399',
            'ai_enabled' => true,
        ]);

        $this->actingAs($this->empleado, 'web');

        $response = $this->postJson('api/whatsapp-chats/' . $chat_sin_mensajes->id . '/suggest');

        $response->assertStatus(422);
        $this->assertEquals('', $response->json('suggestion'));
        $this->assertStringContainsStringIgnoringCase(
            'no hay mensajes',
            (string) $response->json('message'),
            'El mensaje tiene que decir POR QUÉ no hay sugerencia, no solo que no la hay.'
        );
        $this->assertCount(0, $this->consumos(), 'Sin llamada a la IA, no hay consumo que imputar.');
    }
}
