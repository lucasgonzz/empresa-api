<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * La bienvenida de la prueba adentro del sistema (misión prueba-e2e-campana-imperio, 11/11/2026).
 *
 * El admin, al dar la bienvenida, crea una conversación de Soporte del canal SISTEMA y le deja un mensaje
 * (el texto de la bienvenida y la URL del video), por las rutas que ya existían:
 *
 *   POST api/admin-sync/support/tickets   {ticket_uuid, client_user_id, name, status}
 *   POST api/admin-sync/support/messages  {ticket_uuid, message_uuid, kind, body, sender_admin_uuid}
 *
 * Este archivo no cambia nada del sistema: FIJA que una conversación NACIDA en el admin (sin ningún mensaje
 * previo del usuario) se guarda como del sistema, se lista en Agentes con su mensaje sin leer, y que los dos
 * pedidos son idempotentes por uuid. Es el contrato C-bienvenida del lado de empresa, y lo que el SPA usa
 * para abrir esa conversación directo cuando el negocio entra a Soporte.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class BienvenidaDeSoporteNacidaEnElAdminTest extends EmpresaTestCase
{
    const CLAVE = 'clave-del-admin-del-test-de-bienvenida-41be';
    const VIDEO = 'https://videos.comerciocity.store/landing-prueba/04-bienvenida.mp4';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        config([
            'services.admin_api.api_key' => self::CLAVE,
            'broadcasting.default'       => 'null',
        ]);

        // Nada de este test puede salir a la red (lecturas al admin del listado de Agentes).
        Http::fake();
    }

    private function headers()
    {
        return ['X-Admin-Api-Key' => self::CLAVE];
    }

    public function test_la_conversacion_nacida_en_el_admin_se_lista_en_el_sistema_con_su_mensaje_sin_leer()
    {
        $ticket_uuid = (string) Str::uuid();
        $mensaje_uuid = (string) Str::uuid();
        $texto = "¡Hola! Soy Martín, de Soporte de ComercioCity.\n\nMirá este video para arrancar: " . self::VIDEO;

        $this->postJson('/api/admin-sync/support/tickets', [
            'ticket_uuid'    => $ticket_uuid,
            'client_user_id' => $this->dueno->id,
            'name'           => 'Bienvenida a tu prueba',
            'status'         => 'open',
        ], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('model.uuid', $ticket_uuid)
            ->assertJsonPath('model.status', 'open');

        $this->postJson('/api/admin-sync/support/messages', [
            'ticket_uuid'  => $ticket_uuid,
            'message_uuid' => $mensaje_uuid,
            'kind'         => 'text',
            'body'         => $texto,
        ], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('model.sender_type', 'admin')
            ->assertJsonPath('model.body', $texto);

        $ticket = SupportTicket::where('uuid', $ticket_uuid)->first();
        $this->assertNotNull($ticket);
        $this->assertSame(SupportTicket::ORIGEN_SISTEMA, $ticket->origen, 'Nace como conversación del sistema, no de WhatsApp.');
        $this->assertSame((int) $this->dueno->id, (int) $ticket->user_id);

        // Lo que pide el SPA al abrir Soporte en Agentes.
        $modelos = $this->getJson('/api/support-ticket?agentes=1')->assertOk()->json('models');
        $listado = null;
        foreach ($modelos as $modelo) {
            if ($modelo['uuid'] === $ticket_uuid) {
                $listado = $modelo;
            }
        }

        $this->assertNotNull($listado, 'La conversación del admin tiene que estar en la lista de Soporte del dueño.');
        $this->assertSame('sistema', $listado['origen']);
        $this->assertSame('open', $listado['status']);
        $this->assertSame(1, (int) $listado['unread_messages_count']);
        $this->assertSame('Bienvenida a tu prueba', $listado['name']);

        // Al abrirla trae el mensaje de Soporte, con la URL del video intacta.
        $this->getJson('/api/support-ticket/' . $ticket->id . '?agentes=1')
            ->assertOk()
            ->assertJsonCount(1, 'model.messages')
            ->assertJsonPath('model.messages.0.sender_type', 'admin')
            ->assertJsonPath('model.messages.0.body', $texto);
    }

    public function test_repetir_el_ticket_y_el_mensaje_no_duplica_nada()
    {
        $ticket_uuid = (string) Str::uuid();
        $mensaje_uuid = (string) Str::uuid();
        $ticket = ['ticket_uuid' => $ticket_uuid, 'client_user_id' => $this->dueno->id, 'name' => 'Bienvenida a tu prueba', 'status' => 'open'];
        $mensaje = ['ticket_uuid' => $ticket_uuid, 'message_uuid' => $mensaje_uuid, 'kind' => 'text', 'body' => 'Bienvenida'];

        $this->postJson('/api/admin-sync/support/tickets', $ticket, $this->headers())->assertStatus(201);
        $this->postJson('/api/admin-sync/support/tickets', $ticket, $this->headers())->assertStatus(200);
        $this->postJson('/api/admin-sync/support/messages', $mensaje, $this->headers())->assertStatus(201);
        $this->postJson('/api/admin-sync/support/messages', $mensaje, $this->headers())->assertStatus(200);

        $this->assertSame(1, SupportTicket::where('uuid', $ticket_uuid)->count());
        $this->assertSame(1, SupportMessage::where('uuid', $mensaje_uuid)->count());
    }

    public function test_el_mensaje_sin_su_ticket_contesta_422_y_no_guarda_nada()
    {
        $mensaje_uuid = (string) Str::uuid();

        // Es lo que ve el admin si manda el mensaje antes que el ticket: tiene que crear el ticket primero.
        $this->postJson('/api/admin-sync/support/messages', [
            'ticket_uuid'  => (string) Str::uuid(),
            'message_uuid' => $mensaje_uuid,
            'kind'         => 'text',
            'body'         => 'Bienvenida',
        ], $this->headers())->assertStatus(422);

        $this->assertSame(0, SupportMessage::where('uuid', $mensaje_uuid)->count());
    }

    public function test_sin_la_clave_del_admin_no_se_crea_la_conversacion()
    {
        $ticket_uuid = (string) Str::uuid();

        $this->postJson('/api/admin-sync/support/tickets', [
            'ticket_uuid'    => $ticket_uuid,
            'client_user_id' => $this->dueno->id,
            'status'         => 'open',
        ])->assertStatus(401);

        $this->assertSame(0, SupportTicket::where('uuid', $ticket_uuid)->count());
    }
}
