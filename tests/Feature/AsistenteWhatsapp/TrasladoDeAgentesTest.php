<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Events\SupportMessageReceived;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiMessage;
use App\Models\MostradorReporte;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTour;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class TrasladoDeAgentesTest extends AsistenteWhatsappTestCase
{
    private const INBOUND = 'https://admin.test/api/inbound';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();
        $this->dar_extension('support_chat');
        config([
            'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.client_uuid' => (string) Str::uuid(),
            'services.admin_api.inbound_key' => self::CLAVE,
            'broadcasting.default' => 'null',
        ]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
        $this->actingAs($this->comercio, 'web');
        Queue::fake();
        Event::fake([SupportMessageReceived::class]);
    }

    public function test_continuar_desde_el_sistema_cierra_cita_reintento_y_respuesta_whatsapp(): void
    {
        $anterior = $this->conversacion_whatsapp(now()->subHour());
        $hilo = $this->conversacion_whatsapp();
        $entrada = $this->mandar(['ai_conversation_id' => $hilo->id, 'whatsapp_message_id' => 'wamid.mismo'])->assertStatus(202);
        $mensaje_wa = $entrada->json('ai_message_id');
        AiMessage::whereKey($mensaje_wa)->update(['estado' => 'listo', 'contenido' => 'Respuesta anterior de WhatsApp']);

        $ruta = '/api/ai-conversations/' . $hilo->id . '/messages';
        $datos = ['contenido' => 'Sigo desde el sistema', 'agentes_version' => 1];
        $this->getJson($ruta)->assertOk();
        $this->postJson($ruta, $datos)->assertStatus(409)->assertJsonPath('code', 'confirmar_traspaso_a_sistema');
        $this->assertNull($hilo->fresh()->sistema_exclusivo_at);
        $this->postJson($ruta, $datos + ['traspasar_a_sistema' => 1])->assertCreated()
            ->assertJsonPath('user_message.ai_conversation_id', $hilo->id);
        $this->assertNotNull($hilo->fresh()->sistema_exclusivo_at);

        $this->mandar(['ai_conversation_id' => $hilo->id])->assertStatus(409)->assertJsonPath('code', 'conversacion_solo_sistema');
        $this->mandar(['whatsapp_message_id' => 'wamid.mismo'])->assertStatus(409)->assertJsonPath('code', 'conversacion_solo_sistema');
        $this->getJson('/api/admin-sync/asistente/mensajes/' . $mensaje_wa, $this->headers())
            ->assertOk()->assertExactJson(['estado' => 'solo_sistema', 'contenido' => null, 'adjuntos' => []]);

        $nuevo = $this->mandar(['texto' => 'Tema nuevo'])->assertStatus(202)->json('ai_conversation_id');
        $this->assertNotContains($nuevo, [$anterior->id, $hilo->id]);
        $this->assertDatabaseHas('ai_conversations', ['id' => $nuevo, 'origen' => 'whatsapp', 'sistema_exclusivo_at' => null]);
    }

    public function test_el_origen_de_un_reporte_depende_del_primer_mensaje_del_usuario(): void
    {
        $reporte = $this->conversacion_whatsapp();
        $reporte->update(['origen' => MostradorReporte::ORIGEN_CONVERSACION]);
        $this->mensaje($reporte, 'user', 'listo', ['canal' => AiMessage::CANAL_WHATSAPP, 'contenido' => '¿Qué vendí?']);

        $this->getJson('/api/ai-conversations')->assertOk()->assertJsonPath('models.0.origen_canal', 'whatsapp')
            ->assertJsonPath('models.0.origen', MostradorReporte::ORIGEN_CONVERSACION);
        $this->postJson('/api/ai-conversations/' . $reporte->id . '/messages', [
            'contenido' => 'Sigo con el reporte', 'agentes_version' => 1, 'traspasar_a_sistema' => 1,
        ])->assertCreated();
        $this->mandar(['ai_conversation_id' => $reporte->id])->assertStatus(409)
            ->assertJsonPath('code', 'conversacion_solo_sistema');
    }

    public function test_la_entrega_en_curso_reserva_el_hilo_hasta_que_admin_la_libera(): void
    {
        $hilo = $this->conversacion_whatsapp();
        $mensaje = $this->mensaje($hilo, 'assistant', 'listo', ['contenido' => 'Respuesta lista para entregar']);
        $estado = '/api/admin-sync/asistente/mensajes/' . $mensaje->id;
        $enviar = '/api/ai-conversations/' . $hilo->id . '/messages';
        $pedido = ['contenido' => 'Sigo desde el sistema', 'agentes_version' => 1, 'traspasar_a_sistema' => 1];
        $reserva = $this->headers() + ['X-Reservar-Entrega-Whatsapp' => '1'];

        $token = $this->getJson($estado, $reserva)->assertOk()->assertJsonPath('entrega_reservada', true)->json('entrega_token');
        $this->assertTrue(Str::isUuid($token));
        $this->getJson($estado, $reserva)->assertOk()->assertJsonPath('estado', 'pendiente');
        $this->postJson($enviar, $pedido)->assertStatus(409)->assertJsonPath('code', 'respuesta_en_curso');
        $this->assertNull($hilo->fresh()->sistema_exclusivo_at);

        $this->postJson($estado . '/liberar-entrega', ['entrega_token' => (string) Str::uuid()], $this->headers())->assertOk();
        $this->postJson($enviar, $pedido)->assertStatus(409);
        $this->postJson($estado . '/liberar-entrega', ['entrega_token' => $token], $this->headers())->assertOk();
        $this->postJson($enviar, $pedido)->assertCreated();
        $this->getJson($estado, $reserva)->assertOk()->assertJsonPath('estado', 'solo_sistema');
    }

    public function test_los_avisos_del_hilo_trasladado_no_se_ofrecen_para_whatsapp(): void
    {
        $trasladado = $this->conversacion_whatsapp();
        $trasladado->update(['sistema_exclusivo_at' => now()]);
        $vigente = $this->conversacion_whatsapp();
        foreach ([$trasladado, $vigente] as $hilo) {
            $respuesta = $this->mensaje($hilo, 'assistant', 'error');
            AiAvisoDelAsistente::create([
                'uuid' => (string) Str::uuid(),
                'owner_id' => $this->comercio->id,
                'ai_conversation_id' => $hilo->id,
                'ai_message_id' => $respuesta->id,
                'tipo' => AiAvisoDelAsistente::TIPO_TURNO_SIN_TERMINAR,
                'texto' => 'El turno quedó pendiente.',
            ]);
        }

        $this->getJson('/api/admin-sync/asistente/avisos-pendientes', $this->headers())
            ->assertOk()->assertJsonCount(1, 'avisos')->assertJsonPath('avisos.0.ai_conversation_id', $vigente->id);
    }

    public function test_cada_agente_devuelve_su_numero_sin_exponer_el_del_asistente_al_empleado(): void
    {
        Http::fake([
            self::INBOUND . '/agentes/canales*' => Http::response([
                'asistente' => '+54 9 (345) 123-4567', 'soporte' => '5493457654321',
            ]),
        ]);
        $this->getJson('/api/agentes/canales')->assertOk()
            ->assertExactJson(['asistente' => '5493451234567', 'soporte' => '5493457654321']);

        Auth::forgetGuards();
        $this->actingAs($this->empleado, 'web');
        $this->getJson('/api/agentes/canales')->assertOk()
            ->assertExactJson(['asistente' => null, 'soporte' => '5493457654321']);
        Http::assertSent(function (Request $pedido) {
            return $pedido->hasHeader('X-Admin-Api-Key', self::CLAVE)
                && strpos($pedido->url(), 'client_user_id=' . $this->empleado->id) !== false
                && strpos($pedido->url(), 'es_dueno=0') !== false;
        });
    }

    public function test_el_listado_relaciona_numeros_de_admin_y_ordena_whatsapp_antes_de_abrirlo(): void
    {
        $ticket_erp = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        $ticket_ajeno = SupportTicket::create(['user_id' => $this->empleado->id, 'status' => 'open']);
        SupportMessage::create([
            'support_ticket_id' => $ticket_erp->id, 'sender_type' => 'user', 'kind' => 'text', 'body' => 'Pregunta ERP',
            'created_at' => '2026-11-08 11:00:00',
        ]);
        $ticket_wa = [
            'uuid' => (string) Str::uuid(), 'origen' => 'whatsapp', 'status' => 'open',
            'opened_at' => '2026-11-07T10:00:00-03:00', 'admin_ticket_id' => 83,
            'last_message_at' => '2026-11-08T12:00:00-03:00',
        ];
        Http::fake([
            self::INBOUND . '/agentes/soporte*' => Http::response([
                'models' => [$ticket_wa], 'ticket_numbers' => [$ticket_erp->uuid => 82, $ticket_ajeno->uuid => 88],
            ]),
        ]);

        $this->getJson('/api/support-ticket?agentes=1')->assertOk()
            ->assertJsonPath('models.0.admin_ticket_id', 83)
            ->assertJsonPath('models.0.messages', [])
            ->assertJsonPath('models.1.admin_ticket_id', 82);
        $this->getJson('/api/support-ticket/' . $ticket_erp->id)->assertOk()->assertJsonPath('model.admin_ticket_id', 82);
        $this->assertNull($ticket_ajeno->fresh()->admin_ticket_id);
    }

    /*
     * Hasta el 12/11/2026 la sesión técnica de este caso era el login maestro. Lucas pidió que el
     * maestro que entra como el dueño vea Soporte entero, también las conversaciones de WhatsApp
     * (misión maestro-auditoria-completa / maestro-asistente-completo): ese caso pasa al test de abajo.
     * La sesión técnica que sigue leyendo solo las referencias ERP es el acceso firmado de soporte.
     */
    public function test_la_sesion_tecnica_lee_solo_las_referencias_de_sus_tickets_erp(): void
    {
        $ticket_erp = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        $ticket_wa = ['uuid' => (string) Str::uuid(), 'origen' => 'whatsapp', 'status' => 'open'];
        Http::fake([
            self::INBOUND . '/agentes/soporte*' => Http::response([
                'models' => [$ticket_wa], 'ticket_numbers' => [$ticket_erp->uuid => 82],
            ]),
        ]);

        $this->withSession(['acceso_soporte_token_id' => 7])
            ->getJson('/api/support-ticket?agentes=1')->assertOk()
            ->assertJsonCount(1, 'models')->assertJsonPath('models.0.admin_ticket_id', 82);
        $this->assertDatabaseMissing('support_tickets', ['uuid' => $ticket_wa['uuid']]);
        Http::assertSent(function (Request $pedido) {
            return $pedido->hasHeader('X-Admin-Api-Key', self::CLAVE)
                && strpos($pedido->url(), 'solo_referencias_erp=1') !== false;
        });
    }

    public function test_soporte_importa_sin_duplicar_y_espera_confirmacion_de_admin_para_marcar_traslado(): void
    {
        $ticket_uuid = (string) Str::uuid();
        $mensaje_uuid = (string) Str::uuid();
        $ticket = [
            'uuid' => $ticket_uuid, 'origen' => 'whatsapp', 'status' => 'open', 'name' => 'Consulta por WhatsApp',
            'opened_at' => '2026-11-08T12:01:00.000000Z', 'sistema_exclusivo_at' => null,
        ];
        $detalle = $ticket + ['messages' => [[
            'uuid' => $mensaje_uuid, 'sender_type' => 'user', 'kind' => 'image',
            'body' => 'Primero por WhatsApp', 'created_at' => '2026-11-08T12:02:00.000000Z',
            'attachments' => [['id' => 42, 'mime' => 'image/png', 'public_url' => 'https://admin.test/adjunto?signature=prueba']],
        ]]];
        $confirma_traslado = false;
        $autoriza_historial = true;
        Http::fake(function (Request $pedido) use ($ticket_uuid, $ticket, $detalle, &$confirma_traslado, &$autoriza_historial) {
            $url = strtok($pedido->url(), '?');
            if ($url === self::INBOUND . '/agentes/soporte') {
                return Http::response(['models' => $autoriza_historial ? [$ticket] : []]);
            }
            if ($url === self::INBOUND . '/agentes/soporte/' . $ticket_uuid) {
                return $autoriza_historial ? Http::response(['model' => $detalle]) : Http::response([], 404);
            }
            $this->assertSame(self::INBOUND . '/support/messages', $url);
            $this->assertSame($ticket_uuid, $pedido['ticket_uuid']);
            $this->assertSame(1, $pedido['agentes_version']);
            $this->assertSame(1, $pedido['traspasar_a_sistema']);

            return Http::response($confirma_traslado ? ['sistema_exclusivo_at' => '2026-11-08T12:12:00.000000Z'] : []);
        });

        $this->getJson('/api/support-ticket')->assertOk()->assertJsonCount(0, 'models');
        $id = $this->getJson('/api/support-ticket?agentes=1')->assertOk()->assertJsonCount(1, 'models')->json('models.0.id');
        $ruta = '/api/support-ticket/' . $id . '?agentes=1';
        $this->getJson($ruta)->assertOk()->assertJsonPath('model.messages.0.attachments.0.public_url', 'https://admin.test/adjunto?signature=prueba');
        $this->getJson($ruta)->assertOk()->assertJsonCount(1, 'model.messages')->assertJsonCount(1, 'model.messages.0.attachments');
        $this->assertDatabaseHas('support_messages', ['uuid' => $mensaje_uuid, 'support_ticket_id' => $id]);

        $autoriza_historial = false;
        $this->getJson('/api/support-ticket?agentes=1')->assertOk()->assertJsonCount(0, 'models');
        $this->getJson($ruta)->assertNotFound();
        $autoriza_historial = true;

        $respuesta = ['body' => 'Seguimos en Empresa', 'support_ticket_id' => $id, 'agentes_version' => 1];
        $this->postJson('/api/support-message', $respuesta)->assertStatus(409)->assertJsonPath('code', 'confirmar_traspaso_a_sistema');
        $fallido = $this->postJson('/api/support-message', $respuesta + ['traspasar_a_sistema' => 1])->assertCreated()
            ->assertJsonPath('model.remote_delivery_status', 'not_received')->json('model');
        $this->assertNull(SupportTicket::find($id)->sistema_exclusivo_at);

        $confirma_traslado = true;
        $this->postJson('/api/support-message/' . $fallido['id'] . '/retry-remote-sync')->assertOk()
            ->assertJsonPath('model.ticket.sistema_exclusivo_at', '2026-11-08T12:12:00.000000Z');
        $this->assertNotNull(SupportTicket::find($id)->sistema_exclusivo_at);
        $autoriza_historial = false;
        $this->getJson($ruta)->assertOk()->assertJsonCount(2, 'model.messages');

        $respuesta_de_soporte = ['ticket_uuid' => $ticket_uuid, 'message_uuid' => (string) Str::uuid(), 'body' => 'Soy Martín'];
        $this->postJson('/api/admin-sync/support/messages', $respuesta_de_soporte)->assertUnauthorized();
        $this->postJson('/api/admin-sync/support/messages', $respuesta_de_soporte, $this->headers())->assertCreated();
        $this->putJson('/api/admin-sync/support/tickets/' . $ticket_uuid, ['name' => 'Consulta editada'])->assertUnauthorized();
        $this->putJson('/api/admin-sync/support/tickets/' . $ticket_uuid, ['name' => 'Consulta editada'], $this->headers())
            ->assertOk()->assertJsonPath('model.name', 'Consulta editada');

        Auth::forgetGuards();
        $this->actingAs($this->empleado, 'web')->getJson($ruta)->assertNotFound();
    }

    public function test_el_primer_envio_web_nuevo_y_su_reintento_no_reutilizan_ni_duplican_tickets(): void
    {
        Http::fake([self::INBOUND . '/support/messages' => Http::response(['ok' => true], 201)]);
        $otro = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        $datos = [
            'body' => 'Otro tema', 'mensaje_uuid' => (string) Str::uuid(),
            'agentes_version' => 1, 'nueva_conversacion' => 1,
        ];
        $primero = $this->postJson('/api/support-message', $datos)->assertCreated()->json('model');
        $this->assertNotSame($otro->id, $primero['support_ticket_id']);
        $this->postJson('/api/support-message', $datos)->assertOk()->assertJsonPath('model.id', $primero['id']);
        $this->assertSame(1, SupportMessage::where('support_ticket_id', $primero['support_ticket_id'])->count());
        $this->assertSame(2, SupportTicket::where('user_id', $this->comercio->id)->count());
        Http::assertSentCount(1);
    }

    public function test_el_reintento_del_uuid_reenvia_el_mismo_mensaje_si_no_hubo_acuse(): void
    {
        Http::fakeSequence(self::INBOUND . '/support/messages')->push([], 503)->push(['ok' => true], 201);
        $uuid = (string) Str::uuid();
        $datos = [
            'body' => 'El primer envío se interrumpió', 'mensaje_uuid' => $uuid,
            'agentes_version' => 1, 'nueva_conversacion' => 1,
        ];

        $mensaje = $this->postJson('/api/support-message', $datos)->assertCreated()
            ->assertJsonPath('model.remote_delivery_status', 'not_received')->json('model');
        $this->postJson('/api/support-message', $datos)->assertOk()
            ->assertJsonPath('model.id', $mensaje['id'])->assertJsonPath('model.remote_delivery_status', null);

        $this->assertSame(1, SupportTicket::where('user_id', $this->comercio->id)->count());
        $this->assertSame([$uuid, $uuid], Http::recorded()->map(function ($registro) {
            return $registro[0]['message_uuid'];
        })->all());
    }

    public function test_el_envio_implicito_reutiliza_solo_el_ticket_abierto_del_usuario(): void
    {
        Http::fake([self::INBOUND . '/support/messages' => Http::response(['ok' => true], 201)]);
        $abierto = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'closed']);
        SupportTicket::create(['user_id' => $this->empleado->id, 'status' => 'open']);

        foreach (['Primera consulta', 'Sigo en el mismo hilo'] as $texto) {
            $this->postJson('/api/support-message', [
                'body' => $texto, 'mensaje_uuid' => (string) Str::uuid(), 'agentes_version' => 1,
            ])->assertCreated()->assertJsonPath('model.support_ticket_id', $abierto->id);
        }

        $this->assertSame(1, SupportTicket::where('user_id', $this->comercio->id)->where('status', 'open')->count());
        $this->assertSame(2, $abierto->messages()->count());
    }

    public function test_el_orden_no_cambia_al_leer_cerrar_o_descargar_el_historial_remoto(): void
    {
        $fecha_vieja = now()->subDays(3);
        $fecha_reciente = now()->subDay();
        $uuid_whatsapp = (string) Str::uuid();
        $modelo_whatsapp = [
            'uuid' => $uuid_whatsapp, 'origen' => 'whatsapp', 'status' => 'closed',
            'opened_at' => $fecha_vieja->toIso8601String(), 'last_message_at' => $fecha_reciente->toIso8601String(),
        ];
        Http::fake([
            self::INBOUND . '/agentes/soporte/' . $uuid_whatsapp . '*' => Http::response(['model' => $modelo_whatsapp + [
                'messages' => [[
                    'uuid' => (string) Str::uuid(), 'sender_type' => 'admin',
                    'body' => 'Respuesta de WhatsApp', 'created_at' => $fecha_reciente->toIso8601String(),
                ]],
            ]]),
            self::INBOUND . '/agentes/soporte*' => Http::response(['models' => [$modelo_whatsapp]]),
            self::INBOUND . '/*' => Http::response(['ok' => true]),
        ]);

        $ticket = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        $mensaje = $ticket->messages()->create(['sender_type' => 'admin', 'body' => 'Respuesta anterior', 'created_at' => $fecha_vieja]);
        $sin_mensajes = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'closed']);
        SupportTicket::create(['user_id' => $this->empleado->id, 'status' => 'open']);

        $lista = '/api/support-ticket?agentes=1';
        $whatsapp_id = $this->getJson($lista)->assertOk()->assertJsonCount(3, 'models')
            ->assertJsonPath('models.0.uuid', $uuid_whatsapp)->assertJsonCount(0, 'models.0.messages')
            ->assertJsonPath('models.1.id', $ticket->id)->assertJsonPath('models.2.id', $sin_mensajes->id)->json('models.0.id');

        $this->getJson('/api/support-ticket/' . $whatsapp_id . '?agentes=1')->assertOk()->assertJsonCount(1, 'model.messages');
        $this->postJson('/api/support-message/' . $mensaje->id . '/mark-read')->assertOk();
        $this->putJson('/api/support-ticket/' . $ticket->id, ['status' => 'closed'])->assertOk();
        $this->getJson($lista)->assertOk()->assertJsonPath('models.0.id', $whatsapp_id)
            ->assertJsonPath('models.1.id', $ticket->id)->assertJsonPath('models.1.unread_messages_count', 0);

        $ticket->messages()->create(['sender_type' => 'admin', 'body' => 'Respuesta nueva']);
        $this->getJson($lista)->assertOk()->assertJsonPath('models.0.id', $ticket->id)
            ->assertJsonPath('models.1.id', $whatsapp_id)->assertJsonPath('models.2.id', $sin_mensajes->id);
    }

    public function test_robotito_conserva_el_hilo_solo_si_ya_esta_en_el_sistema(): void
    {
        Http::fake([
            self::INBOUND . '/support/messages' => Http::response(['sistema_exclusivo_at' => now()->toIso8601String()]),
        ]);
        foreach ([null, now()] as $traspasado) {
            $ticket = SupportTicket::create([
                'user_id' => $this->comercio->id,
                'origen' => 'whatsapp',
                'status' => 'open',
                'sistema_exclusivo_at' => $traspasado,
            ]);
            $tour = SupportTour::create([
                'uuid' => (string) Str::uuid(),
                'owner_id' => $this->comercio->id,
                'recipient_id' => $this->comercio->id,
                'ticket_uuid' => $ticket->uuid,
                'origin_ticket_uuid' => $ticket->uuid,
                'source' => 'whatsapp',
                'tema' => 'crear_ticket',
                'titulo' => 'Creá un ticket',
                'textos' => [],
                'total_steps' => 3,
                'state' => 'accepted',
                'steps_shown' => [1],
            ]);
            $this->postJson('/api/support-tour/' . $tour->uuid . '/question', [
                'question' => '¿Cómo sigo?', 'step' => 1,
            ])->assertOk();
            $this->assertSame($traspasado !== null, $tour->fresh()->question_ticket_uuid === $ticket->uuid);
        }
    }

    /** El maestro que entra como el dueño ve Soporte entero, incluidas las conversaciones de WhatsApp. */
    public function test_el_maestro_como_dueno_ve_tambien_las_conversaciones_de_whatsapp(): void
    {
        $ticket_erp = SupportTicket::create(['user_id' => $this->comercio->id, 'status' => 'open']);
        $ticket_wa = ['uuid' => (string) Str::uuid(), 'origen' => 'whatsapp', 'status' => 'open'];
        Http::fake([
            self::INBOUND . '/agentes/soporte*' => Http::response([
                'models' => [$ticket_wa], 'ticket_numbers' => [$ticket_erp->uuid => 82],
            ]),
        ]);

        $this->withSession(['master_login_bypass_user_last_activity' => true])
            ->getJson('/api/support-ticket?agentes=1')->assertOk()->assertJsonCount(2, 'models');
        $this->assertDatabaseHas('support_tickets', ['uuid' => $ticket_wa['uuid']]);
    }
}
