<?php

namespace Tests\Feature\ModelosIa;

use App\Models\AiTokenUsage;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión modelos-ia-por-cliente (30/9/2026) — el bot de WhatsApp con DeepSeek Pro elegido y una foto.
 *
 * Protege: con `ia_modelo_whatsapp = deepseek_pro` y la visión prendida, un turno que lleva una foto
 * va al modelo de VISIÓN de DeepSeek (Pro no ve imágenes y DeepSeek no avisa: contestaría sin mirarla),
 * con el thinking de Pro; un turno sin foto va a Pro. El gasto se registra con el modelo que
 * efectivamente contestó.
 *
 * Siembra copiada de Whatsapp/13_Vision_del_agente_Test (disco local falseado, foto en el disco
 * privado).
 *
 * 🔴 Ningún test sale a la red: Http::fake con los tres hosts y el `'*'` de red de seguridad.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Whatsapp_con_vision_Test extends TestCase
{
    use DatabaseTransactions;

    /** Bytes del archivo de imagen guardado en el disco privado. */
    const BINARIO = 'JPEG-DE-PRUEBA-MODELOS-IA-0123456789-0123456789';

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => 'clave-anthropic-de-prueba',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'services.deepseek.model_vision'   => 'deepseek-vision-test',
            'services.openai.api_key'          => null,
            'broadcasting.default'             => 'null',
        ]);

        Storage::fake('local');

        $this->comercio = User::create([
            'name'         => 'Comercio modelos IA W2',
            'company_name' => 'Ferreteria W2',
            'email'        => 'modelos-ia-w2-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->comercio->ia_modelo_whatsapp = 'deepseek_pro';
        $this->comercio->save();

        $this->config = WhatsappBotConfig::create([
            'user_id'           => $this->comercio->id,
            'kapso_api_key'     => 'kapso-w2',
            'phone_number_id'   => '5491100000302',
            'webhook_secret'    => 'secreto-w2',
            'is_active'         => true,
            'ai_vision_enabled' => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'         => $this->comercio->id,
            'phone'           => '5493416003002',
            'ai_enabled'      => true,
            'unread_count'    => 1,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        Http::fake([
            'api.openai.com/*'   => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.deepseek.com/*' => Http::response([
                'model'   => 'lo-que-diga-deepseek',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Miro la foto...', 'signature' => 'x'],
                    ['type' => 'text', 'text' => 'Sí, ese repuesto lo tenemos.'],
                ],
                'usage'   => ['input_tokens' => 500, 'output_tokens' => 60],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * El request de chat que salió (el que no fue al embedding), decodificado: `[url, body]`.
     *
     * @return array{0: string, 1: array}
     */
    protected function el_request_de_chat()
    {
        $capturado = null;

        Http::assertSent(function ($request) use (&$capturado) {
            if (strpos($request->url(), 'api.deepseek.com') === false) {
                return false;
            }

            $capturado = $request;

            return true;
        });

        return [$capturado->url(), json_decode($capturado->body(), true)];
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_pro_elegido_un_turno_con_foto_va_al_modelo_de_vision_pensando()
    {
        $path = 'whatsapp/' . $this->chat->id . '/wa_w2.jpg';
        Storage::disk('local')->put($path, self::BINARIO);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Tenés este repuesto?',
            'media_type'       => 'image',
            'media_path'       => $path,
            'media_mime'       => 'image/jpeg',
            'media_size'       => strlen(self::BINARIO),
        ]);

        $texto = (new WhatsappBotAiService())->generate_response($this->chat, $this->config->fresh());

        $this->assertSame('Sí, ese repuesto lo tenemos.', $texto, 'Con thinking adelante, el texto igual se lee.');

        list($url, $body) = $this->el_request_de_chat();

        $this->assertSame('https://api.deepseek.com/anthropic/v1/messages', $url);
        $this->assertSame('deepseek-vision-test', $body['model'], 'Pro no ve: el turno con foto va al modelo con visión.');
        $this->assertSame('enabled', $body['thinking']['type'], 'Con el thinking de Pro, que es la opción elegida.');

        $tiene_imagen = false;

        foreach ($body['messages'] as $turno) {
            if (is_array($turno['content'])) {
                foreach ($turno['content'] as $bloque) {
                    if (isset($bloque['type']) && $bloque['type'] === 'image') {
                        $tiene_imagen = true;
                    }
                }
            }
        }

        $this->assertTrue($tiene_imagen, 'La foto tiene que haber viajado como bloque image.');

        $fila = AiTokenUsage::where('user_id', $this->comercio->id)->where('proceso', 'whatsapp_respuesta')->first();

        $this->assertNotNull($fila);
        $this->assertSame('deepseek', $fila->proveedor);
        $this->assertSame('deepseek-vision-test', $fila->modelo, 'Se registra el modelo con el que efectivamente se llamó.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_pro_elegido_un_turno_sin_foto_va_a_pro()
    {
        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Tenés tornillos?',
        ]);

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config->fresh());

        list($url, $body) = $this->el_request_de_chat();

        $this->assertSame('deepseek-pro-test', $body['model']);
        $this->assertSame('enabled', $body['thinking']['type']);
        $this->assertGreaterThan(500, (int) $body['max_tokens'], 'Con thinking enabled el techo sube al de profundo.');
    }
}
