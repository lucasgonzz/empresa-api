<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConversacionesAnterioresIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientaDeManualDePrueba;
use App\Services\DemoChatProxy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsistenteUnificadoDePruebaTest extends TestCase
{
    use DatabaseTransactions;

    const ADMIN = 'https://admin.test/api/prueba-chat';
    const PAGINA = 'manual_sistema/articulos.md';

    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::create([
            'name' => 'Dueño de la prueba',
            'email' => uniqid('asistente-unico-') . '@test.local',
            'password' => bcrypt('prueba-local'),
        ]);
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if ($extension === null) {
            $extension = new ExtencionEmpresa();
            $extension->name = 'Agentes de IA';
            $extension->slug = 'agentes_ia';
            $extension->save();
        }
        $this->dueno->extencions()->attach($extension->id);

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'cache.default' => 'array',
            'broadcasting.default' => 'null',
            'services.admin_api.url' => 'https://admin.test',
            'services.admin_api.inbound_key' => 'clave-interna-de-la-instancia',
            'services.deepseek.api_key' => 'clave-de-pruebas',
            'services.deepseek.base_url' => 'https://proveedor.test',
        ]);

        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        DemoTrackingConfigHelper::guardar('clave-eventos-de-prueba', 'https://admin.test/api/demo-eventos');

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/')
            ->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    public function test_el_acceso_unico_requiere_un_proveedor_y_el_contrato_del_admin()
    {
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', uniqid('ingreso-prueba-', true)),
            'user_id' => $this->dueno->id,
            'expires_at' => now()->addHour(),
        ]);
        $this->withSession(['demo_ingreso_token_id' => $token->id]);
        Http::fake([self::ADMIN . '/manual*' => Http::response(['disponible' => true])]);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.puede_unificar_chat_prueba', true);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.puede_unificar_chat_prueba', true);

        Http::assertSentCount(1);
        Http::assertSent(function ($pedido) {
            return $pedido->url() === self::ADMIN . '/manual?capacidad=1'
                && $pedido->hasHeader('X-Demo-Eventos-Key', 'clave-eventos-de-prueba')
                && $pedido->hasHeader('X-Admin-Api-Key', 'clave-interna-de-la-instancia');
        });

        config(['services.deepseek.api_key' => '']);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.puede_unificar_chat_prueba', false);
        $this->postJson('/api/prueba/chat/trasladar')->assertStatus(204);
        Http::assertSentCount(1);
    }

    public function test_un_admin_anterior_deja_disponible_el_chat_de_ayuda_legado()
    {
        Http::fake([
            self::ADMIN . '/manual*' => Http::response([], 404),
            self::ADMIN => Http::response($this->snapshot()),
        ]);

        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.puede_unificar_chat_prueba', false)
            ->assertJsonPath('user.puede_usar_chat_prueba', true);
        $this->getJson('/api/prueba/chat')->assertOk()->assertJsonPath('disponible', true);
    }

    public function test_reconcilia_respuestas_publicadas_tarde_por_id_y_respeta_el_borrado()
    {
        $snapshot = $this->snapshot();
        Http::fake([self::ADMIN => function () use (&$snapshot) {
            return Http::response($snapshot);
        }]);

        $id = $this->postJson('/api/prueba/chat/trasladar', [
            'user_id' => $this->dueno->id + 1,
            'mensajes' => [['contenido' => 'Inyección desde el navegador']],
        ])->assertOk()->assertJsonPath('pendiente', true)->json('conversacion_id');

        $this->assertIsInt($id);
        $conversacion = AiConversation::findOrFail($id);
        $this->assertSame($this->dueno->id, (int) $conversacion->auth_user_id);
        $this->assertSame(['user', 'assistant'], $conversacion->messages()->pluck('rol')->all());

        $snapshot['conversacion']['estado'] = 'inactivo';
        $snapshot['mensajes'][1]['estado'] = 'publicado';
        $turno = $conversacion->messages()->create(['rol' => 'assistant', 'estado' => 'pendiente']);
        $this->postJson('/api/prueba/chat/trasladar')
            ->assertOk()->assertJsonPath('conversacion_id', $id)->assertJsonPath('pendiente', true);
        $this->assertEquals([10, 12], $conversacion->messages()->whereNotNull('prueba_mensaje_id')->pluck('prueba_mensaje_id')->all());
        $turno->update(['estado' => 'listo']);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/prueba/chat/trasladar')
                ->assertOk()->assertJsonPath('conversacion_id', $id)->assertJsonPath('pendiente', false);
        }
        $this->assertEquals([10, 12, 11], $conversacion->messages()->whereNotNull('prueba_mensaje_id')->pluck('prueba_mensaje_id')->all());

        $this->deleteJson('/api/ai-conversations/' . $id)->assertOk();
        $this->postJson('/api/prueba/chat/trasladar')->assertOk()->assertJsonPath('conversacion_id', null);
        $this->assertFalse(AiConversation::where('user_id', $this->dueno->id)->exists());
        $this->assertDatabaseMissing('ai_messages', ['ai_conversation_id' => $id]);
    }

    public function test_si_el_admin_falla_se_puede_volver_a_intentar_sin_perder_el_hilo()
    {
        Http::fake([self::ADMIN => Http::sequence()
            ->push([], 503)
            ->push($this->snapshot())]);

        $this->postJson('/api/prueba/chat/trasladar')->assertOk()->assertExactJson(['disponible' => false]);
        $this->assertFalse(AiConversation::where('user_id', $this->dueno->id)->exists());
        $this->postJson('/api/prueba/chat/trasladar')->assertOk()->assertJsonPath('disponible', true);
        $this->assertTrue(AiConversation::where('user_id', $this->dueno->id)->exists());
    }

    public function test_el_turno_personal_bloquea_el_hilo_antes_de_guardar_el_pedido_y_su_respuesta()
    {
        Http::fake([self::ADMIN => Http::response($this->snapshot())]);
        $id = $this->postJson('/api/prueba/chat/trasladar')->assertOk()->json('conversacion_id');
        Bus::fake();

        $nivel_del_test = DB::transactionLevel();
        $escrituras = [];
        DB::listen(function ($consulta) use (&$escrituras) {
            if (stripos($consulta->sql, 'from `ai_conversations`') !== false && stripos($consulta->sql, 'for update') !== false) {
                $escrituras[] = ['lock del hilo', $consulta->connection->transactionLevel()];
            }
            if (stripos($consulta->sql, 'insert into `ai_messages`') !== false) {
                $escrituras[] = ['mensaje', $consulta->connection->transactionLevel()];
            }
        });

        $respuesta = $this->postJson('/api/ai-conversations/' . $id . '/messages', ['contenido' => '¿Y cómo lo cambio?'])
            ->assertCreated();
        $this->assertSame([
            ['lock del hilo', $nivel_del_test + 1],
            ['mensaje', $nivel_del_test + 1],
            ['mensaje', $nivel_del_test + 1],
        ], $escrituras);

        $respuesta->assertJsonPath('user_message.contenido', '¿Y cómo lo cambio?')
            ->assertJsonPath('assistant_message.estado', 'pendiente');
        Bus::assertDispatchedTimes(ResponderMensajeChatIaJob::class, 1);

        $this->postJson('/api/ai-conversations/' . $id . '/messages', ['contenido' => 'Otra consulta'])
            ->assertStatus(409)->assertJsonPath('code', 'respuesta_en_curso');
        $this->assertDatabaseMissing('ai_messages', ['ai_conversation_id' => $id, 'contenido' => 'Otra consulta']);
    }

    /**
     * @dataProvider accesos_que_no_son_el_dueno
     */
    public function test_el_acceso_delegado_no_traslada_ni_ve_el_historial($clave, $valor)
    {
        Http::fake([self::ADMIN => Http::response($this->snapshot())]);
        $id = $this->postJson('/api/prueba/chat/trasladar')->assertOk()->json('conversacion_id');
        $this->getJson('/api/ai-conversations/' . $id . '/messages')->assertOk();

        $this->withSession([$clave => $valor]);
        $this->postJson('/api/prueba/chat/trasladar')->assertStatus(204);
        $this->getJson('/api/ai-conversations')->assertOk()->assertExactJson(['models' => []]);
        $this->getJson('/api/ai-conversations/' . $id . '/messages')->assertNotFound();
        $this->postJson('/api/ai-conversations/' . $id . '/messages', ['contenido' => '¿Qué hablamos?'])->assertNotFound();
        Http::assertSentCount(1);
    }

    public function accesos_que_no_son_el_dueno(): array
    {
        return [
            'soporte' => ['acceso_soporte_token_id', 45],
        ];
    }

    public function test_un_empleado_u_otro_dueno_de_la_base_no_puede_trasladar_el_hilo()
    {
        Http::fake();
        foreach ([$this->dueno->id, null] as $owner_id) {
            $persona = User::create([
                'name' => 'Otra persona',
                'email' => uniqid('asistente-otro-') . '@test.local',
                'password' => bcrypt('prueba-local'),
                'owner_id' => $owner_id,
            ]);
            if ($owner_id === null) {
                $persona->extencions()->attach($this->dueno->extencions()->first()->id);
                PruebaHelper::activar_desde_payload($persona, ['dias' => 7]);
            }
            $this->actingAs($persona, 'web')->postJson('/api/prueba/chat/trasladar')->assertStatus(204);
        }
        Http::assertNothingSent();
    }

    public function test_el_hilo_se_puede_continuar_pero_otros_hilos_no_lo_leen_por_memoria()
    {
        Http::fake([self::ADMIN => Http::response($this->snapshot())]);
        $id = $this->postJson('/api/prueba/chat/trasladar')->assertOk()->json('conversacion_id');
        $otro = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        $this->assertArrayHasKey('error', ConversacionesAnterioresIaHelper::leer($otro, ['conversacion_id' => $id]));
        $this->assertSame([], ConversacionesAnterioresIaHelper::buscar($otro, [])['conversaciones']);
        $this->assertCount(2, ConversacionesAnterioresIaHelper::leer(AiConversation::findOrFail($id), [])['mensajes']);

        PruebaHelper::fijar_vencimiento($this->dueno->id, null);
        $this->getJson('/api/ai-conversations/' . $id . '/messages')->assertOk();
    }

    public function test_la_herramienta_solo_envia_rutas_del_indice_y_no_transmite_texto_libre()
    {
        Http::fake([
            self::ADMIN . '/manual?*' => Http::response(['disponible' => true, 'contenido' => 'En la ficha del artículo elegí la categoría.']),
            self::ADMIN . '/manual' => Http::response(['disponible' => true, 'indice' => '- ' . self::PAGINA]),
        ]);
        $herramienta = new HerramientaDeManualDePrueba();
        $proxy = app(DemoChatProxy::class);

        $this->assertTrue($herramienta->ejecutar(['ruta' => 'manual_sistema/deuda_de_cliente_privado.md'], $proxy)['is_error']);
        $this->assertFalse($herramienta->ejecutar(['ruta' => self::PAGINA], $proxy)['is_error']);
        Http::assertSentCount(2);
        Http::assertNotSent(function ($pedido) {
            return strpos($pedido->url(), 'cliente_privado') !== false;
        });
    }

    public function test_el_asistente_personal_usa_el_manual_en_el_mismo_turno_del_negocio()
    {
        Http::fake([
            self::ADMIN . '/manual?*' => Http::response(['disponible' => true, 'contenido' => 'En la ficha elegí la categoría.']),
            self::ADMIN . '/manual' => Http::response(['disponible' => true, 'indice' => '- ' . self::PAGINA]),
            'proveedor.test/*' => Http::sequence()
                ->push([
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'manual_1',
                        'name' => HerramientaDeManualDePrueba::NOMBRE,
                        'input' => ['ruta' => self::PAGINA],
                    ]],
                ])
                ->push([
                    'stop_reason' => 'end_turn',
                    'content' => [['type' => 'text', 'text' => 'Abrí la ficha del artículo y elegí la categoría.']],
                ]),
        ]);
        $conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $conversacion->messages()->create(['rol' => 'user', 'contenido' => '¿Cómo asigno una categoría?']);
        $pendiente = $conversacion->messages()->create(['rol' => 'assistant', 'estado' => 'pendiente', 'canal' => 'sistema']);

        $respuesta = (new AsistenteIaService())->responder($conversacion, $pendiente);

        $this->assertSame('Abrí la ficha del artículo y elegí la categoría.', $respuesta);
        Http::assertSent(function ($pedido) {
            if (strpos($pedido->url(), 'proveedor.test/') === false) {
                return false;
            }
            $herramientas = array_column($pedido['tools'], 'name');
            return in_array(HerramientaDeManualDePrueba::NOMBRE, $herramientas, true)
                && in_array('consultar_resumen_de_ventas', $herramientas, true);
        });
        $this->assertDatabaseMissing('ai_message_actions', ['ai_conversation_id' => $conversacion->id]);
    }

    private function snapshot(): array
    {
        return [
            'disponible' => true,
            'conversacion' => ['estado' => 'listo'],
            'mensajes' => [
                ['id' => 10, 'rol' => 'lead', 'contenido' => '¿Cómo asigno una categoría?', 'creado_at' => '2026-11-07T10:02:00-03:00'],
                ['id' => 11, 'rol' => 'asistente', 'estado' => 'borrador', 'contenido' => 'Abrí la ficha.', 'creado_at' => '2026-11-07T10:02:02-03:00'],
                ['id' => 12, 'rol' => 'asistente', 'contenido' => 'Fijate en Datos generales.', 'creado_at' => '2026-11-07T10:02:03-03:00'],
            ],
        ];
    }
}
