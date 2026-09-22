<?php

namespace Tests\Feature\ChatIa;

use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use App\Models\AiTokenUsage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión proveedores-ia-deepseek (22/9/2026) — el chat del dueño corre contra el proveedor que
 * eligió: Claude (Anthropic) o DeepSeek por su endpoint compatible con Anthropic.
 *
 * Protege: con el dueño en DeepSeek el request va a `api.deepseek.com/anthropic/v1/messages` con
 * la clave de DeepSeek, el modelo de su variante (Flash para agil, Pro para profundo) y el bloque
 * `thinking` (disabled / enabled); con el dueño en Anthropic el body NO lleva `thinking` y va a
 * `api.anthropic.com`, exactamente como antes de la misión; un `equilibrado` guardado cae a agil
 * en DeepSeek; sin clave de DeepSeek el asistente cae a Anthropic y el gasto se registra con el
 * proveedor que contestó; un 503 de DeepSeek es "sobrecargado" y no una falla técnica; una foto en
 * el pedido con DeepSeek/Profundo va al modelo con visión (Pro no ve imágenes) con el thinking de
 * Profundo; y el título de conversación también sigue al dueño.
 *
 * 🔴 Ningún test sale a la red: Http::fake con los dos hosts, y las claves son de prueba.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Proveedor_deepseek_Test extends TestCase
{
    use DatabaseTransactions;

    /** La URL exacta del endpoint compatible con Anthropic de DeepSeek. */
    const URL_DEEPSEEK = 'https://api.deepseek.com/anthropic/v1/messages';

    /** La URL de siempre de Anthropic. */
    const URL_ANTHROPIC = 'https://api.anthropic.com/v1/messages';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Las dos claves son de prueba y los ids de modelo son inventados a propósito: así las
         * aserciones prueban el CABLEADO (config → payload) y no el default de config/services.php.
         */
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba',
            'services.anthropic.model'          => 'claude-general-test',
            'services.anthropic.model_agil'     => 'claude-agil-test',
            'services.anthropic.model_profundo' => 'claude-profundo-test',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba',
            'services.deepseek.model'           => 'deepseek-general-test',
            'services.deepseek.model_agil'      => 'deepseek-flash-test',
            'services.deepseek.model_profundo'  => 'deepseek-pro-test',
            'services.deepseek.model_vision'    => 'deepseek-vision-test',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio deepseek P47',
            'company_name' => 'Ferreteria P47',
            'email'        => 'deepseek-p47-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Fakea los dos proveedores con una respuesta `end_turn` y el `'*'` de red de seguridad.
     *
     * @param  array|null  $respuesta_deepseek  Body de DeepSeek; null = el mismo end_turn.
     * @param  int  $status_deepseek
     * @return void
     */
    protected function fakes_de_red($respuesta_deepseek = null, $status_deepseek = 200)
    {
        $end_turn = [
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'Listo, acá va.']],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
            'model'       => 'lo-que-diga-el-proveedor',
        ];

        Http::fake([
            'api.deepseek.com/*'  => Http::response(is_null($respuesta_deepseek) ? $end_turn : $respuesta_deepseek, $status_deepseek),
            'api.anthropic.com/*' => Http::response($end_turn, 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    /**
     * Deja al dueño con el proveedor y el pensamiento dados, guardados en la base.
     *
     * @param  string  $proveedor
     * @param  string  $pensamiento
     * @return void
     */
    protected function dueno_en($proveedor, $pensamiento)
    {
        $this->comercio->agente_proveedor = $proveedor;
        $this->comercio->agente_pensamiento = $pensamiento;
        $this->comercio->save();
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
            'contenido'          => '¿Cuánto vendí hoy?',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Le cuelga una foto de verdad (un PNG chico en el disco fake) al último mensaje del usuario de
     * la conversación, con su fila en `ai_message_imagenes`: así build_messages_payload() arma el
     * bloque `image` como en producción. Misma siembra que AsistenteWhatsapp/4_Imagenes_Test.
     *
     * @param  AiConversation  $conversation
     * @return void
     */
    protected function con_una_foto(AiConversation $conversation)
    {
        Storage::fake('local');

        $mensaje = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('rol', 'user')
            ->orderBy('id', 'DESC')
            ->first();

        $recurso = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();

        $path = 'asistente_imagenes/' . $this->comercio->id . '/' . $mensaje->id . '/1.webp';

        Storage::disk('local')->put($path, $binario);

        AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->comercio->id,
            'orden'         => 1,
            'path'          => $path,
            'mime'          => 'image/webp',
            'bytes'         => strlen($binario),
        ]);
    }

    /**
     * Corre el loop del chat para el dueño tal como está guardado y devuelve la conversación.
     *
     * @return AiConversation
     */
    protected function responder()
    {
        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new AsistenteIaService())->responder($conversation, $assistant);

        return $conversation;
    }

    /**
     * El único request que se mandó, ya decodificado: `[url, headers, body]`.
     *
     * @return array{0: string, 1: array, 2: array}
     */
    protected function el_request_enviado()
    {
        $capturado = null;

        Http::assertSent(function ($request) use (&$capturado) {
            $capturado = $request;

            return true;
        });

        $this->assertNotNull($capturado, 'Tendría que haber salido exactamente un request.');

        return [$capturado->url(), $capturado->headers(), json_decode($capturado->body(), true)];
    }

    /**
     * (a) Dueño en DeepSeek / agil: URL, clave y modelo de DeepSeek, thinking apagado, y la fila de
     * consumo dice `deepseek` con ese modelo.
     *
     * @group chat-ia
     * @test
     */
    public function con_el_dueno_en_deepseek_agil_el_request_va_a_deepseek_con_flash_y_sin_pensar()
    {
        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'agil');

        $conversation = $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(['clave-deepseek-de-prueba'], $headers['x-api-key'], 'La clave del header es la de DeepSeek, no la de Anthropic.');
        $this->assertEquals(config('services.deepseek.model_agil'), $body['model']);
        $this->assertEquals('disabled', $body['thinking']['type'], 'Ágil manda el thinking apagado: DeepSeek lo trae prendido por defecto.');
        $this->assertEquals(AsistenteIaService::MAX_TOKENS, $body['max_tokens'], 'Con el thinking apagado el techo de salida es el de siempre.');

        /* El payload de siempre viaja igual: system, tools y messages. */
        $this->assertArrayHasKey('system', $body);
        $this->assertArrayHasKey('tools', $body);
        $this->assertArrayHasKey('messages', $body);

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($fila, 'La llamada a DeepSeek también se paga: tiene que quedar su fila.');
        $this->assertEquals('deepseek', $fila->proveedor, 'El gasto se registra con el proveedor que efectivamente contestó.');
        $this->assertEquals(config('services.deepseek.model_agil'), $fila->modelo);
        $this->assertEquals(11, (int) $fila->input_tokens);
        $this->assertEquals(7, (int) $fila->output_tokens);
    }

    /**
     * (b) Dueño en DeepSeek / profundo: el modelo Pro con el thinking prendido, y el techo de
     * salida subido a `max_tokens_profundo` (si el endpoint cuenta el razonamiento contra
     * max_tokens, con los 1500 del asistente Profundo podría cortar sin texto).
     *
     * @group chat-ia
     * @test
     */
    public function con_el_dueno_en_deepseek_profundo_va_pro_con_el_thinking_prendido_y_mas_techo()
    {
        config(['services.deepseek.max_tokens_profundo' => 6000]);

        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'profundo');

        $conversation = $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(config('services.deepseek.model_profundo'), $body['model']);
        $this->assertEquals('enabled', $body['thinking']['type']);
        $this->assertGreaterThanOrEqual(
            (int) config('services.deepseek.max_tokens_profundo'),
            $body['max_tokens'],
            'Con el thinking prendido el techo de salida sube al de profundo, nunca queda en los 1500.'
        );

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertEquals('deepseek', $fila->proveedor);
        $this->assertEquals(config('services.deepseek.model_profundo'), $fila->modelo);
    }

    /**
     * (b') Dueño en DeepSeek / profundo con una FOTO en el pedido: la llamada va con el modelo de
     * visión (Pro no ve imágenes y DeepSeek no avisa: contestaría sin mirar la foto), con el
     * thinking de Profundo igual prendido, y la fila registra el modelo que efectivamente se usó.
     *
     * @group chat-ia
     * @test
     */
    public function con_una_foto_el_dueno_en_deepseek_profundo_va_al_modelo_de_vision_pensando()
    {
        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'profundo');

        list($conversation, $assistant) = $this->conversacion_con_pendiente();
        $this->con_una_foto($conversation);

        (new AsistenteIaService())->responder($conversation, $assistant);

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(config('services.deepseek.model_vision'), $body['model'], 'Con una foto, Profundo de DeepSeek va al modelo que ve.');
        $this->assertNotEquals(config('services.deepseek.model_profundo'), $body['model']);
        $this->assertEquals('enabled', $body['thinking']['type'], 'El thinking sigue siendo el de Profundo: Flash también razona.');

        /* La foto efectivamente viajó: el último turno del dueño lleva un bloque image. */
        $ultimo = $body['messages'][count($body['messages']) - 1];
        $this->assertTrue(is_array($ultimo['content']));
        $this->assertEquals('image', $ultimo['content'][0]['type']);

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertEquals('deepseek', $fila->proveedor);
        $this->assertEquals(config('services.deepseek.model_vision'), $fila->modelo, 'Se registra el modelo con el que efectivamente se llamó.');
    }

    /**
     * (b'') Y sin foto, Profundo sigue yendo a Pro: el modelo de visión es solo para las fotos.
     * Con Anthropic una foto no cambia nada, porque todos sus modelos ven.
     *
     * @group chat-ia
     * @test
     */
    public function sin_foto_profundo_sigue_en_pro_y_con_anthropic_la_foto_no_cambia_el_modelo()
    {
        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'profundo');

        $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(config('services.deepseek.model_profundo'), $body['model']);

        /* Anthropic con foto: el modelo de la variante, sin desvío. Fake nuevo para contar de cero. */
        $this->fakes_de_red();
        $this->dueno_en('anthropic', 'profundo');

        list($conversation, $assistant) = $this->conversacion_con_pendiente();
        $this->con_una_foto($conversation);

        (new AsistenteIaService())->responder($conversation, $assistant);

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_ANTHROPIC, $url);
        $this->assertEquals(config('services.anthropic.model_profundo'), $body['model'], 'Todos los modelos de Anthropic ven: la foto no cambia el modelo.');
        $this->assertEquals('image', $body['messages'][count($body['messages']) - 1]['content'][0]['type']);
    }

    /**
     * (c) Dueño en Anthropic: NADA cambia. La URL es la de Anthropic, la clave es la de Anthropic,
     * el body no lleva la clave `thinking` y el techo es el de siempre.
     *
     * @group chat-ia
     * @test
     */
    public function con_el_dueno_en_anthropic_el_request_es_el_de_siempre_sin_clave_thinking()
    {
        $this->fakes_de_red();
        $this->dueno_en('anthropic', 'profundo');

        $conversation = $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_ANTHROPIC, $url);
        $this->assertEquals(['clave-anthropic-de-prueba'], $headers['x-api-key']);
        $this->assertEquals(config('services.anthropic.model_profundo'), $body['model']);
        $this->assertArrayNotHasKey('thinking', $body, 'A Anthropic no se le manda ninguna clave thinking: el body es el de antes de la misión.');
        $this->assertEquals(AsistenteIaService::MAX_TOKENS, $body['max_tokens']);

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertEquals('anthropic', $fila->proveedor);
    }

    /**
     * (d) Dueño en DeepSeek pero la instalación NO tiene la clave de DeepSeek (y sí la de
     * Anthropic): el asistente no muere, cae a Anthropic, y la fila dice `anthropic` porque es
     * quien contestó.
     *
     * @group chat-ia
     * @test
     */
    public function sin_clave_de_deepseek_el_dueno_en_deepseek_cae_a_anthropic_y_el_gasto_lo_dice()
    {
        config(['services.deepseek.api_key' => null]);

        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'agil');

        $conversation = $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_ANTHROPIC, $url, 'Sin clave de DeepSeek, el request tiene que ir a Anthropic.');
        $this->assertEquals(['clave-anthropic-de-prueba'], $headers['x-api-key']);
        $this->assertEquals(config('services.anthropic.model_agil'), $body['model']);
        $this->assertArrayNotHasKey('thinking', $body);

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertEquals('anthropic', $fila->proveedor, 'El gasto se imputa al proveedor que contestó, no al que el dueño eligió.');
        $this->assertEquals(config('services.anthropic.model_agil'), $fila->modelo);
    }

    /**
     * (e) Un `equilibrado` guardado (con Claude, o a mano en la columna) con el dueño en DeepSeek
     * cae a agil: DeepSeek no tiene equilibrado y el modelo es Flash.
     *
     * @group chat-ia
     * @test
     */
    public function un_equilibrado_guardado_con_el_dueno_en_deepseek_usa_flash()
    {
        $this->fakes_de_red();
        $this->dueno_en('deepseek', 'equilibrado');

        $this->responder();

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(config('services.deepseek.model_agil'), $body['model'], 'DeepSeek no tiene equilibrado: cae a agil (Flash).');
        $this->assertEquals('disabled', $body['thinking']['type']);

        /* Y el helper lo dice igual, sin pasar por el loop. */
        $eleccion = ProveedorIaHelper::modelo_del_asistente($this->comercio->fresh());

        $this->assertEquals('deepseek', $eleccion['proveedor']);
        $this->assertEquals('agil', $eleccion['pensamiento']);
    }

    /**
     * (f) `hay_credenciales($owner)` pregunta por la clave del proveedor del dueño, con el
     * fallback: con la clave de DeepSeek, true; sin ninguna, false; sin la de DeepSeek pero con la
     * de Anthropic, true (porque cae a Anthropic). Y sin dueño, alcanza con que haya alguna.
     *
     * @group chat-ia
     * @test
     */
    public function hay_credenciales_pregunta_por_el_proveedor_del_dueno_con_su_fallback()
    {
        $this->dueno_en('deepseek', 'agil');
        $service = new AsistenteIaService();

        /* Con las dos claves: el elegido tiene la suya. */
        $this->assertTrue($service->hay_credenciales($this->comercio->fresh()));

        /* Sin la de DeepSeek pero con la de Anthropic: cae a Anthropic, así que sí hay. */
        config(['services.deepseek.api_key' => null]);
        $this->assertTrue($service->hay_credenciales($this->comercio->fresh()));
        $this->assertTrue($service->hay_credenciales(), 'Sin dueño alcanza con que algún proveedor tenga clave.');

        /* Sin ninguna: no hay con qué contestar. */
        config(['services.anthropic.api_key' => null]);
        $this->assertFalse($service->hay_credenciales($this->comercio->fresh()));
        $this->assertFalse($service->hay_credenciales());
        $this->assertEquals([], ProveedorIaHelper::proveedores_disponibles());

        /* Solo la de DeepSeek: el dueño en Anthropic también cae al otro. */
        config(['services.deepseek.api_key' => 'clave-deepseek-de-prueba']);
        $this->dueno_en('anthropic', 'agil');
        $this->assertTrue($service->hay_credenciales($this->comercio->fresh()));
        $this->assertEquals('deepseek', ProveedorIaHelper::proveedor_de($this->comercio->fresh()));
    }

    /**
     * (g) Un 503 de DeepSeek (saturado) es "sobrecargado" y no la falla técnica genérica:
     * DeepSeek no manda `overloaded_error` ni 529 como Anthropic. Nada se reintenta solo
     * (ResponderMensajeChatIaJob tiene $tries = 1): lo único que cambia es el texto que ve la
     * persona —"probá de nuevo en unos segundos" en vez del genérico de conexión cortada—.
     *
     * @group chat-ia
     * @test
     */
    public function un_503_de_deepseek_se_lee_como_sobrecargado()
    {
        $this->fakes_de_red(['error' => ['type' => 'server_error', 'message' => 'Service Unavailable']], 503);
        $this->dueno_en('deepseek', 'agil');

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);

            $this->fail('Con un 503 el loop tiene que cortar con AsistenteIaException.');
        } catch (AsistenteIaException $e) {
            $this->assertEquals(AsistenteIaException::MOTIVO_SOBRECARGADO, $e->motivo());
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        /* Sin respuesta válida no hay consumo que imputar. */
        $this->assertEquals(0, AiTokenUsage::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Y un error que NO es transitorio sigue siendo la falla técnica, ahora nombrando al proveedor.
     *
     * @group chat-ia
     * @test
     */
    public function un_400_de_deepseek_sigue_siendo_falla_tecnica_y_nombra_al_proveedor()
    {
        $this->fakes_de_red(['error' => ['type' => 'invalid_request_error', 'message' => 'body-crudo-de-deepseek']], 400);
        $this->dueno_en('deepseek', 'agil');

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);

            $this->fail('Con un 400 el loop tiene que cortar con AsistenteIaException.');
        } catch (AsistenteIaException $e) {
            $this->assertEquals(AsistenteIaException::MOTIVO_FALLA_TECNICA, $e->motivo());
            $this->assertStringContainsString('DeepSeek', $e->getMessage(), 'El detalle técnico nombra al proveedor que falló.');
            $this->assertStringContainsString('body-crudo-de-deepseek', $e->getMessage());
        }
    }

    /**
     * El título de conversación también sigue al dueño: con DeepSeek va a su URL con el modelo
     * GENERAL de DeepSeek, el thinking apagado, y la fila de `chat_titulo` dice `deepseek`.
     *
     * @group chat-ia
     * @test
     */
    public function el_titulo_de_conversacion_sigue_al_proveedor_del_dueno()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'model'   => 'deepseek-general-test',
                'content' => [['type' => 'text', 'text' => 'Ventas de hoy']],
                'usage'   => ['input_tokens' => 30, 'output_tokens' => 4],
            ], 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $this->dueno_en('deepseek', 'profundo');

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, '¿Cuánto vendí hoy?'))->handle();

        $this->assertEquals('Ventas de hoy', $conversation->fresh()->titulo);

        list($url, $headers, $body) = $this->el_request_enviado();

        $this->assertEquals(self::URL_DEEPSEEK, $url);
        $this->assertEquals(['clave-deepseek-de-prueba'], $headers['x-api-key']);
        $this->assertEquals(config('services.deepseek.model'), $body['model'], 'El título usa el modelo GENERAL, no el de la variante de pensamiento.');
        $this->assertEquals('disabled', $body['thinking']['type'], 'El título es corto y barato: nunca piensa a fondo, ni con el dueño en profundo.');
        $this->assertEquals(InferirTituloConversacionIaJob::MAX_TOKENS, $body['max_tokens']);

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();

        $this->assertNotNull($fila);
        $this->assertEquals('chat_titulo', $fila->proceso);
        $this->assertEquals('deepseek', $fila->proveedor);
        $this->assertEquals(config('services.deepseek.model'), $fila->modelo);
    }

    /**
     * Y sin clave del proveedor del dueño (ni de otro), el título no sale a la red.
     *
     * @group chat-ia
     * @test
     */
    public function el_titulo_no_sale_a_la_red_sin_clave_del_proveedor_del_dueno()
    {
        config(['services.deepseek.api_key' => null, 'services.anthropic.api_key' => null]);
        Http::fake();

        $this->dueno_en('deepseek', 'agil');

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, 'Hola'))->handle();

        Http::assertNothingSent();
        $this->assertNull($conversation->fresh()->titulo);
    }
}
