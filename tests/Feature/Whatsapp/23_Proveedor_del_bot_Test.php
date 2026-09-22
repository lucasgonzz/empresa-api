<?php

namespace Tests\Feature\Whatsapp;

use App\Models\AiTokenUsage;
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
 * Misión proveedores-ia-deepseek (22/9/2026) — el bot de WhatsApp sigue al proveedor del DUEÑO.
 *
 * Protege: con el dueño en DeepSeek, `generate_response()` y `generate_summary()` pegan a
 * `api.deepseek.com/anthropic/v1/messages` con la clave de DeepSeek, el modelo GENERAL de DeepSeek
 * (`services.deepseek.model`, no el de la variante de pensamiento) y el thinking apagado, y las
 * filas de consumo dicen `deepseek`; con el dueño en Anthropic todo queda exactamente como hoy
 * (URL, clave, sin clave `thinking`, `proveedor = anthropic`); y sin clave del proveedor del dueño
 * (ni de otro), el bot no sale a la red.
 *
 * Siembra copiada de `5_Tokens_agente_Test`: esa suite ya documenta que una respuesta del agente
 * son DOS filas (la del proveedor y la del embedding del RAG en OpenAI); acá las aserciones filtran
 * por proceso, así que el RAG no molesta.
 *
 * 🔴 Ningún test sale a la red: Http::fake con los tres hosts y el `'*'` de red de seguridad.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Proveedor_del_bot_Test extends TestCase
{
    use DatabaseTransactions;

    /** La URL exacta del endpoint compatible con Anthropic de DeepSeek. */
    const URL_DEEPSEEK = 'https://api.deepseek.com/anthropic/v1/messages';

    /** La URL de siempre de Anthropic. */
    const URL_ANTHROPIC = 'https://api.anthropic.com/v1/messages';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: claves de prueba y modelos inventados.
        config([
            'services.anthropic.api_key' => 'clave-anthropic-de-prueba',
            'services.anthropic.model'   => 'claude-general-test',
            'services.deepseek.api_key'  => 'clave-deepseek-de-prueba',
            'services.deepseek.model'    => 'deepseek-general-test',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'services.openai.api_key'    => null,
        ]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio whatsapp P23',
            'company_name' => 'Ferreteria P23',
            'email'        => 'whatsapp-p23-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'         => $this->comercio->id,
            'kapso_api_key'   => 'kapso-p23',
            'phone_number_id' => '5491100000023',
            'webhook_secret'  => 'secreto-p23',
            'is_active'       => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416002323',
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
     * Stubs de red para los tres hosts. El `'*'` final evita que una request sin stub salga.
     *
     * @param  string  $texto  Lo que contestan los dos proveedores de chat.
     * @return void
     */
    protected function fakes_de_red($texto)
    {
        $respuesta = [
            'model'   => 'lo-que-diga-el-proveedor',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage'   => ['input_tokens' => 321, 'output_tokens' => 45],
        ];

        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => 42, 'total_tokens' => 42],
            ], 200),
            'api.deepseek.com/*'  => Http::response($respuesta, 200),
            'api.anthropic.com/*' => Http::response($respuesta, 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * Deja al dueño con el proveedor dado, guardado en la base (el bot lo lee por la config y
     * por el user_id del chat).
     *
     * @param  string  $proveedor
     * @param  string  $pensamiento
     * @return void
     */
    protected function dueno_en($proveedor, $pensamiento = 'agil')
    {
        $this->comercio->agente_proveedor = $proveedor;
        $this->comercio->agente_pensamiento = $pensamiento;
        $this->comercio->save();

        /* La config del bot ya puede tener cargada la relación `user`: se refresca para que vea el cambio. */
        $this->config = $this->config->fresh();
    }

    /**
     * El request de chat que salió (el que NO fue al embedding), decodificado: `[url, headers, body]`.
     *
     * @return array{0: string, 1: array, 2: array}
     */
    protected function el_request_de_chat()
    {
        $capturado = null;

        Http::assertSent(function ($request) use (&$capturado) {
            if (strpos($request->url(), 'api.openai.com') !== false) {

                return false;
            }

            $capturado = $request;

            return true;
        });

        $this->assertNotNull($capturado, 'Tendría que haber salido un request al proveedor de chat.');

        return [$capturado->url(), $capturado->headers(), json_decode($capturado->body(), true)];
    }

    /**
     * Filas de consumo del comercio del test, solo las del proceso dado.
     *
     * @param  string  $proceso
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function consumos($proceso)
    {
        return AiTokenUsage::where('user_id', $this->comercio->id)->where('proceso', $proceso)->orderBy('id')->get();
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_respuesta_del_bot_con_el_dueno_en_deepseek_va_a_deepseek_con_el_modelo_general()
    {
        $this->fakes_de_red('Sí, tenemos tornillos.');
        /* En profundo a propósito: el bot usa el modelo GENERAL, no el de la variante. */
        $this->dueno_en('deepseek', 'profundo');

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Sí, tenemos tornillos.', $texto);

        list($url, $headers, $body) = $this->el_request_de_chat();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(['clave-deepseek-de-prueba'], $headers['x-api-key'], 'La clave del header es la de DeepSeek.');
        $this->assertEquals(config('services.deepseek.model'), $body['model'], 'El bot usa el modelo GENERAL de DeepSeek, no el de la variante de pensamiento.');
        $this->assertEquals('disabled', $body['thinking']['type'], 'Las respuestas del bot son cortas y baratas: el thinking va apagado.');
        $this->assertEquals(500, $body['max_tokens'], 'Con el thinking apagado el techo de salida es el de siempre.');

        $filas = $this->consumos('whatsapp_respuesta');

        $this->assertCount(1, $filas);
        $this->assertEquals('deepseek', $filas[0]->proveedor, 'El gasto se registra con el proveedor que contestó.');
        $this->assertEquals(config('services.deepseek.model'), $filas[0]->modelo);
        $this->assertEquals(321, (int) $filas[0]->input_tokens);
        $this->assertEquals(45, (int) $filas[0]->output_tokens);
    }

    /**
     * `generate_summary()` no recibe la config: resuelve al dueño por el `user_id` del chat, y tiene
     * que llegar al mismo proveedor.
     *
     * @group whatsapp
     * @test
     */
    public function el_resumen_con_el_dueno_en_deepseek_va_a_deepseek_con_el_modelo_general()
    {
        $this->fakes_de_red('El cliente preguntó por tornillos.');
        $this->dueno_en('deepseek');

        $texto = (new WhatsappBotAiService())->generate_summary($this->chat);

        $this->assertEquals('El cliente preguntó por tornillos.', $texto);

        list($url, $headers, $body) = $this->el_request_de_chat();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(['clave-deepseek-de-prueba'], $headers['x-api-key']);
        $this->assertEquals(config('services.deepseek.model'), $body['model']);
        $this->assertEquals('disabled', $body['thinking']['type']);

        $filas = $this->consumos('whatsapp_resumen');

        $this->assertCount(1, $filas);
        $this->assertEquals('deepseek', $filas[0]->proveedor);
        $this->assertEquals(config('services.deepseek.model'), $filas[0]->modelo);
    }

    /**
     * Con el dueño en Anthropic NADA cambia: URL y clave de Anthropic, sin clave `thinking`, y la
     * fila dice `anthropic` con el modelo general de Anthropic.
     *
     * @group whatsapp
     * @test
     */
    public function con_el_dueno_en_anthropic_la_respuesta_y_el_resumen_son_los_de_siempre()
    {
        $this->fakes_de_red('Como siempre.');
        $this->dueno_en('anthropic');

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Como siempre.', $texto);

        list($url, $headers, $body) = $this->el_request_de_chat();

        $this->assertEquals(self::URL_ANTHROPIC, $url);
        $this->assertEquals(['clave-anthropic-de-prueba'], $headers['x-api-key']);
        $this->assertEquals(config('services.anthropic.model'), $body['model']);
        $this->assertArrayNotHasKey('thinking', $body, 'A Anthropic no se le manda ninguna clave thinking.');

        $filas = $this->consumos('whatsapp_respuesta');

        $this->assertCount(1, $filas);
        $this->assertEquals('anthropic', $filas[0]->proveedor);
        $this->assertEquals(config('services.anthropic.model'), $filas[0]->modelo);

        /* El resumen, por el mismo camino. */
        $resumen = (new WhatsappBotAiService())->generate_summary($this->chat);

        $this->assertEquals('Como siempre.', $resumen);

        $filas_resumen = $this->consumos('whatsapp_resumen');

        $this->assertCount(1, $filas_resumen);
        $this->assertEquals('anthropic', $filas_resumen[0]->proveedor);

        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'api.deepseek.com') !== false;
        });
    }

    /**
     * Dueño en DeepSeek sin la clave de DeepSeek (y con la de Anthropic): el bot no se queda mudo,
     * cae a Anthropic, y la fila lo dice.
     *
     * @group whatsapp
     * @test
     */
    public function sin_clave_de_deepseek_el_bot_cae_a_anthropic_y_el_gasto_lo_dice()
    {
        config(['services.deepseek.api_key' => null]);

        $this->fakes_de_red('Cayó a Anthropic.');
        $this->dueno_en('deepseek');

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config);

        $this->assertEquals('Cayó a Anthropic.', $texto);

        list($url, $headers, $body) = $this->el_request_de_chat();

        $this->assertEquals(self::URL_ANTHROPIC, $url);
        $this->assertEquals(config('services.anthropic.model'), $body['model']);
        $this->assertArrayNotHasKey('thinking', $body);

        $filas = $this->consumos('whatsapp_respuesta');

        $this->assertCount(1, $filas);
        $this->assertEquals('anthropic', $filas[0]->proveedor);
    }

    /**
     * Sin clave de ningún proveedor, ni la respuesta ni el resumen salen a la red.
     *
     * @group whatsapp
     * @test
     */
    public function sin_ninguna_clave_el_bot_no_sale_a_la_red()
    {
        config(['services.deepseek.api_key' => null, 'services.anthropic.api_key' => null]);
        Http::fake();

        $this->dueno_en('deepseek');

        $resultado = (new WhatsappBotAiService())->generate_response_with_photo($this->chat, $this->config);

        $this->assertEquals('', $resultado['body']);
        $this->assertEquals('sin_configurar', $resultado['motivo']);
        $this->assertEquals('', (new WhatsappBotAiService())->generate_summary($this->chat));

        Http::assertNothingSent();
        $this->assertCount(0, AiTokenUsage::where('user_id', $this->comercio->id)->get());
    }
}
