<?php

namespace Tests\Feature\SupportTours;

use App\Events\SupportMessageReceived;
use App\Events\SupportTourOffered;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Models\DemoIngresoToken;
use App\Models\ExtencionEmpresa;
use App\Models\ImportHistory;
use App\Models\PermissionEmpresa;
use App\Models\Provider;
use App\Models\SupportMessage;
use App\Models\Surchage;
use App\Models\User;
use App\Models\VenderLayout;
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
            'services.admin_api.inbound_key' => self::CLAVE,
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
            'body' => 'Pregunta para Robotito sobre «Creá un ticket» en el paso 1: ¿Cómo creo un ticket?',
            'robotito_support_version' => 99,
            'robotito_followup' => true,
            'robotito_question' => '¿Cómo creo un ticket?',
        ])->assertCreated()->json('model');
        $this->assertArrayNotHasKey('robotito_support_version', $mensaje);
        Http::assertSent(function (Request $pedido) use ($mensaje) {
            return $pedido->url() === self::INBOUND && $pedido['robotito_support_version'] === 1
                && $pedido['message_uuid'] === $mensaje['uuid']
                && !isset($pedido['robotito_followup']) && !isset($pedido['robotito_question']);
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

    public function test_pendientes_prioriza_la_guia_recien_programada_y_conserva_la_anterior(): void
    {
        $vieja = $this->datos();
        $nueva = $this->datos(['tema' => 'guia_dinamica', 'titulo' => 'Aplicá un recargo']);
        $this->postJson(self::ADMIN_SYNC, $vieja, $this->clave())->assertOk();
        $this->postJson(self::ADMIN_SYNC, $nueva + [
            'pasos' => [['clave' => 'indicacion', 'texto' => 'Revisá la venta.']],
        ], $this->clave())->assertOk();

        $this->getJson('/api/support-tour/pending')->assertOk()
            ->assertJsonPath('model.0.uuid', $nueva['uuid'])
            ->assertJsonPath('model.1.uuid', $vieja['uuid']);
        $this->postJson($this->ruta($nueva, 'event'), ['event' => 'dismissed'])->assertOk();
        $this->getJson('/api/support-tour/pending')->assertOk()->assertJsonCount(1, 'model')
            ->assertJsonPath('model.0.uuid', $vieja['uuid']);
    }

    public function test_sesion_de_prueba_vigente_anuncia_robotito_y_marca_el_mensaje_firmado(): void
    {
        PruebaHelper::olvidar_cache();
        config(['app.FOR_USER' => 'prueba', 'services.admin_api.inbound_key' => self::CLAVE]);
        PruebaHelper::activar_desde_payload($this->dueno, ['vence_at' => now()->addDays(7)->toIso8601String()]);
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', (string) Str::uuid()), 'user_id' => $this->dueno->id,
            'expires_at' => now()->addHours(2),
        ]);
        $this->withSession(['demo_ingreso_token_id' => $token->id]);

        try {
            $this->getJson('/api/user')->assertOk()
                ->assertJsonPath('user.es_sesion_demo', false)
                ->assertJsonPath('user.robotito_soporte_habilitado', true);
            $this->getJson('/api/support-tour/pending')->assertExactJson(['model' => []]);
            Http::fake([self::INBOUND => Http::response(['ok' => true], 201)]);
            $this->postJson('/api/support-message', [
                'body' => '¿Cómo aplico un recargo?',
                'agentes_version' => 1,
            ])->assertCreated();
            Http::assertSent(static function (Request $pedido) {
                return $pedido->url() === self::INBOUND && $pedido['robotito_support_version'] === 1
                    && $pedido['agentes_version'] === 1;
            });

            config(['app.FOR_USER' => 'demo']);
            $this->getJson('/api/user')->assertOk()
                ->assertJsonPath('user.es_sesion_demo', true)
                ->assertJsonPath('user.robotito_soporte_habilitado', false);
            $this->getJson('/api/support-tour/pending')->assertForbidden();
        } finally {
            PruebaHelper::olvidar_cache();
        }
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

    public function test_revertir_importacion_fija_un_historial_del_comercio_sin_ejecutar_la_reversion(): void
    {
        $importacion = $this->historial();
        $otra_importacion = $this->historial();
        $ajena = $this->historial(['user_id' => $this->persona()->id]);
        $en_curso = $this->historial(['status' => 'en_proceso']);
        $de_clientes = $this->historial(['model_name' => 'client']);
        $oferta = $this->datos(['tema' => 'revertir_importacion_articulos', 'titulo' => 'Revertí la importación']);

        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertJsonValidationErrors('import_history_id');
        foreach ([$ajena, $en_curso, $de_clientes] as $no_disponible) {
            $this->postJson(self::ADMIN_SYNC, $oferta + ['import_history_id' => $no_disponible->id], $this->clave())
                ->assertStatus(409);
        }
        $this->postJson(self::ADMIN_SYNC, $this->datos(['import_history_id' => $importacion->id]), $this->clave())
            ->assertJsonValidationErrors('import_history_id');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['import_history_id' => $importacion->id, 'user_id' => $this->empleado->id,
            'recipient_phone' => '5493454622139'], $this->clave())->assertStatus(409);

        $this->postJson(self::ADMIN_SYNC, $oferta + ['import_history_id' => $importacion->id], $this->clave())->assertOk();
        $this->postJson(self::ADMIN_SYNC, $oferta + ['import_history_id' => $otra_importacion->id], $this->clave())
            ->assertStatus(409);
        $this->postJson(self::ADMIN_SYNC, $oferta + ['import_history_id' => $importacion->id], $this->clave())->assertOk();
        $this->getJson('/api/support-tour/pending')->assertJsonPath('model.0.import_history_id', $importacion->id);
        $this->postJson($this->ruta($oferta, 'accept'))->assertJsonPath('model.import_history_id', $importacion->id);
        $this->assertNull($importacion->fresh()->rollback_status);
        Event::assertDispatchedTimes(SupportTourOffered::class, 1);
    }

    public function test_candidatas_requiere_clave_destinatario_y_devuelve_solo_datos_utiles_del_comercio(): void
    {
        $proveedor = Provider::create(['user_id' => $this->dueno->id, 'name' => 'Proveedor del comercio']);
        $importacion = $this->historial(['provider_id' => $proveedor->id]);
        $this->historial(['rollback_status' => 'encolado']);
        $ajena = $this->historial(['user_id' => $this->persona()->id]);
        $ruta = self::ADMIN_SYNC . '/importaciones/candidatas?user_id=';
        $this->getJson($ruta . $this->dueno->id)->assertUnauthorized();
        $this->getJson($ruta . $this->empleado->id, $this->clave())->assertStatus(409);
        $this->getJson($ruta . $ajena->user_id, $this->clave())->assertStatus(409);
        $this->getJson($ruta . $this->dueno->id . '&import_history_id=' . $ajena->id, $this->clave())
            ->assertExactJson(['model' => []]);
        $this->getJson($ruta . $this->dueno->id . '&import_history_id=' . $importacion->id, $this->clave())
            ->assertExactJson(['model' => [[
                'id' => $importacion->id,
                'created_at' => $importacion->created_at->toDateTimeString(),
                'provider_id' => $proveedor->id,
                'provider_name' => 'Proveedor del comercio',
            ]]]);
    }

    public function test_disponibilidad_de_recargos_valida_comercio_permisos_catalogo_y_diseno(): void
    {
        $dueno = $this->persona();
        $empleado = $this->persona(['owner_id' => $dueno->id]);
        config(['app.USER_ID' => $dueno->id]);
        $ruta = self::ADMIN_SYNC . '/catalogo';
        $this->getJson($ruta)->assertUnauthorized();
        $claves = function (string $consulta): array {
            return $this->getJson($consulta, $this->clave())->assertOk()
                ->assertJsonPath('available', true)->json('pasos.*.clave');
        };
        $this->assertNotContains('vender.recargos', $claves($ruta));

        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'comerciocity_interno'], ['name' => 'Vender']);
        $dueno->extencions()->attach($extension->id);
        Surchage::create(['user_id' => $this->dueno->id, 'name' => 'De otro comercio', 'percentage' => 9]);
        $this->assertNotContains('vender.recargos', $claves($ruta));

        $recargo = Surchage::create(['user_id' => $dueno->id, 'name' => 'De este comercio', 'percentage' => 7]);
        $this->assertContains('vender.recargos', $claves($ruta));
        $this->getJson($ruta . '?user_id=' . $this->dueno->id, $this->clave())
            ->assertExactJson(['available' => false, 'pasos' => []]);
        $ruta_empleado = $ruta . '?user_id=' . $empleado->id;
        $this->assertNotContains('vender.total', $claves($ruta_empleado));
        foreach (['sale.store', 'sale.discount_surchage.aplicar'] as $slug) {
            $permiso = PermissionEmpresa::unguarded(function () use ($slug) {
                return PermissionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            });
            $empleado->permissions()->attach($permiso->id);
        }
        $this->assertContains('vender.recargos', $claves($ruta_empleado));

        $diseno = VenderLayout::create([
            'user_id' => $dueno->id, 'name' => 'Sin recargos', 'en_uso' => true,
            'layout' => ['etapas' => ['etapa_3' => []], 'sacados' => ['recargos']],
        ]);
        $this->assertNotContains('vender.recargos', $claves($ruta_empleado));
        $diseno->update(['layout' => ['etapas' => ['etapa_1' => [['key' => 'recargos', 'cols' => 6]]], 'sacados' => []]]);
        $this->assertContains('vender.recargos', $claves($ruta_empleado));
        $recargo->delete();
        $this->assertNotContains('vender.recargos', $claves($ruta_empleado));
    }

    public function test_tour_recargos_vuelve_a_validar_disponibilidad_y_entrega_dos_pasos_privados(): void
    {
        $dueno = $this->persona();
        config(['app.USER_ID' => $dueno->id]);
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'comerciocity_interno'], ['name' => 'Vender']);
        $dueno->extencions()->attach($extension->id);
        $oferta = $this->datos(['tema' => 'guia_dinamica', 'titulo' => 'Aplicá un recargo']);
        $pasos = [
            ['clave' => 'vender.recargos', 'texto' => 'Activá el recargo que quieras.'],
            ['clave' => 'vender.total', 'texto' => 'Revisá el total.'],
        ];
        $this->postJson(self::ADMIN_SYNC, $oferta, $this->clave())->assertJsonValidationErrors('pasos');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => $pasos], $this->clave())->assertJsonValidationErrors('pasos.0');
        Surchage::create(['user_id' => $dueno->id, 'name' => 'Recargo de prueba', 'percentage' => 7]);
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => $pasos], $this->clave())->assertOk();
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => $pasos], $this->clave())->assertOk();
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => array_reverse($pasos)], $this->clave())->assertStatus(409);
        $this->getJson('/api/support-tour/pending')->assertExactJson(['model' => []]);

        Auth::forgetGuards();
        $this->actingAs($dueno, 'web')->getJson('/api/support-tour/pending')
            ->assertJsonPath('model.0.tema', 'guia_dinamica')->assertJsonPath('model.0.pasos', $pasos);
        $this->seguimiento($oferta)->assertJsonPath('model.0.total_steps', 2);
        Event::assertDispatchedTimes(SupportTourOffered::class, 1);
        $dueno->extencions()->detach($extension->id);
        $this->postJson($this->ruta($oferta, 'accept'))->assertJsonValidationErrors('pasos.0');
        $this->seguimiento($oferta)->assertJsonPath('model.0.state', 'pending');
    }

    public function test_guia_dinamica_rechaza_selectores_arbitrarios_y_destinos_ajenos(): void
    {
        $oferta = $this->datos(['tema' => 'guia_dinamica', 'titulo' => 'Historial']);
        $historial = $this->historial();
        $ajeno = $this->historial(['user_id' => $this->persona()->id]);
        $paso = ['clave' => 'listado.revertir_importacion', 'texto' => 'Revisá esta importación.'];

        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => [$paso]], $this->clave())
            ->assertJsonValidationErrors('pasos.0');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => [$paso + ['destino' => $ajeno->id]]], $this->clave())
            ->assertJsonValidationErrors('pasos.0.destino');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => [$paso + ['selector' => 'body']]], $this->clave())
            ->assertJsonValidationErrors('pasos.0');
        $ruta = self::ADMIN_SYNC . '/catalogo';
        $this->assertArrayNotHasKey('destinos', $this->getJson($ruta, $this->clave())->assertOk()->json());
        $this->getJson($ruta . '?import_history_id=' . $historial->id, $this->clave())
            ->assertJsonPath('destinos', ['listado.revertir_importacion' => [[
                'id' => $historial->id, 'created_at' => $historial->created_at->toDateTimeString(),
                'provider_id' => null, 'provider_name' => null,
            ]]]);
        $pasos = [
            ['clave' => 'vista.listado', 'texto' => 'Abrí el historial.'],
            $paso + ['destino' => $historial->id],
            ['clave' => 'indicacion', 'texto' => 'Verificá los datos antes de confirmar.'],
        ];
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => $pasos], $this->clave())->assertOk();
        $this->postJson($this->ruta($oferta, 'accept'))->assertJsonPath('model.pasos', $pasos);
        $historial->update(['rollback_status' => 'revertida', 'rolled_back_at' => now()]);
        $this->postJson($this->ruta($oferta, 'accept'))->assertJsonValidationErrors('pasos.1.destino');
    }

    public function test_catalogo_separa_exportacion_de_importacion_y_exige_permisos_del_menu(): void
    {
        $ruta = self::ADMIN_SYNC . '/catalogo?user_id=' . $this->empleado->id;
        $claves = function () use ($ruta): array {
            return $this->getJson($ruta, $this->clave())->assertOk()->json('pasos.*.clave');
        };
        $permisos = ['article.index', 'article.export_excel_clients', 'article.excel.export'];
        foreach ($permisos as $slug) {
            $permiso = PermissionEmpresa::unguarded(static function () use ($slug) {
                return PermissionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            });
            $this->assertNotContains('listado.opcion_nueva_exportacion', $claves());
            $this->empleado->permissions()->attach($permiso->id);
        }
        $disponibles = $claves();
        foreach (['listado.submenu_exportacion', 'listado.opcion_nueva_exportacion', 'listado.opcion_historial_exportaciones', 'listado.historial_importaciones'] as $clave) {
            $this->assertContains($clave, $disponibles);
        }
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

    public function test_catalogo_de_aperturas_y_pago_repartido_respeta_permisos_y_diseno(): void
    {
        $extension = ExtencionEmpresa::firstOrCreate(['slug' => 'comerciocity_interno'], ['name' => 'Vender']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $ruta = self::ADMIN_SYNC . '/catalogo?user_id=' . $this->empleado->id;
        $claves = function () use ($ruta): array {
            return $this->getJson($ruta, $this->clave())->assertOk()->json('pasos.*.clave');
        };
        $pasos = ['caja.historial_aperturas', 'caja.reabrir_apertura', 'vender.metodos_pago', 'vender.reparto_pago'];
        $this->assertEmpty(array_intersect($pasos, $claves()));

        foreach (['caja.index', 'sale.store'] as $slug) {
            $permiso = PermissionEmpresa::unguarded(function () use ($slug) {
                return PermissionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            });
            $this->empleado->permissions()->attach($permiso->id);
        }
        $this->assertSame($pasos, array_values(array_intersect($pasos, $claves())));

        VenderLayout::create([
            'user_id' => $this->dueno->id, 'name' => 'Sin métodos de pago', 'en_uso' => true,
            'layout' => ['etapas' => ['etapa_3' => []], 'sacados' => ['metodo_de_pago']],
        ]);
        $catalogo = $claves();
        $this->assertNotContains('vender.metodos_pago', $catalogo);
        $this->assertNotContains('vender.reparto_pago', $catalogo);
        $this->assertContains('caja.reabrir_apertura', $catalogo);
    }

    public function test_el_catalogo_ofrece_editar_porcentajes_segun_el_acceso_a_abm(): void
    {
        $ruta = self::ADMIN_SYNC . '/catalogo?user_id=' . $this->empleado->id;
        $pasos = ['abm.editar_recargo', 'abm.porcentaje_recargo', 'abm.editar_descuento', 'abm.porcentaje_descuento'];
        $claves = function () use ($ruta): array {
            return $this->getJson($ruta, $this->clave())->assertOk()->json('pasos.*.clave');
        };

        $this->assertEmpty(array_intersect($pasos, $claves()));
        $permiso = PermissionEmpresa::unguarded(function () {
            return PermissionEmpresa::firstOrCreate(['slug' => 'abm'], ['name' => 'ABM']);
        });
        $this->empleado->permissions()->attach($permiso->id);
        $this->assertSame($pasos, array_values(array_intersect($pasos, $claves())));
        $this->assertNotContains('vender.recargos', $claves());
    }

    public function test_el_catalogo_ofrece_las_solapas_del_abm_con_ruta_y_ancla_y_acepta_un_recorrido_con_ellas(): void
    {
        $ruta = self::ADMIN_SYNC . '/catalogo?user_id=' . $this->empleado->id;
        $catalogo = function () use ($ruta): array {
            return collect($this->getJson($ruta, $this->clave())->assertOk()->json('pasos'))->pluck('descripcion', 'clave')->all();
        };
        $grupo = 'abm.grupo.precios';
        $solapa = 'abm.solapa.precios.impuestos_sobre_ventas';
        $larga = 'abm.solapa.facturacion.metodo_de_pago_seleccionado_para_facturacion';

        $this->assertArrayNotHasKey($grupo, $catalogo(), 'Sin acceso al ABM no se ofrecen sus solapas.');
        $permiso = PermissionEmpresa::unguarded(function () {
            return PermissionEmpresa::firstOrCreate(['slug' => 'abm'], ['name' => 'ABM']);
        });
        $this->empleado->permissions()->attach($permiso->id);

        $pasos = $catalogo();
        $this->assertSame('Abrir el ABM y señalar el grupo «Precios» para que la persona lo toque.', $pasos[$grupo]);
        $this->assertStringContainsString('Abrir ABM → Precios y señalar la solapa «Impuestos sobre ventas»', $pasos[$solapa]);
        $this->assertArrayHasKey($larga, $pasos);
        $this->assertStringContainsString('acoplada', $pasos['indicacion']);
        // Lo que depende de una extensión del comercio solo se ofrece si la tiene.
        $this->assertArrayNotHasKey('abm.grupo.vinoteca', $pasos);
        $this->assertArrayNotHasKey('abm.solapa.integraciones.whatsapp_bot', $pasos);
        $this->dueno->extencions()->attach(ExtencionEmpresa::firstOrCreate(['slug' => 'vinoteca'], ['name' => 'Vinoteca'])->id);
        $this->assertArrayHasKey('abm.solapa.vinoteca.cepas', $catalogo());

        foreach (array_keys($pasos) as $clave) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_.]{1,80}$/D', $clave, 'El Admin rechaza una clave con otro formato.');
        }

        // Por WhatsApp y sin user_id el recorrido es para el dueño, que tiene todo el ABM.
        $oferta = $this->datos(['tema' => 'guia_dinamica', 'titulo' => 'Desactivar Ingresos Brutos']);
        $recorrido = [
            ['clave' => $grupo, 'texto' => 'Dentro de ABM, tocá Precios.'],
            ['clave' => $solapa, 'texto' => 'Ahora tocá Impuestos sobre ventas.'],
            ['clave' => 'indicacion', 'texto' => 'Abrí Ingresos Brutos y destildá Activo.'],
            ['clave' => $larga, 'texto' => 'Esta solapa tiene la clave más larga del catálogo.'],
        ];
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => [['clave' => 'abm.solapa.precios.no_existe', 'texto' => 'x']]], $this->clave())
            ->assertJsonValidationErrors('pasos.0');
        $this->postJson(self::ADMIN_SYNC, $oferta + ['pasos' => $recorrido], $this->clave())->assertOk();
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
        $this->postJson($this->ruta($oferta, 'question'), $pregunta)->assertStatus(502)
            ->assertJsonPath('code', 'question_saved');
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
            $this->assertTrue($pedido['robotito_followup']);
            $this->assertSame($pregunta['question'], $pedido['robotito_question']);
        }

        $reformulada = $this->datos(['source' => 'sistema', 'uuid' => $mensaje->uuid, 'ticket_uuid' => $mensaje->ticket->uuid]);
        $this->postJson(self::ADMIN_SYNC, $reformulada, $this->clave())->assertOk();
        $this->seguimiento($oferta)->assertJsonCount(2, 'model')
            ->assertJsonPath('model.0.state', 'reformulated')->assertJsonPath('model.0.question_ticket_uuid', $mensaje->ticket->uuid)
            ->assertJsonPath('model.1.previous_uuid', $oferta['uuid'])->assertJsonPath('model.1.origin_ticket_uuid', $oferta['ticket_uuid']);
        $this->seguimiento($reformulada)->assertJsonCount(2, 'model');
        $this->getJson('/api/support-tour/pending')->assertJsonCount(1, 'model')->assertJsonPath('model.0.uuid', $mensaje->uuid)
            ->assertJsonPath('model.0.previous_uuid', $oferta['uuid']);
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

    private function historial(array $cambios = []): ImportHistory
    {
        return ImportHistory::create($cambios + [
            'user_id' => $this->dueno->id,
            'model_name' => 'article',
            'status' => 'terminado',
        ]);
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
