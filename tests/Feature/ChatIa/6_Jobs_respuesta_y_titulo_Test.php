<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Jobs\InferirTituloConversacionIaJob;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P6: los jobs del chat (respuesta y título).
 *
 * Lo que protege este archivo: que un mensaje 'pendiente' SIEMPRE termina en
 * un estado final con aviso (listo con texto, o error con contenido amigable
 * que el usuario ve — nunca un globo vacío ni un "pensando" eterno); que sin
 * credenciales o sin extensión el job no sale a la red; que el job convive
 * con una conversación borrada (R3); y que el título es cosmético de verdad:
 * su falla deja null y no rompe nada.
 *
 * 🔴 Event::fake en todo test que corre el job de respuesta: el evento es
 * ShouldBroadcastNow y sin fake saldría a Pusher de verdad.
 */
class Jobs_respuesta_y_titulo_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el chat. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: cada test setea su fake si la necesita.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P6',
            'company_name' => 'Ferreteria P6',
            'email'        => 'chat-ia-p6-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado chat-ia P6',
            'email'    => 'chat-ia-p6-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la
     * base del slot todavía no la tiene sembrada).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Conversación del empleado con un user 'listo' y un assistant 'pendiente'.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_pendiente()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => '¿Cuánto stock tengo de tornillos?',
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
    public function el_camino_feliz_deja_el_mensaje_listo_y_emite_el_evento()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

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

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('listo', $assistant->estado);
        $this->assertEquals('Tenés 12 unidades de tornillos.', $assistant->contenido);
        $this->assertNull($assistant->error_mensaje);

        Event::assertDispatched(ChatIaMensajeActualizado::class, function ($evento) use ($conversation, $assistant) {
            return $evento->auth_user_id === $this->empleado->id
                && $evento->ai_conversation_id === $conversation->id
                && $evento->ai_message_id === $assistant->id
                && $evento->estado === 'listo';
        });
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_529_deja_el_mensaje_en_error_con_contenido_amigable_y_avisa_igual()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant->refresh();

        $this->assertEquals('error', $assistant->estado);
        $this->assertNotEquals('', trim((string) $assistant->contenido), 'El usuario nunca ve un globo vacío: el contenido amigable es obligatorio.');
        $this->assertNotNull($assistant->error_mensaje, 'El detalle técnico va en su columna, no se pierde.');

        // El evento sale TAMBIÉN en error: es lo que corta la espera de la SPA.
        Event::assertDispatched(ChatIaMensajeActualizado::class, function ($evento) use ($assistant) {
            return $evento->ai_message_id === $assistant->id && $evento->estado === 'error';
        });
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_credenciales_no_sale_a_la_red_y_deja_error_amigable()
    {
        $this->dar_extension();
        // La clave quedó null desde setUp: no hay IA contratada.
        Event::fake([ChatIaMensajeActualizado::class]);
        Http::fake();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        Http::assertNothingSent();

        $assistant->refresh();
        $this->assertEquals('error', $assistant->estado);
        $this->assertNotEquals('', trim((string) $assistant->contenido));

        Event::assertDispatched(ChatIaMensajeActualizado::class);
    }

    /**
     * D28: si el dueño perdió la extensión entre el POST y el worker, el
     * mensaje queda en error amigable sin gastar una llamada a la API.
     *
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_no_sale_a_la_red_y_deja_error_amigable()
    {
        // A propósito NO se da la extensión; la clave sí existe.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);
        Http::fake();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        Http::assertNothingSent();

        $assistant->refresh();
        $this->assertEquals('error', $assistant->estado);
        $this->assertNotEquals('', trim((string) $assistant->contenido));

        Event::assertDispatched(ChatIaMensajeActualizado::class);
    }

    /**
     * R3: destroy puede correr entre el POST y el worker. El job recarga por
     * id y vuelve sin explotar, sin llamar a la API y sin avisar a nadie.
     *
     * @group chat-ia
     * @test
     */
    public function con_la_conversacion_borrada_el_job_vuelve_sin_explotar_ni_llamar_a_la_api()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Event::fake([ChatIaMensajeActualizado::class]);
        Http::fake();

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        // El destroy real borra conversación y mensajes; acá se borra solo la
        // conversación para ejercitar la rama del mensaje huérfano.
        $conversation->delete();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        Http::assertNothingSent();
        Event::assertNotDispatched(ChatIaMensajeActualizado::class);

        // Y con el mensaje también borrado (el caso normal del destroy), ídem.
        $assistant->delete();
        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        Http::assertNothingSent();
        Event::assertNotDispatched(ChatIaMensajeActualizado::class);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function failed_no_pisa_un_mensaje_ya_listo_y_cierra_un_pendiente_colgado()
    {
        $this->dar_extension();
        Event::fake([ChatIaMensajeActualizado::class]);

        list($conversation, $assistant) = $this->conversacion_con_pendiente();

        // Carrera 1: el mensaje ya quedó listo; failed() no lo toca.
        $assistant->contenido = 'Respuesta que ya había quedado bien.';
        $assistant->estado = 'listo';
        $assistant->save();

        (new ResponderMensajeChatIaJob($assistant->id))->failed(new \Exception('worker muerto'));

        $assistant->refresh();
        $this->assertEquals('listo', $assistant->estado);
        $this->assertEquals('Respuesta que ya había quedado bien.', $assistant->contenido);

        // Carrera 2: seguía pendiente (timeout del worker); failed() lo cierra con error amigable y avisa.
        $assistant->estado = 'pendiente';
        $assistant->contenido = null;
        $assistant->save();

        (new ResponderMensajeChatIaJob($assistant->id))->failed(new \Exception('timeout del worker'));

        $assistant->refresh();
        $this->assertEquals('error', $assistant->estado);
        $this->assertNotEquals('', trim((string) $assistant->contenido));

        Event::assertDispatched(ChatIaMensajeActualizado::class, function ($evento) use ($assistant) {
            return $evento->ai_message_id === $assistant->id && $evento->estado === 'error';
        });
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_titulo_se_guarda_limpio_de_comillas_y_graba_su_consumo()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'   => 'claude-modelo-fake',
                'content' => [
                    ['type' => 'text', 'text' => '"Stock de tornillos"'],
                ],
                'usage'   => ['input_tokens' => 60, 'output_tokens' => 8],
            ], 200),
        ]);

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, '¿Cuánto stock tengo de tornillos?'))->handle();

        $conversation->refresh();

        $this->assertEquals('Stock de tornillos', $conversation->titulo, 'Las comillas de los extremos se sacan.');

        $fila = AiTokenUsage::where('ai_conversation_id', $conversation->id)->first();
        $this->assertNotNull($fila, 'El título también se paga: tiene que quedar su fila de consumo.');
        $this->assertEquals('chat_titulo', $fila->proceso);
        $this->assertEquals($this->comercio->id, (int) $fila->user_id);
        $this->assertEquals($this->empleado->id, (int) $fila->auth_user_id);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_titulo_se_recorta_a_150_caracteres()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => str_repeat('t', 300)],
                ],
                'usage'   => ['input_tokens' => 60, 'output_tokens' => 40],
            ], 200),
        ]);

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, 'Un mensaje largo'))->handle();

        $conversation->refresh();

        $this->assertEquals(150, mb_strlen($conversation->titulo), 'El título se recorta al largo de la columna.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function si_la_inferencia_falla_el_titulo_queda_null_sin_romper_nada()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['type' => 'api_error']], 500),
        ]);

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, 'Hola'))->handle();

        $conversation->refresh();

        $this->assertNull($conversation->titulo, 'Un título es cosmético: la falla degrada a null, jamás a un error visible.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_job_de_titulo_no_pisa_un_titulo_existente_ni_sale_a_la_red_sin_clave()
    {
        // Sin clave (quedó null del setUp): ni un request.
        Http::fake();

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->empleado->id,
        ]);

        (new InferirTituloConversacionIaJob($conversation->id, 'Hola'))->handle();

        Http::assertNothingSent();
        $this->assertNull($conversation->fresh()->titulo);

        // Con título ya puesto (p. ej. una conversación de sugerencia): tampoco llama.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $conversation->titulo = 'Sugerencia de stock #9';
        $conversation->save();

        (new InferirTituloConversacionIaJob($conversation->id, 'Hola'))->handle();

        Http::assertNothingSent();
        $this->assertEquals('Sugerencia de stock #9', $conversation->fresh()->titulo);
    }
}
