<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ProveedorIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RespaldoDeepSeekTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.api_key' => 'prueba-local',
            'services.deepseek.base_url' => 'https://deepseek.test/anthropic',
        ]);
    }

    private function payload(string $modelo): array
    {
        return [
            'model' => $modelo,
            'max_tokens' => 1000,
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
            'messages' => [['role' => 'user', 'content' => 'Devolvé un JSON']],
            'tools' => [['name' => 'consultar', 'input_schema' => ['type' => 'object']]],
        ];
    }

    public function modelosCompatibles(): array
    {
        return [
            ['deepseek-v4-pro', 'deepseek-flash'],
            ['deepseek-flash', 'deepseek-v4-pro'],
            ['deepseek-v4-flash', 'deepseek-v4-pro'],
            ['deepseek-v4-flash-vision-exp', 'deepseek-v4-pro'],
        ];
    }

    /** @dataProvider modelosCompatibles */
    public function test_envia_la_otra_variante_con_el_mismo_contrato(string $inicial, string $alternativo): void
    {
        $solicitudes = [];
        Http::fake(function ($request) use (&$solicitudes) {
            $solicitudes[] = $request->data();
            return count($solicitudes) === 1
                ? Http::response(['error' => ['type' => 'overloaded_error']], 503)
                : Http::response(['content' => [['type' => 'text', 'text' => '{}']]], 200);
        });

        $payload = $this->payload($inicial);
        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $payload);

        $this->assertSame($alternativo, $modelo);
        $this->assertTrue($respuesta->successful());
        $this->assertSame([$payload, array_replace($payload, ['model' => $alternativo])], $solicitudes);
    }

    public function test_cambia_de_modelo_despues_de_un_timeout(): void
    {
        $modelos = [];
        Http::fake(function ($request) use (&$modelos) {
            $modelos[] = $request['model'];
            if (count($modelos) === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }
            return Http::response(['content' => [['type' => 'text', 'text' => '{}']]], 200);
        });

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-v4-pro'));

        $this->assertTrue($respuesta->successful());
        $this->assertSame('deepseek-flash', $modelo);
        $this->assertSame(['deepseek-v4-pro', 'deepseek-flash'], $modelos);
    }

    public function test_dos_fallas_conservan_el_error_inicial(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 401);

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-v4-pro'));

        Http::assertSentCount(2);
        $this->assertSame(503, $respuesta->status());
        $this->assertSame('deepseek-v4-pro', $modelo);
    }

    public function erroresPermanentes(): array
    {
        return [[400], [401], [402], [403], [422]];
    }

    /** @dataProvider erroresPermanentes */
    public function test_errores_permanentes_se_devuelven_sin_reintento(int $status): void
    {
        Http::fakeSequence()->push(['error' => ['type' => 'api_error']], $status);

        [$respuesta] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-flash'));

        Http::assertSentCount(1);
        $this->assertSame($status, $respuesta->status());
    }

    public function test_tls_no_se_trata_como_conexion_transitoria(): void
    {
        $llamadas = 0;
        Http::fake(function () use (&$llamadas) {
            $llamadas++;
            throw new ConnectionException('cURL error 60: SSL certificate problem');
        });

        try {
            ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $this->payload('deepseek-flash'));
            $this->fail('La falla de certificado debía conservarse.');
        } catch (ConnectionException $e) {
            $this->assertSame(1, $llamadas);
        }
    }

    public function contratosIncompatibles(): array
    {
        return [
            [['messages' => [['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => [['type' => 'image']]]]]]]],
            [['tools' => [['type' => 'web_search_20250305', 'name' => 'web_search']]]],
            [['stream' => true]],
        ];
    }

    /** @dataProvider contratosIncompatibles */
    public function test_una_entrada_incompatible_conserva_la_salida_original(array $cambios): void
    {
        Http::fakeSequence()->push([], 503);
        $payload = array_replace($this->payload('deepseek-flash'), $cambios);

        [$respuesta, $modelo] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 30, $payload);

        Http::assertSentCount(1);
        $this->assertSame('deepseek-flash', $modelo);
        $this->assertSame(503, $respuesta->status());
    }

    public function test_si_se_agota_la_ventana_no_sale_el_respaldo(): void
    {
        Http::fake(function () {
            usleep(1100000);
            return Http::response([], 503);
        });

        [$respuesta] = ProveedorIaHelper::pedir_mensaje(ProveedorIaHelper::DEEPSEEK, 2, $this->payload('deepseek-flash'));

        Http::assertSentCount(1);
        $this->assertSame(503, $respuesta->status());
    }

    // ---------------------------------------------------------------------
    // tiene_respaldo(): la misma decisión que toma pedir_mensaje()
    // ---------------------------------------------------------------------

    public function test_tiene_respaldo_solo_con_variante_alternativa(): void
    {
        $this->assertTrue(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 60, $this->payload('deepseek-v4-pro')));
        $this->assertTrue(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 60, $this->payload('deepseek-flash')));
        // Un modelo sin alternativa, el respaldo no permitido, un timeout sin ventana o otro proveedor: no hay respaldo.
        $this->assertFalse(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 60, $this->payload('deepseek-sin-alternativa')));
        $this->assertFalse(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 60, $this->payload('deepseek-v4-pro'), false));
        $this->assertFalse(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 1, $this->payload('deepseek-v4-pro')));
        $this->assertFalse(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::ANTHROPIC, 60, $this->payload('deepseek-v4-pro')));
        // Un pedido que requiere visión no puede pasar de Flash a Pro.
        $con_imagen = array_replace($this->payload('deepseek-flash'), [
            'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => []]]]],
        ]);
        $this->assertFalse(ProveedorIaHelper::tiene_respaldo(ProveedorIaHelper::DEEPSEEK, 60, $con_imagen));
    }

    // ---------------------------------------------------------------------
    // El asistente: el respaldo reemplaza al reintento SOLO donde hay variante
    // ---------------------------------------------------------------------

    /**
     * Comercio con la extensión del asistente, DeepSeek ágil, y un mensaje pendiente de contestar.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    private function conversacion_del_asistente(string $modelo_agil, string $modelo_profundo, string $confianza = ConfianzaDelAgenteIaHelper::CAUTELOSO): array
    {
        config([
            'services.anthropic.api_key'       => null,
            'services.deepseek.model'          => $modelo_agil,
            'services.deepseek.model_agil'     => $modelo_agil,
            'services.deepseek.model_profundo' => $modelo_profundo,
            'services.deepseek.thinking_agil'  => 'disabled',
        ]);
        Event::fake([ChatIaMensajeActualizado::class]);

        $comercio = User::create([
            'name'         => 'Comercio respaldo',
            'company_name' => 'Ferreteria respaldo',
            'email'        => 'respaldo-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $comercio->agente_proveedor   = 'deepseek';
        $comercio->agente_pensamiento = 'agil';
        $comercio->agente_confianza   = $confianza;
        $comercio->save();

        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (! $extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }
        $comercio->extencions()->attach($extension->id);
        $comercio->load('extencions');

        $conversation = AiConversation::create([
            'user_id'      => $comercio->id,
            'auth_user_id' => $comercio->id,
            'origen'       => 'usuario',
        ]);
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Anotame una tarea para mañana',
            'estado'             => 'listo',
        ]);
        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    private function respuesta_del_modelo(string $stop_reason, array $bloques): array
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    private function fin_de_turno(string $texto): array
    {
        return $this->respuesta_del_modelo('end_turn', [['type' => 'text', 'text' => $texto]]);
    }

    /** @var string[] Los modelos de los pedidos que salieron a DeepSeek, en orden (también los que el doble corta con una excepción). */
    private $enviados = [];

    /**
     * Http::fake() con un doble que anota el modelo de cada pedido antes de contestar.
     *
     * @param  callable  $doble  fn(int $numero_de_pedido, $request): Response|void (puede tirar una excepción)
     */
    private function fingir_deepseek(callable $doble): void
    {
        $this->enviados = [];
        Http::fake(function ($request) use ($doble) {
            $this->enviados[] = $request->data()['model'];
            return $doble(count($this->enviados), $request);
        });
    }

    private function modelos_enviados(): array
    {
        return $this->enviados;
    }

    public function test_el_asistente_con_variante_disponible_usa_el_respaldo_y_no_reintenta_el_mismo_modelo(): void
    {
        $this->fingir_deepseek(function ($n) {
            return $n === 1
                ? Http::response(['error' => ['type' => 'overloaded_error']], 503)
                : Http::response($this->fin_de_turno('Hola, ¿en qué te ayudo?'), 200);
        });

        [$conversation, $assistant] = $this->conversacion_del_asistente('deepseek-flash', 'deepseek-v4-pro');

        $this->assertSame('Hola, ¿en qué te ayudo?', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertSame(['deepseek-flash', 'deepseek-v4-pro'], $this->modelos_enviados(), 'Un pedido a Flash y UNO a la variante; Flash no se repite.');
    }

    public function test_el_asistente_con_variante_disponible_hace_como_mucho_dos_pedidos_por_vuelta(): void
    {
        $this->fingir_deepseek(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        [$conversation, $assistant] = $this->conversacion_del_asistente('deepseek-flash', 'deepseek-v4-pro');

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);
            $this->fail('Con las dos variantes caídas el turno corta.');
        } catch (ConnectionException $e) {
            $this->assertSame(['deepseek-flash', 'deepseek-v4-pro'], $this->modelos_enviados(), 'Nada de un tercer pedido.');
        }
    }

    public function test_el_asistente_sin_variante_reintenta_el_mismo_modelo_una_sola_vez(): void
    {
        $this->fingir_deepseek(function ($n) {
            return $n <= 2
                ? Http::response(['error' => ['type' => 'overloaded_error']], 529)
                : Http::response($this->fin_de_turno('Nunca tendría que llegar acá.'), 200);
        });

        // Modelos que no tienen alternativa: se conserva el camino de siempre (post_al_proveedor).
        [$conversation, $assistant] = $this->conversacion_del_asistente('deepseek-sin-alternativa', 'deepseek-sin-alternativa-pro');

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);
            $this->fail('El reintento es uno solo: la segunda falla (529) se maneja como siempre, con su mensaje amable.');
        } catch (AsistenteIaException $e) {
            $this->assertSame(AsistenteIaException::MOTIVO_SOBRECARGADO, $e->motivo());
            $this->assertSame(['deepseek-sin-alternativa', 'deepseek-sin-alternativa'], $this->modelos_enviados(), 'Se reintenta el MISMO modelo, una vez.');
        }
    }

    public function test_el_asistente_sin_variante_recupera_un_error_transitorio_con_el_mismo_modelo(): void
    {
        $this->fingir_deepseek(function ($n) {
            if ($n === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
            }
            return Http::response($this->fin_de_turno('Ahora sí.'), 200);
        });

        [$conversation, $assistant] = $this->conversacion_del_asistente('deepseek-sin-alternativa', 'deepseek-sin-alternativa-pro');

        $this->assertSame('Ahora sí.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertSame(['deepseek-sin-alternativa', 'deepseek-sin-alternativa'], $this->modelos_enviados());
    }

    public function test_a_mitad_de_turno_despues_de_una_herramienta_que_escribe_no_cambia_de_variante_ni_duplica_efectos(): void
    {
        $pedidos = [];
        Http::fake(function ($request) use (&$pedidos) {
            $pedidos[] = $request->data();
            $manana = Carbon::tomorrow()->format('Y-m-d');
            if (count($pedidos) === 1) {
                return Http::response($this->respuesta_del_modelo('tool_use', [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_respaldo_1',
                    'name'  => 'proponer_tarea',
                    'input' => ['detalle' => 'ZZRESPALDO tarea que se registra una sola vez', 'fecha' => $manana],
                ]]), 200);
            }
            // La vuelta que sigue a la carga: primero un 503 y después responde.
            return count($pedidos) === 2
                ? Http::response(['error' => ['type' => 'overloaded_error']], 503)
                : Http::response($this->fin_de_turno('Tarea anotada.'), 200);
        });

        [$conversation, $assistant] = $this->conversacion_del_asistente('deepseek-flash', 'deepseek-v4-pro', ConfianzaDelAgenteIaHelper::DIRECTO);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $modelos = array_column($pedidos, 'model');
        $this->assertCount(3, $pedidos, 'La carga, la vuelta que falla y UN reintento.');
        // La carga escala a Pro desde la vuelta siguiente (de siempre); el reintento repite ESE modelo y no pasa a la otra variante.
        $this->assertSame(['deepseek-flash', 'deepseek-v4-pro', 'deepseek-v4-pro'], $modelos);
        $this->assertSame($pedidos[1]['messages'], $pedidos[2]['messages'], 'Mismo historial: nada se reenvía distinto.');
        $this->assertSame('listo', $assistant->fresh()->estado);
        $this->assertStringContainsString('Tarea anotada.', (string) $assistant->fresh()->contenido);

        $tarjetas = AiMessageAction::where('ai_message_id', $assistant->id)->get();
        $this->assertCount(1, $tarjetas, 'La herramienta que escribe se ejecutó UNA sola vez.');
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, (string) $tarjetas[0]->estado);
    }
}
