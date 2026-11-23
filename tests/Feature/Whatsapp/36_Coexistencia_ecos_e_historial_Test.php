<?php

namespace Tests\Feature\Whatsapp;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappAgentScheduler;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgentesIa\OtorgaAgentesIa;
use Tests\TestCase;

/**
 * Misión whatsapp-coexistencia-ecos (23/11/2026) — WhatsApp Business en coexistencia (el dueño sigue usando la app
 * del celular). Kapso reenvía lo que el dueño escribe desde el celular (`message.kapso.origin = business_app`) y el
 * historial importado (`origin = history_sync`). Lo que protege este archivo:
 *
 * 🔴 EL AGENTE NO CONTESTA ENCIMA DEL DUEÑO NI MENSAJES VIEJOS. Un eco frena la respuesta pendiente (borrador, token,
 * job en vuelo, señal de pedido) y el historial no abre la ventana de 24 h, no suma no leídos ni agenda al agente.
 * 🔴 IDEMPOTENCIA POR WAMID Y AISLAMIENTO POR `phone_number_id`: repetir un eco o cruzar de comercio no deja rastro.
 *
 * Todos los casos entran por el webhook firmado real (`api/whatsapp-bot/webhook`), nunca por el helper nuevo.
 * Un solo `Http::fake()` por método y con el stub `'*'` al final (Laravel 8 acumula stubs).
 */
class Coexistencia_ecos_e_historial_Test extends TestCase
{
    use DatabaseTransactions;
    use OtorgaAgentesIa;

    const PHONE = '5493415550036';
    const PNID = '5491100000036';
    const SECRET = 'secreto-webhook-coexistencia';
    const TS_HIST = 1790866800; // 2026-10-01 12:00 en Buenos Aires
    const TS_HIST_2 = 1790867100; // 12:05

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var array Eventos de log capturados (level, message). */
    protected $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio coexistencia',
            'company_name' => 'Ferretería coexistencia',
            'email'        => 'coexistencia-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $this->otorgar_agentes_ia($this->comercio);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-coexistencia',
            'phone_number_id'    => self::PNID,
            'webhook_secret'     => self::SECRET,
            'is_active'          => true,
            'ai_enabled_default' => true,
            // Con demora 0 el scheduler corre el job en sync + afterResponse y `Queue::fake()` no lo ve:
            // un `assertNotPushed` sería vacío. Con demora > 0 el job va a la cola y el fake lo registra.
            'ai_reply_delay_seconds' => 30,
        ]);

        $this->logs = [];
        app('events')->listen(MessageLogged::class, function ($evento) {
            $this->logs[] = ['level' => $evento->level, 'message' => $evento->message];
        });
    }

    // ------------------------------------------------------------------------------------------------ helpers

    protected function chat_de($telefono = self::PHONE, array $extra = [])
    {
        return WhatsappChat::create(array_merge([
            'user_id'         => $this->comercio->id,
            'phone'           => $telefono,
            'ai_enabled'      => true,
            'unread_count'    => 0,
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
        ], $extra));
    }

    protected function mensaje($chat, $direction, $source, $body, $wamid = null, array $extra = [])
    {
        return WhatsappChatMessage::create(array_merge([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => $direction,
            'source'           => $source,
            'body'             => $body,
            'wa_message_id'    => $wamid,
            'delivery_status'  => $direction === 'out' ? 'entregado' : 'pendiente',
        ], $extra));
    }

    protected function borrador($chat)
    {
        return $this->mensaje($chat, 'out', 'ia', 'Borrador del agente', null, ['ai_status' => 'a_confirmar']);
    }

    /**
     * Payload tal como lo manda Kapso para un eco/historial/vivo.
     *
     * @param  string  $origin  business_app | history_sync | cloud_api | '' (sin origin)
     * @param  string  $direction  outbound | inbound
     * @param  array  $message  Campos de `message` que pisan los de defecto.
     * @param  array  $raiz  Campos de la raíz que pisan los de defecto (null borra la clave).
     */
    protected function payload($origin, $direction, array $message = [], array $raiz = [])
    {
        $es_saliente = $direction === 'outbound';
        $kapso = ['direction' => $direction];
        if ($origin !== '') {
            $kapso['origin'] = $origin;
        }
        $base_message = array_merge([
            'id'        => 'wamid.ECO1',
            ($es_saliente ? 'to' : 'from') => self::PHONE,
            'timestamp' => (string) time(),
            'type'      => 'text',
            'text'      => ['body' => 'Te lo guardo hasta el sábado'],
            'kapso'     => $kapso,
        ], $message);
        foreach ($base_message as $clave => $valor) {
            if (is_null($valor)) {
                unset($base_message[$clave]);
            }
        }

        $payload = array_merge([
            'message'         => $base_message,
            'conversation'    => ['phone_number' => '+' . self::PHONE],
            'phone_number_id' => self::PNID,
        ], $raiz);
        foreach ($payload as $clave => $valor) {
            if (is_null($valor)) {
                unset($payload[$clave]);
            }
        }

        return $payload;
    }

    protected function eco(array $message = [], array $raiz = [])
    {
        return $this->payload('business_app', 'outbound', $message, $raiz);
    }

    protected function historial_saliente(array $message = [], array $raiz = [])
    {
        return $this->payload('history_sync', 'outbound', array_merge(['id' => 'wamid.HIST_OUT1', 'timestamp' => (string) self::TS_HIST_2], $message), $raiz);
    }

    protected function historial_entrante(array $message = [], array $raiz = [])
    {
        return $this->payload('history_sync', 'inbound', array_merge([
            'id' => 'wamid.HIST_IN1', 'timestamp' => (string) self::TS_HIST, 'text' => ['body' => '¿Tienen tornillos 8x40?'],
        ], $message), $raiz);
    }

    protected function vivo(array $message = [], $origin = 'cloud_api')
    {
        return $this->payload($origin, 'inbound', array_merge(['id' => 'wamid.VIVO1', 'text' => ['body' => 'Hola, ¿tienen pintura?']], $message));
    }

    protected function post_evento($evento, array $payload, $secreto = self::SECRET)
    {
        $firma = hash_hmac('sha256', json_encode($payload), $secreto);

        return $this->postJson('api/whatsapp-bot/webhook', $payload, [
            'X-Webhook-Event'     => $evento,
            'X-Kapso-Signature'   => $firma,
            'X-Idempotency-Key'   => 'idem-' . uniqid(),
        ]);
    }

    protected function enviar_sent(array $payload)
    {
        return $this->post_evento('whatsapp.message.sent', $payload);
    }

    protected function enviar_received(array $payload)
    {
        return $this->post_evento('whatsapp.message.received', $payload);
    }

    protected function filas($chat = null)
    {
        $q = WhatsappChatMessage::query();
        if ($chat) {
            $q->where('whatsapp_chat_id', $chat->id);
        }

        return $q->orderBy('id')->get();
    }

    protected function logs_de_nivel($nivel)
    {
        return array_values(array_filter($this->logs, function ($l) use ($nivel) {
            return $l['level'] === $nivel;
        }));
    }

    protected function fakes_de_ia($callback_anthropic)
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => $callback_anthropic,
            'api.kapso.ai/*'      => function () {
                $this->llamadas_a_kapso++;

                return Http::response(['messages' => [['id' => 'wamid.DEPRUEBA']]], 200);
            },
            '*' => Http::response([], 200),
        ]);
    }

    /** @var int */
    protected $llamadas_a_kapso = 0;

    protected function respuesta_anthropic()
    {
        return Http::response([
            'model'   => 'claude-de-prueba',
            'content' => [['type' => 'text', 'text' => 'Respuesta del agente']],
            'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
        ], 200);
    }

    // ------------------------------------------------------------------------------------------------ CA1 a CA4

    /** @test CA1 */
    public function el_eco_del_celular_queda_en_la_conversacion_sin_despertar_al_agente()
    {
        Queue::fake();
        $chat = $this->chat_de(self::PHONE, ['unread_count' => 2]);
        $inbound_antes = $chat->fresh()->last_inbound_at->toDateTimeString();

        $this->enviar_sent($this->eco())->assertStatus(200);

        $filas = $this->filas($chat)->where('direction', 'out');
        $this->assertCount(1, $filas);
        $fila = $filas->first();
        $this->assertSame('celular', $fila->source);
        $this->assertNull($fila->sent_by_user_id);
        $this->assertSame('Te lo guardo hasta el sábado', $fila->body);
        $this->assertSame('wamid.ECO1', $fila->wa_message_id);
        $this->assertSame('enviado', $fila->delivery_status);

        $chat = $chat->fresh();
        $this->assertSame(2, (int) $chat->unread_count);
        $this->assertSame($inbound_antes, $chat->last_inbound_at->toDateTimeString());
        $this->assertSame('automatic', $chat->ai_mode);
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
    }

    /** @test CA2 */
    public function el_eco_descarta_el_borrador_y_cancela_la_respuesta_agendada()
    {
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 7, 'follow_up' => 'pedido']);
        $this->borrador($chat);

        $this->enviar_sent($this->eco())->assertStatus(200);

        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('ai_status', 'a_confirmar')->count());
        $chat = $chat->fresh();
        $this->assertSame(8, (int) $chat->ai_schedule_token);
        $this->assertNull($chat->follow_up);
    }

    /** @test CA3 */
    public function el_job_en_vuelo_no_contesta_despues_del_eco()
    {
        config(['services.anthropic.api_key' => 'clave-falsa-test']);
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 5, 'unread_count' => 1]);
        $this->mensaje($chat, 'in', 'cliente', 'Hola, ¿tienen pintura?', null, ['created_at' => now()->subMinutes(2)]);
        $this->fakes_de_ia($this->respuesta_anthropic());

        $this->enviar_sent($this->eco())->assertStatus(200);
        (new GenerateWhatsappAiReplyJob($chat->id, 5))->handle(new WhatsappAgentScheduler());

        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('source', 'ia')->count());
        Http::assertNothingSent();
    }

    /** @test CA3b */
    public function el_eco_durante_la_generacion_cancela_antes_de_persistir()
    {
        config(['services.anthropic.api_key' => 'clave-falsa-test']);
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 5, 'unread_count' => 1]);
        $this->mensaje($chat, 'in', 'cliente', 'Hola, ¿tienen pintura?', null, ['created_at' => now()->subMinutes(2)]);

        $llamadas_anthropic = 0;
        $this->fakes_de_ia(function () use (&$llamadas_anthropic) {
            $llamadas_anthropic++;
            if ($llamadas_anthropic === 1) {
                // El dueño contesta desde el celular MIENTRAS la IA genera: el eco entra por el webhook real.
                $this->enviar_sent($this->eco())->assertStatus(200);
            }

            return $this->respuesta_anthropic();
        });

        (new GenerateWhatsappAiReplyJob($chat->id, 5))->handle(new WhatsappAgentScheduler());

        $this->assertSame(1, $llamadas_anthropic);
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('source', 'ia')->count());
        $this->assertSame(0, $this->llamadas_a_kapso);
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'out')->where('source', 'celular')->count());
        $this->assertSame(6, (int) $chat->fresh()->ai_schedule_token);
    }

    /** @test CA4 */
    public function el_eco_repetido_no_duplica_ni_repite_efectos_y_recibe_sus_estados()
    {
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 3]);

        $this->enviar_sent($this->eco())->assertStatus(200);
        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.ECO1')->count());

        // Entre las dos entregas pasa algo nuevo en el chat: el repetido NO puede tocarlo (efectos sólo la primera vez).
        $this->borrador($chat);
        $chat->forceFill(['ai_schedule_token' => 20, 'follow_up' => 'pedido', 'last_message_at' => now()->subMinutes(10)])->save();
        $last_message_at = $chat->fresh()->last_message_at->toDateTimeString();

        $this->enviar_sent($this->eco())->assertStatus(200);

        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.ECO1')->count());
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('ai_status', 'a_confirmar')->count());
        $chat = $chat->fresh();
        $this->assertSame(20, (int) $chat->ai_schedule_token);
        $this->assertSame('pedido', $chat->follow_up);
        $this->assertSame($last_message_at, $chat->last_message_at->toDateTimeString());

        // (c) El estado `delivered` de un eco (origin business_app) avanza la fila igual que cualquier saliente.
        $entregado = $this->payload('business_app', 'outbound', ['id' => 'wamid.ECO1']);
        $this->post_evento('whatsapp.message.delivered', $entregado)->assertStatus(200);
        $this->assertSame('entregado', WhatsappChatMessage::where('wa_message_id', 'wamid.ECO1')->first()->delivery_status);
    }

    // ------------------------------------------------------------------------------------------------ CA5 a CA7

    /** @test CA5 */
    public function el_historial_entrante_no_agenda_ni_abre_ventana_ni_queda_sin_responder()
    {
        Queue::fake();
        $payload = $this->historial_entrante(['from' => '5493415550099'], ['conversation' => ['phone_number' => '+5493415550099']]);

        $this->enviar_received($payload)->assertStatus(200);

        $chat = WhatsappChat::where('user_id', $this->comercio->id)->where('phone', '5493415550099')->first();
        $this->assertNotNull($chat);
        $this->assertSame(0, (int) $chat->unread_count);
        $this->assertNull($chat->last_inbound_at);
        $filas = $this->filas($chat);
        $this->assertCount(1, $filas);
        $this->assertSame('in', $filas[0]->direction);
        $this->assertSame('historial', $filas[0]->source);
        $this->assertSame('wamid.HIST_IN1', $filas[0]->wa_message_id);
        $this->assertSame('2026-10-01 12:00:00', $filas[0]->created_at->format('Y-m-d H:i:s'));
        Queue::assertNotPushed(GenerateWhatsappAiReplyJob::class);
        $this->assertFalse($chat->is_sin_responder());
        $this->assertSame([], \App\Http\Controllers\Helpers\WhatsappChatHelper::chat_ids_sin_responder([$chat->id]));
        $this->assertNull($chat->estado_pendiente());

        // Repetido: sigue siendo una sola fila.
        $this->enviar_received($payload)->assertStatus(200);
        $this->assertCount(1, $this->filas($chat));
    }

    /** @test CA6 */
    public function el_historial_saliente_respeta_su_fecha_y_no_mueve_el_chat()
    {
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 7]);
        $this->borrador($chat);
        $last_message_at = $chat->fresh()->last_message_at->toDateTimeString();

        $this->enviar_sent($this->historial_saliente())->assertStatus(200);

        $fila = WhatsappChatMessage::where('wa_message_id', 'wamid.HIST_OUT1')->first();
        $this->assertNotNull($fila);
        $this->assertSame('out', $fila->direction);
        $this->assertSame('historial', $fila->source);
        $this->assertSame('enviado', $fila->delivery_status);
        $this->assertSame('2026-10-01 12:05:00', $fila->created_at->format('Y-m-d H:i:s'));
        $chat = $chat->fresh();
        $this->assertSame($last_message_at, $chat->last_message_at->toDateTimeString());
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('ai_status', 'a_confirmar')->count());
        $this->assertSame(7, (int) $chat->ai_schedule_token);
    }

    /** @test CA7 */
    public function el_historial_no_tapa_ni_crea_un_sin_responder()
    {
        // Caso 1: pendiente real + historial saliente posterior (id mayor, fecha vieja) → sigue sin responder.
        $chat = $this->chat_de();
        $this->mensaje($chat, 'in', 'cliente', 'Necesito presupuesto', 'wamid.REAL', ['created_at' => now()->subMinutes(5)]);
        $inbound = $chat->fresh()->last_inbound_at->toDateTimeString();
        $this->enviar_sent($this->historial_saliente())->assertStatus(200);
        $this->assertTrue($chat->fresh()->is_sin_responder());
        $this->assertSame([$chat->id], \App\Http\Controllers\Helpers\WhatsappChatHelper::chat_ids_sin_responder([$chat->id]));
        $this->assertSame($inbound, $chat->fresh()->last_inbound_at->toDateTimeString());

        // Caso 2: el único mensaje es un entrante de historial → no está sin responder en las tres copias.
        $otro = $this->chat_de('5493415550098');
        $this->enviar_received($this->historial_entrante(['from' => '5493415550098'], ['conversation' => ['phone_number' => '+5493415550098']]))->assertStatus(200);
        $this->assertFalse($otro->fresh()->is_sin_responder());
        $this->assertSame([], \App\Http\Controllers\Helpers\WhatsappChatHelper::chat_ids_sin_responder([$otro->id]));

        // Tercera copia: el job no ve entrante sin responder (no llama a la IA).
        config(['services.anthropic.api_key' => 'clave-falsa-test']);
        $otro->forceFill(['ai_schedule_token' => 4])->save();
        $this->fakes_de_ia($this->respuesta_anthropic());
        (new GenerateWhatsappAiReplyJob($otro->id, 4))->handle(new WhatsappAgentScheduler());
        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $otro->id)->where('source', 'ia')->count());
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------------------------------------ CA8

    /** @return array */
    public function casos_de_aislamiento()
    {
        $casos = [];
        foreach (['eco', 'historial'] as $camino) {
            $casos["$camino a: phone_number_id ajeno"] = [$camino, 'ajeno'];
            $casos["$camino b: sin phone_number_id"] = [$camino, 'sin_pnid'];
            $casos["$camino c: config vacía"] = [$camino, 'config_vacia'];
            $casos["$camino d: sólo en conversation"] = [$camino, 'solo_conversation'];
        }

        return $casos;
    }

    /**
     * @test CA8
     * @dataProvider casos_de_aislamiento
     */
    public function sin_phone_number_id_igual_y_no_vacio_no_se_registra_nada($camino, $caso)
    {
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 7, 'follow_up' => 'pedido']);
        $this->borrador($chat);
        $last_message_at = $chat->fresh()->last_message_at->toDateTimeString();
        $chats_antes = WhatsappChat::count();
        $filas_antes = WhatsappChatMessage::count();

        $raiz = [];
        switch ($caso) {
            case 'ajeno':
                $raiz = ['phone_number_id' => '999'];
                break;
            case 'sin_pnid':
                $raiz = ['phone_number_id' => null];
                break;
            case 'config_vacia':
                $this->config->forceFill(['phone_number_id' => ''])->save();
                break;
            case 'solo_conversation':
                $raiz = ['phone_number_id' => null, 'conversation' => ['phone_number' => '+' . self::PHONE, 'phone_number_id' => self::PNID]];
                break;
        }

        if ($camino === 'eco') {
            $this->enviar_sent($this->eco([], $raiz))->assertStatus(200);
        } else {
            $this->enviar_sent($this->historial_saliente([], $raiz))->assertStatus(200);
        }

        if ($caso === 'solo_conversation') {
            $this->assertSame($filas_antes + 1, WhatsappChatMessage::count());

            return;
        }

        $this->assertSame($chats_antes, WhatsappChat::count());
        $this->assertSame($filas_antes, WhatsappChatMessage::count());
        $chat = $chat->fresh();
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('ai_status', 'a_confirmar')->count());
        $this->assertSame(7, (int) $chat->ai_schedule_token);
        $this->assertSame('pedido', $chat->follow_up);
        $this->assertSame($last_message_at, $chat->last_message_at->toDateTimeString());
        // Las trazas de un descarte por aislamiento importan: el canal daily sólo registra desde warning.
        $this->assertNotEmpty($this->logs_de_nivel('warning'));
    }

    /** @test CA8e */
    public function otro_comercio_no_recibe_nada()
    {
        $otro = User::create([
            'name' => 'Otro comercio', 'company_name' => 'Otra ferretería',
            'email' => 'otro-coex-' . uniqid() . '@test.local', 'password' => Hash::make('secret'),
        ]);
        $chat_otro = WhatsappChat::create([
            'user_id' => $otro->id, 'phone' => self::PHONE, 'ai_enabled' => true, 'unread_count' => 0,
            'last_message_at' => now()->subHour(), 'ai_schedule_token' => 7,
        ]);
        $this->chat_de();
        $last_message_at = $chat_otro->fresh()->last_message_at->toDateTimeString();

        $this->enviar_sent($this->eco())->assertStatus(200);

        $this->assertSame(0, WhatsappChatMessage::where('whatsapp_chat_id', $chat_otro->id)->count());
        $chat_otro = $chat_otro->fresh();
        $this->assertSame(7, (int) $chat_otro->ai_schedule_token);
        $this->assertSame($last_message_at, $chat_otro->last_message_at->toDateTimeString());
        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.ECO1')->count());
    }

    // ------------------------------------------------------------------------------------------------ CA9 a CA11

    /** @return array */
    public function casos_dedicados()
    {
        return [
            'cloud_api con id'     => ['cloud_api'],
            'sin origin con id'    => ['sin_origin'],
            'sin message.id'       => ['sin_id'],
            'simulate-inbound'     => ['simulado'],
            'sent cloud_api ajeno' => ['sent_ajeno'],
            'sent cloud_api propio' => ['sent_propio'],
        ];
    }

    /**
     * @test CA9
     * @dataProvider casos_dedicados
     */
    public function las_conexiones_dedicadas_siguen_igual_salvo_el_wamid($caso)
    {
        Queue::fake();
        $chat = $this->chat_de(self::PHONE, ['unread_count' => 1]);

        if (in_array($caso, ['cloud_api', 'sin_origin', 'sin_id'], true)) {
            $message = $caso === 'sin_id' ? ['id' => null] : [];
            $this->enviar_received($this->vivo($message, $caso === 'sin_origin' ? '' : 'cloud_api'))->assertStatus(200);
            $fila = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'in')->first();
            $this->assertNotNull($fila);
            $this->assertSame('cliente', $fila->source);
            $this->assertSame('Hola, ¿tienen pintura?', $fila->body);
            $this->assertSame($caso === 'sin_id' ? null : 'wamid.VIVO1', $fila->wa_message_id);
            $chat = $chat->fresh();
            $this->assertSame(2, (int) $chat->unread_count);
            $this->assertTrue($chat->last_inbound_at->gt(now()->subMinutes(5)));
            Queue::assertPushed(GenerateWhatsappAiReplyJob::class);
        } elseif ($caso === 'simulado') {
            $this->config->forceFill(['chat_simulation_enabled' => true])->save();
            $this->actingAs($this->comercio, 'web');
            $this->postJson('api/whatsapp-bot/simulate-inbound', ['phone' => self::PHONE, 'body' => 'Mensaje simulado'])->assertSuccessful();
            $fila = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'in')->first();
            $this->assertNotNull($fila);
            $this->assertNull($fila->wa_message_id);
        } elseif ($caso === 'sent_ajeno') {
            $this->enviar_sent($this->payload('cloud_api', 'outbound', ['id' => 'wamid.DESCONOCIDO']))->assertStatus(200);
            $this->assertSame(0, WhatsappChatMessage::count());
        } else {
            $propio = $this->mensaje($chat, 'out', 'ia', 'Respuesta', 'wamid.PROPIO', ['delivery_status' => 'pendiente']);
            $this->enviar_sent($this->payload('cloud_api', 'outbound', ['id' => 'wamid.PROPIO']))->assertStatus(200);
            $this->assertSame('enviado', $propio->fresh()->delivery_status);
            $this->assertSame(1, WhatsappChatMessage::count());
        }
    }

    /** @test CA10 */
    public function ecos_no_textuales_y_sin_telefono()
    {
        Http::fake(['*' => Http::response([], 500)]);
        $chat = $this->chat_de();

        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_IMG', 'type' => 'image', 'text' => null, 'image' => ['id' => 'media1', 'caption' => 'Así quedó']]))->assertStatus(200);
        $imagen = WhatsappChatMessage::where('wa_message_id', 'wamid.ECO_IMG')->first();
        $this->assertNotNull($imagen);
        $this->assertSame('Así quedó', $imagen->body);
        $this->assertSame('image', $imagen->media_type);

        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_AUD', 'type' => 'audio', 'text' => null, 'audio' => ['id' => 'media2']]))->assertStatus(200);
        $audio = WhatsappChatMessage::where('wa_message_id', 'wamid.ECO_AUD')->first();
        $this->assertNotNull($audio);
        $this->assertSame('[Audio enviado desde el celular]', $audio->body);

        $antes = WhatsappChatMessage::count();
        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_BSUID', 'to' => null], ['conversation' => ['business_scoped_user_id' => 'AR.123']]))->assertStatus(200);
        $this->assertSame($antes, WhatsappChatMessage::count());
        Http::assertNothingSent();
    }

    /** @test CA11 */
    public function los_estados_no_tocan_entrantes()
    {
        $chat = $this->chat_de();
        $historial = $this->mensaje($chat, 'in', 'historial', 'Viejo', 'wamid.HIST_IN1');
        $this->enviar_received($this->vivo());
        $vivo = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'in')->where('source', 'cliente')->first();
        $this->assertNotNull($vivo);

        foreach (['wamid.HIST_IN1', 'wamid.VIVO1'] as $wamid) {
            $this->post_evento('whatsapp.message.delivered', $this->payload('cloud_api', 'outbound', ['id' => $wamid]))->assertStatus(200);
        }

        $this->assertSame('pendiente', $historial->fresh()->delivery_status);
        $this->assertSame('pendiente', $vivo->fresh()->delivery_status);
    }

    // ------------------------------------------------------------------------------------------------ CA12

    /**
     * Arma la carrera de creación del chat: el listener de `creating` simula a la otra entrega.
     *
     * 🔴 El competidor se inserta DENTRO de la transacción del helper (así la INSERT del helper falla con un 1062 real
     * por `wch_user_phone_uq`), pero el rollback de esa transacción lo borraría y el reintento no encontraría nada.
     * Por eso un listener de `TransactionRolledBack` lo reinserta UNA vez, ya afuera: el reintento lo relee con lock.
     *
     * @param  string  $modo  duplicado | deadlock | siempre_deadlock
     * @return \stdClass Contadores: creating, reinsertado.
     */
    protected function armar_carrera($modo, $telefono)
    {
        $estado = (object) ['creating' => 0, 'rollbacks' => 0, 'reinsertado' => 0];
        $comercio_id = $this->comercio->id;
        $insertar = function () use ($comercio_id, $telefono) {
            DB::table('whatsapp_chats')->insert([
                'user_id' => $comercio_id, 'phone' => $telefono, 'ai_enabled' => 1, 'unread_count' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        WhatsappChat::creating(function ($chat) use ($modo, $telefono, $estado, $insertar) {
            if ($chat->phone !== $telefono) {
                return;
            }
            $estado->creating++;
            if ($modo === 'duplicado') {
                if ($estado->creating === 1) {
                    $insertar();
                }

                return;
            }
            if ($modo === 'siempre_deadlock' || $estado->creating === 1) {
                $pdo = new \PDOException('Deadlock found');
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw new QueryException('insert into whatsapp_chats', [], $pdo);
            }
        });

        if ($modo === 'duplicado') {
            app('events')->listen(TransactionRolledBack::class, function () use ($estado, $insertar) {
                $estado->rollbacks++;
                if ($estado->creating === 1 && $estado->reinsertado === 0) {
                    $estado->reinsertado++;
                    $insertar();
                }
            });
        }

        return $estado;
    }

    /** @test CA12 a (duplicado real 1062) */
    public function la_carrera_de_creacion_reintenta_una_vez_con_duplicado_real()
    {
        $telefono = '5493415550077';
        $estado = $this->armar_carrera('duplicado', $telefono);

        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_RACE', 'to' => $telefono], ['conversation' => ['phone_number' => '+' . $telefono]]))->assertStatus(200);

        $this->assertSame(1, $estado->creating);
        $this->assertSame(1, $estado->reinsertado);
        $chats = WhatsappChat::where('user_id', $this->comercio->id)->where('phone', $telefono)->get();
        $this->assertCount(1, $chats);
        $this->assertSame(3, (int) $chats[0]->unread_count);
        $filas = $this->filas($chats[0]);
        $this->assertCount(1, $filas);
        $this->assertSame('celular', $filas[0]->source);
        $this->assertSame('wamid.ECO_RACE', $filas[0]->wa_message_id);
    }

    /** @test CA12 b (deadlock) */
    public function la_carrera_de_creacion_reintenta_una_vez_ante_deadlock()
    {
        $telefono = '5493415550077';
        $estado = $this->armar_carrera('deadlock', $telefono);

        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_RACE', 'to' => $telefono], ['conversation' => ['phone_number' => '+' . $telefono]]))->assertStatus(200);

        $this->assertSame(2, $estado->creating);
        $this->assertSame(1, WhatsappChat::where('user_id', $this->comercio->id)->where('phone', $telefono)->count());
        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.ECO_RACE')->count());
    }

    /** @test CA12 c (dos fallos seguidos) */
    public function la_carrera_de_creacion_con_dos_fallos_se_rinde_sin_bucle()
    {
        $telefono = '5493415550077';
        $estado = $this->armar_carrera('siempre_deadlock', $telefono);

        $this->enviar_sent($this->eco(['id' => 'wamid.ECO_RACE', 'to' => $telefono], ['conversation' => ['phone_number' => '+' . $telefono]]))->assertStatus(200);

        $this->assertSame(2, $estado->creating);
        $this->assertSame(0, WhatsappChat::where('user_id', $this->comercio->id)->where('phone', $telefono)->count());
        $this->assertSame(0, WhatsappChatMessage::where('wa_message_id', 'wamid.ECO_RACE')->count());
        $this->assertCount(1, $this->logs_de_nivel('error'));
    }

    /** @test CA12 d (historial entrante, mismo fixture que a) */
    public function la_carrera_de_creacion_tambien_vale_para_el_historial_entrante()
    {
        $telefono = '5493415550077';
        $estado = $this->armar_carrera('duplicado', $telefono);

        $this->enviar_received($this->historial_entrante(['from' => $telefono], ['conversation' => ['phone_number' => '+' . $telefono]]))->assertStatus(200);

        $this->assertSame(1, $estado->creating);
        $chats = WhatsappChat::where('user_id', $this->comercio->id)->where('phone', $telefono)->get();
        $this->assertCount(1, $chats);
        $this->assertSame(3, (int) $chats[0]->unread_count);
        $filas = $this->filas($chats[0]);
        $this->assertCount(1, $filas);
        $this->assertSame('in', $filas[0]->direction);
        $this->assertSame('historial', $filas[0]->source);
    }

    // ------------------------------------------------------------------------------------------------ CA13 y CA14

    /** @test CA13 */
    public function el_historial_no_duplica_lo_que_entro_vivo_ni_lo_enviado()
    {
        Queue::fake();
        $chat = $this->chat_de();
        $this->enviar_received($this->vivo())->assertStatus(200);
        $chat = $chat->fresh();
        $unread = (int) $chat->unread_count;
        $inbound = $chat->last_inbound_at->toDateTimeString();
        $last = $chat->last_message_at->toDateTimeString();
        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.VIVO1')->count());

        $this->enviar_received($this->historial_entrante(['id' => 'wamid.VIVO1']))->assertStatus(200);

        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.VIVO1')->count());
        $this->assertSame('cliente', WhatsappChatMessage::where('wa_message_id', 'wamid.VIVO1')->first()->source);
        $chat = $chat->fresh();
        $this->assertSame($unread, (int) $chat->unread_count);
        $this->assertSame($inbound, $chat->last_inbound_at->toDateTimeString());
        $this->assertSame($last, $chat->last_message_at->toDateTimeString());

        $this->mensaje($chat, 'out', 'ia', 'Respuesta enviada', 'wamid.IA1');
        $this->enviar_sent($this->historial_saliente(['id' => 'wamid.IA1']))->assertStatus(200);
        $this->assertSame(1, WhatsappChatMessage::where('wa_message_id', 'wamid.IA1')->count());
        $this->assertSame('ia', WhatsappChatMessage::where('wa_message_id', 'wamid.IA1')->first()->source);
    }

    /** @return array */
    public function casos_de_tipos_ignorados()
    {
        $casos = [];
        foreach (['business_app', 'history_sync'] as $origen) {
            foreach (['reaction', 'system', 'unsupported', 'sin_type', 'legado', 'imagen_control'] as $tipo) {
                $casos["$origen / $tipo"] = [$origen, $tipo];
            }
        }

        return $casos;
    }

    /**
     * @test CA14
     * @dataProvider casos_de_tipos_ignorados
     */
    public function reacciones_sistema_y_no_soportados_no_dejan_rastro($origen, $tipo)
    {
        Queue::fake();
        $chat = $this->chat_de(self::PHONE, ['ai_schedule_token' => 7, 'follow_up' => 'pedido', 'unread_count' => 2]);
        $this->borrador($chat);
        $agente = $this->mensaje($chat, 'out', 'ia', 'Respuesta del agente', 'wamid.IA1');
        $last = $chat->fresh()->last_message_at->toDateTimeString();
        $filas_antes = WhatsappChatMessage::count();

        $message = ['id' => 'wamid.X-' . uniqid(), 'text' => null];
        switch ($tipo) {
            case 'reaction':
                $message = array_merge($message, ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.IA1', 'emoji' => '👎']]);
                break;
            case 'system':
                $message['type'] = 'system';
                break;
            case 'unsupported':
                $message['type'] = 'unsupported';
                break;
            case 'sin_type':
                $message['type'] = null;
                break;
            case 'legado':
                $message = array_merge($message, ['type' => 'text', 'text' => ['body' => 'Reacted with 👎 to message wamid.IA1']]);
                break;
            case 'imagen_control':
                $message = array_merge($message, ['type' => 'image', 'image' => ['id' => 'm1', 'caption' => 'Control']]);
                break;
        }

        if ($origen === 'business_app') {
            Http::fake(['*' => Http::response([], 500)]);
            $this->enviar_sent($this->payload('business_app', 'outbound', $message))->assertStatus(200);
        } else {
            Http::fake(['*' => Http::response([], 500)]);
            $this->enviar_received($this->payload('history_sync', 'inbound', array_merge($message, ['timestamp' => (string) self::TS_HIST])))->assertStatus(200);
        }

        if ($tipo === 'imagen_control') {
            $this->assertSame($filas_antes + 1, WhatsappChatMessage::count());

            return;
        }

        $this->assertSame($filas_antes, WhatsappChatMessage::count());
        $this->assertSame(1, WhatsappChat::count());
        $chat = $chat->fresh();
        $this->assertSame(1, WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('ai_status', 'a_confirmar')->count());
        $this->assertSame(7, (int) $chat->ai_schedule_token);
        $this->assertSame('pedido', $chat->follow_up);
        $this->assertSame($last, $chat->last_message_at->toDateTimeString());
        $this->assertSame(2, (int) $chat->unread_count);
        $this->assertNull($agente->fresh()->valoracion);
    }
}
