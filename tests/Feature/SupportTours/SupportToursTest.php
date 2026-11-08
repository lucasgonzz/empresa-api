<?php

namespace Tests\Feature\SupportTours;

use App\Events\SupportMessageReceived;
use App\Events\SupportTourOffered;
use App\Models\ExtencionEmpresa;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

class SupportToursTest extends EmpresaTestCase
{
    private const CLAVE = 'clave-local-tours-soporte';
    private const ADMIN_SYNC = '/api/admin-sync/support/tours';
    private const INBOUND = 'https://admin.test/api/inbound/support/messages';

    private $dueno;
    private $empleado;
    private $extension;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = auth()->user();
        $this->empleado = $this->persona(['owner_id' => $this->dueno->id, 'phone' => '0345 15 462-2139']);
        $this->extension = ExtencionEmpresa::firstOrCreate(['slug' => 'support_chat'], ['name' => 'Chat de soporte']);
        $this->dueno->extencions()->syncWithoutDetaching([$this->extension->id]);
        config([
            'app.USER_ID' => $this->dueno->id,
            'services.admin_api.api_key' => self::CLAVE,
            'services.admin_api.require_api_key' => false,
            'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.client_uuid' => (string) Str::uuid(),
            'broadcasting.default' => 'null',
        ]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
        Event::fake([SupportTourOffered::class, SupportMessageReceived::class]);
    }

    public function test_el_chat_web_ofrece_solo_al_autor_por_canal_privado_y_no_duplica(): void
    {
        Http::fake([self::INBOUND => Http::response(['ok' => true], 201)]);
        $this->actingAs($this->empleado, 'web');
        $mensaje = $this->postJson('/api/support-message', [
            'body' => '¿Cómo creo un ticket?',
            'robotito_support_version' => 99,
        ])->assertCreated()->json('model');
        $this->assertArrayNotHasKey('robotito_support_version', $mensaje);
        Http::assertSent(function (Request $pedido) use ($mensaje) {
            return $pedido->url() === self::INBOUND && $pedido['robotito_support_version'] === 1
                && $pedido['message_uuid'] === $mensaje['uuid'];
        });

        $oferta = $this->datos(['source' => 'sistema', 'uuid' => $mensaje['uuid'], 'ticket_uuid' => $mensaje['ticket']['uuid']]);
        $this->postJson(self::ADMIN_SYNC, $oferta)->assertUnauthorized();
        $this->postJson(self::ADMIN_SYNC, $oferta + ['user_id' => $this->dueno->id], $this->clave())->assertStatus(409);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertExactJson(['ok' => true, 'uuid' => $oferta['uuid']]);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertOk();
        Event::assertDispatchedTimes(SupportTourOffered::class, 1);
        Event::assertDispatched(SupportTourOffered::class, function (SupportTourOffered $evento) {
            $canal = $evento->broadcastOn();

            return $canal instanceof PrivateChannel && $canal->name === 'private-support-tour.user.' . $this->empleado->id
                && $evento->broadcastAs() === 'SupportTourOffered' && $evento->broadcastWith() === [];
        });
        $this->getJson('/api/support-tour/pending')->assertExactJson(['model' => [[
            'uuid' => $oferta['uuid'], 'tema' => 'crear_ticket', 'titulo' => 'Creá un ticket', 'textos' => [],
        ]]]);

        // El contenedor de PHPUnit reutiliza el guard de Sanctum entre requests.
        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
        $this->getJson('/api/support-tour/pending')->assertOk()->assertExactJson(['model' => []]);
        $this->postJson($this->ruta($oferta, 'accept'))->assertNotFound();
        $this->getJson(self::ADMIN_SYNC . '/' . $oferta['ticket_uuid'], $this->clave())->assertJsonCount(1, 'model');

        config(['app.USER_ID' => $this->persona()->id]);
        $this->getJson(self::ADMIN_SYNC . '/' . $oferta['ticket_uuid'], $this->clave())->assertExactJson(['model' => []]);
    }

    public function test_una_sesion_de_soporte_mantiene_el_chat_habitual_sin_leer_ni_crear_ofertas(): void
    {
        Http::fake([self::INBOUND => Http::response(['ok' => true], 201)]);
        $this->withSession(['acceso_soporte_token_id' => 5]);
        $this->getJson('/api/support-tour/pending')->assertForbidden();
        $mensaje = $this->postJson('/api/support-message', [
            'body' => 'Consulta desde soporte firmado',
            'robotito_support_version' => 1,
        ])->assertCreated()->json('model');
        Http::assertSent(function (Request $pedido) {
            return !isset($pedido['robotito_support_version']);
        });

        $oferta = $this->datos(['source' => 'sistema', 'uuid' => $mensaje['uuid'], 'ticket_uuid' => $mensaje['ticket']['uuid']]);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertStatus(409);
        Event::assertNotDispatched(SupportTourOffered::class);
    }

    public function test_whatsapp_valida_el_numero_vigente_y_unico_del_empleado(): void
    {
        $oferta = $this->datos(['user_id' => $this->empleado->id]);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertJsonValidationErrors('recipient_phone');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['recipient_phone' => '551234622139'], $this->clave())->assertStatus(409);

        $otra_persona = $this->persona(['owner_id' => $this->dueno->id, 'phone' => '+54 9 345 4622139']);
        $oferta += ['recipient_phone' => '5493454622139'];
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertStatus(409);
        $otra_persona->update(['phone' => '']);

        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertOk();
        $this->actingAs($this->empleado, 'web')->getJson('/api/support-tour/pending')->assertJsonPath('model.0.uuid', $oferta['uuid']);
    }

    public function test_la_guia_de_tickets_necesita_el_chat_y_un_guion_del_catalogo(): void
    {
        $oferta = $this->datos();
        $this->postJson(self::ADMIN_SYNC, array_replace($oferta, ['tema' => 'ejecutar_codigo']), $this->clave())
            ->assertJsonValidationErrors('tema');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['textos' => ['Falta el resto']], $this->clave())
            ->assertJsonValidationErrors('textos');

        $this->dueno->extencions()->detach($this->extension->id);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertStatus(409);
        Event::assertNotDispatched(SupportTourOffered::class);
    }

    public function test_el_final_exige_todos_los_pasos_y_la_valoracion_distingue_la_polaridad(): void
    {
        $oferta = $this->datos(['textos' => ['Abrí Soporte', 'Creá un ticket', 'Escribí y enviá']]);
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'shown'])->assertOk();
        $this->postJson($this->ruta($oferta, 'accept'))->assertJsonPath('model.textos', $oferta['textos']);
        $this->postJson($this->ruta($oferta, 'accept'))->assertOk();
        $this->postJson($this->ruta($oferta, 'feedback'), ['liked' => false])->assertStatus(409);
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 1])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 1])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 4])->assertStatus(422);
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'completed'])->assertStatus(409);
        $this->seguimiento($oferta)->assertJsonPath('model.0.state', 'accepted')
            ->assertJsonPath('model.0.steps_shown', [1])->assertJsonPath('model.0.feedback', null);

        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 2])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 3])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'completed'])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'completed'])->assertOk();
        $this->postJson($this->ruta($oferta, 'feedback'), ['liked' => false, 'reasons' => ['claro']])
            ->assertJsonValidationErrors('reasons.0');
        $this->postJson($this->ruta($oferta, 'feedback'), ['liked' => true, 'reasons' => ['claro', 'ritmo']])->assertOk();
        $this->seguimiento($oferta)->assertJsonPath('model.0.feedback.liked', true);
        $this->postJson($this->ruta($oferta, 'feedback'), [
            'liked' => false, 'reasons' => ['rapido', 'confuso'], 'comment' => 'El último paso me costó.',
        ])->assertOk();
        $this->seguimiento($oferta)->assertJsonPath('model.0.state', 'completed')
            ->assertJsonPath('model.0.steps_shown', [1, 2, 3])->assertJsonPath('model.0.feedback.liked', false)
            ->assertJsonPath('model.0.feedback.reasons', ['rapido', 'confuso'])
            ->assertJsonPath('model.0.feedback.comment', 'El último paso me costó.');
    }

    public function test_abandonar_y_descartar_son_distintos_y_el_reintento_no_reabre_la_oferta(): void
    {
        $oferta = $this->datos();
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'shown'])->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'dismissed'])->assertOk();
        $this->seguimiento($oferta)->assertJsonPath('model.0.state', 'dismissed')->assertJsonPath('model.0.accepted_at', null);

        $otra = $this->datos();
        $this->postJson(self::ADMIN_SYNC, $otra, $this->clave())->assertOk();
        $this->postJson($this->ruta($otra, 'accept'))->assertOk();
        $this->postJson($this->ruta($otra, 'event'), ['event' => 'step', 'step' => 1])->assertOk();
        $this->postJson($this->ruta($otra, 'event'), ['event' => 'abandoned'])->assertOk();
        $this->postJson(self::ADMIN_SYNC, $otra, $this->clave())->assertOk();
        $this->getJson('/api/support-tour/pending')->assertExactJson(['model' => []]);
        $this->seguimiento($otra)->assertJsonPath('model.0.state', 'abandoned')->assertJsonPath('model.0.steps_shown', [1]);
        Event::assertDispatchedTimes(SupportTourOffered::class, 2);
    }

    public function test_la_repregunta_se_reintenta_sin_duplicar_y_la_nueva_guia_conserva_el_origen(): void
    {
        $status = 503;
        Http::fake([self::INBOUND => function () use (&$status) {
            return Http::response(['ok' => $status === 201], $status);
        }]);
        $oferta = $this->datos();
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertOk();
        $this->postJson($this->ruta($oferta, 'accept'))->assertOk();
        $this->postJson($this->ruta($oferta, 'event'), ['event' => 'step', 'step' => 1])->assertOk();

        $pregunta = ['question' => '¿Dónde está ese botón?', 'step' => 1];
        $this->postJson($this->ruta($oferta, 'question'), $pregunta)->assertStatus(502);
        $this->seguimiento($oferta)->assertJsonPath('model.0.question', $pregunta['question'])
            ->assertJsonPath('model.0.question_step', 1)->assertJsonPath('model.0.question_delivered_at', null);
        $status = 201;
        $this->postJson($this->ruta($oferta, 'question'), $pregunta)->assertExactJson(['ok' => true]);
        $this->postJson($this->ruta($oferta, 'question'), $pregunta)->assertOk();
        $this->postJson($this->ruta($oferta, 'question'), ['question' => 'Otro texto'])->assertStatus(409);
        Http::assertSentCount(2);

        $mensaje = SupportMessage::where('body', 'like', '%¿Dónde está ese botón?')->firstOrFail();
        $this->assertNotNull($mensaje->synced_to_admin_at);
        $this->assertSame('Pregunta para Robotito sobre «Creá un ticket» en el paso 1: ¿Dónde está ese botón?', $mensaje->body);
        $this->assertSame(1, SupportMessage::where('body', $mensaje->body)->count());
        foreach (Http::recorded() as [$pedido]) {
            $this->assertSame($mensaje->uuid, $pedido['message_uuid']);
            $this->assertSame(1, $pedido['robotito_support_version']);
        }

        $reformulada = $this->datos(['source' => 'sistema', 'uuid' => $mensaje->uuid, 'ticket_uuid' => $mensaje->ticket->uuid]);
        $this->postJson(self::ADMIN_SYNC, $reformulada, $this->clave())->assertOk();
        $this->seguimiento($oferta)->assertJsonCount(2, 'model')
            ->assertJsonPath('model.0.state', 'reformulated')->assertJsonPath('model.0.question_ticket_uuid', $mensaje->ticket->uuid)
            ->assertJsonPath('model.1.previous_uuid', $oferta['uuid'])->assertJsonPath('model.1.origin_ticket_uuid', $oferta['ticket_uuid']);
        $this->seguimiento($reformulada)->assertJsonCount(2, 'model');
        $this->getJson('/api/support-tour/pending')->assertJsonCount(1, 'model')->assertJsonPath('model.0.uuid', $mensaje->uuid);
    }

    private function persona(array $datos = []): User
    {
        return User::create($datos + [
            'name' => 'Persona de prueba',
            'email' => Str::uuid() . '@test.local',
            'password' => 'no-se-usa-en-estas-pruebas',
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'uuid' => (string) Str::uuid(),
            'ticket_uuid' => (string) Str::uuid(),
            'source' => 'whatsapp',
            'tema' => 'crear_ticket',
            'titulo' => 'Creá un ticket',
        ];
    }

    private function clave(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE];
    }

    private function ruta(array $oferta, string $accion): string
    {
        return '/api/support-tour/' . $oferta['uuid'] . '/' . $accion;
    }

    private function seguimiento(array $oferta)
    {
        return $this->getJson(self::ADMIN_SYNC . '/' . $oferta['ticket_uuid'], $this->clave())->assertOk();
    }
}
