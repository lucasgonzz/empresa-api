<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P7 — dos mensajes del dueño que llegan juntos (V12).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026, por WhatsApp). El dueño mandó «Perfecto, excelente» (280, 09:08:39) y
 * enseguida un audio «Decime el precio por botella de La Linda Malbec» (282, 09:08:50). La respuesta al 280
 * (281) ya contestaba La Linda, y la del 282 (283) lo repitió. Igual con 284/286 (Angélica) y con la venta 3098,
 * registrada con los $1.000 que pedía un audio que todavía «no había llegado».
 *
 * La causa, reproducida acá: cada mensaje tiene su assistant y su job, y build_messages_payload() traía los
 * últimos mensajes `listo` de la conversación SIN tope de id. El job del 280, que arranca con el 282 ya
 * guardado, le mandaba al modelo los dos; el del 282 veía esa respuesta y su propio mensaje, y contestaba otra
 * vez. Desde P7 el turno solo lleva lo anterior a su assistant: cada mensaje se contesta en su turno, una vez.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class Mensajes_que_llegan_juntos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p114',
            'services.deepseek.model'          => 'deepseek-general-p114',
            'services.deepseek.model_agil'     => 'deepseek-flash-p114',
            'services.deepseek.model_profundo' => 'deepseek-pro-p114',
            'services.deepseek.model_vision'   => 'deepseek-vision-p114',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio juntos P114',
            'company_name' => 'Bebidas P114',
            'email'        => 'juntos-p114-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    /**
     * La conversación del caso real: una vuelta ya contestada y los dos mensajes seguidos, cada uno con su
     * assistant pendiente, como los deja el admin al recibirlos.
     *
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessage}
     */
    protected function dos_mensajes_seguidos()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $base = ['ai_conversation_id' => $conversation->id, 'canal' => AiMessage::CANAL_WHATSAPP];

        AiMessage::create($base + ['rol' => 'user', 'contenido' => 'Decime el precio por botella del DV Cabernet Malbec', 'estado' => 'listo']);
        AiMessage::create($base + ['rol' => 'assistant', 'contenido' => 'El *DV Cabernet Malbec*: la botella sale *$12.333,33*.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        AiMessage::create($base + ['rol' => 'user', 'contenido' => 'Perfecto, excelente', 'estado' => 'listo']);
        $primero = AiMessage::create($base + ['rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        AiMessage::create($base + ['rol' => 'user', 'contenido' => 'Bueno, ahora vamos con otro. Decime el precio por botella de La Linda Malbec', 'estado' => 'listo']);
        $segundo = AiMessage::create($base + ['rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        return [$conversation, $primero, $segundo];
    }

    protected function el_modelo_contesta(array $textos)
    {
        $secuencia = Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'se agotó (P114)']], 500));

        foreach ($textos as $texto) {
            $secuencia->push([
                'model'       => 'lo-que-diga-el-proveedor',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => $texto]],
                'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
            ], 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    protected function cuerpos()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $bodies[] = json_decode($par[0]->body(), true);
            }
        }

        return $bodies;
    }

    /**
     * 🔴 La causa, reproducida: sin tope, el historial del turno del PRIMER mensaje ya trae el segundo.
     *
     * @test
     */
    public function sin_tope_el_historial_del_primer_turno_ya_trae_el_segundo_mensaje()
    {
        list($conversation, $primero) = $this->dos_mensajes_seguidos();

        $sin_tope = json_encode((new AsistenteIaService())->build_messages_payload($conversation), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('La Linda Malbec', $sin_tope, 'Así se contestaban los dos juntos en el turno del primero.');

        $con_tope = json_encode((new AsistenteIaService())->build_messages_payload($conversation, $primero->id), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('La Linda Malbec', $con_tope);
        $this->assertStringContainsString('Perfecto, excelente', $con_tope);
    }

    /**
     * De punta a punta: el turno del primero contesta el primero; el del segundo, el segundo, viendo la respuesta
     * de antes. Ninguno contesta lo del otro.
     *
     * @test
     */
    public function cada_mensaje_se_contesta_en_su_turno_y_una_sola_vez()
    {
        $this->el_modelo_contesta([
            'De nada.',
            '*La Linda Malbec*: la botella sale *$7.833,33*, de $47.000 la caja de 6.',
        ]);

        list($conversation, $primero, $segundo) = $this->dos_mensajes_seguidos();

        $primero->contenido = (new AsistenteIaService())->responder($conversation, $primero);
        $primero->estado = 'listo';
        $primero->save();

        $segundo_texto = (new AsistenteIaService())->responder($conversation, $segundo);

        $bodies = $this->cuerpos();
        $this->assertCount(2, $bodies);

        $primer_historial = json_encode($bodies[0]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('La Linda Malbec', $primer_historial, 'El turno del primero no ve el mensaje que llegó después.');

        $segundo_historial = json_encode($bodies[1]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('La Linda Malbec', $segundo_historial);
        $this->assertStringContainsString('De nada.', $segundo_historial, 'El segundo ve lo que ya se contestó.');

        $this->assertSame('De nada.', $primero->fresh()->contenido);
        $this->assertStringContainsString('$7.833,33', $segundo_texto);
    }
}
