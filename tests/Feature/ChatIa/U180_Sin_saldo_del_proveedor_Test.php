<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261118 — M1: cuenta del proveedor de IA sin saldo (HTTP 402).
 *
 * En Truvari y Ferre Total el dueño recibió siete veces «Se me cortó la conexión con el servicio de IA.
 * Probá de nuevo en unos segundos» porque la cuenta de DeepSeek estaba sin saldo. El texto mentía dos
 * veces: no es la conexión y no se arregla en unos segundos. Lo que protege este archivo:
 *
 * - un 402 / billing_error sale con SU motivo y su texto, sin «conexión» ni «segundos»;
 * - queda un log de nivel ERROR con la marca fija ASISTENTE_IA_SIN_SALDO para alertar;
 * - el body crudo del proveedor no llega a la pantalla;
 * - un 529 sigue diciendo «sobrecargado» (no se pisa lo que ya andaba).
 *
 * Todo con Http::fake: nada sale a la red. PHP 7.4.
 */
class Sin_saldo_del_proveedor_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.deepseek.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Comercio sin saldo IA',
            'company_name' => 'Ferreteria sin saldo',
            'email'        => 'chat-ia-sin-saldo-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        Event::fake([ChatIaMensajeActualizado::class]);
    }

    /**
     * Corre el job sobre un pendiente nuevo y devuelve el assistant recargado.
     *
     * @return AiMessage
     */
    protected function correr_el_job()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Mostrame el último pedido de la tienda',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        return $assistant->fresh();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_402_no_promete_unos_segundos_ni_habla_de_conexion_y_deja_un_log_de_error()
    {
        Log::spy();

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => ['type' => 'unknown_error', 'message' => 'Insufficient Balance-body-crudo'],
            ], 402),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(
            AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_SIN_SALDO],
            $assistant->contenido
        );
        $this->assertStringNotContainsString('segundos', $assistant->contenido);
        $this->assertStringNotContainsString('conexión', mb_strtolower(str_replace('tu conexión', '', $assistant->contenido)));
        $this->assertNotEquals(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, $assistant->contenido);
        $this->assertStringNotContainsString('body-crudo', $assistant->contenido, 'El body del proveedor no llega a la pantalla.');
        $this->assertStringContainsString('falta de saldo', (string) $assistant->error_mensaje);

        Log::shouldHaveReceived('error')->withArgs(function ($mensaje) {
            return strpos((string) $mensaje, 'ASISTENTE_IA_SIN_SALDO') === 0;
        })->atLeast()->once();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_billing_error_con_otro_codigo_http_tambien_es_sin_saldo()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'error' => ['type' => 'billing_error', 'message' => 'Your credit balance is too low'],
            ], 400),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_SIN_SALDO], $assistant->contenido);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_529_sigue_diciendo_sobrecargado()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals(AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_SOBRECARGADO], $assistant->contenido);
    }

    /**
     * El detector solo mira el saldo: un 400 cualquiera o un 500 no se confunden con falta de saldo.
     *
     * @group chat-ia
     * @test
     */
    public function el_detector_no_confunde_otros_errores_con_falta_de_saldo()
    {
        Http::fake([
            'a.test/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'mal armado']], 400),
            'b.test/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'boom']], 500),
        ]);

        $this->assertFalse(ProveedorIaHelper::es_error_de_saldo(Http::post('https://a.test/x')));
        $this->assertFalse(ProveedorIaHelper::es_error_de_saldo(Http::post('https://b.test/x')));
    }
}
