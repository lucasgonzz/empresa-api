<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\RegistrarHitoDePruebaJob;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * El vencimiento de la prueba gratuita (misión prueba-experiencia-empresa, 3/11/2026):
 *
 *  1. Con `users.expired_at` pasado, toda ruta con sesión contesta 403 `{error: 'prueba_vencida',
 *     whatsapp_soporte}` (middleware PruebaVencida del grupo api); admin-sync no se corta.
 *  2. El login con la prueba vencida contesta el mismo 403, no `login: false`.
 *  3. Con la prueba vigente el login funciona, el usuario trae `prueba` y se encola `prueba.ingreso`.
 *  4. C3: `POST admin-sync/prueba/vencimiento` y `GET admin-sync/prueba/estado`.
 *  5. Fuera de una instancia de prueba `expired_at` no corta nada (como siempre).
 */
class VencimientoDePruebaTest extends EmpresaTestCase
{
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-vencimiento-4b7d';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        PruebaHelper::olvidar_cache();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);
        config(['services.admin_api.api_key' => self::CLAVE_DE_ADMIN]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function prender_la_prueba($vence_at)
    {
        PruebaHelper::activar_desde_payload($this->dueno, [
            'dias'             => 7,
            'vence_at'         => $vence_at,
            'whatsapp_soporte' => '+5493444622139',
        ]);
        PruebaHelper::olvidar_cache();
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 1. El middleware
    |------------------------------------------------------------------------------------------------
    */

    public function test_con_la_prueba_vencida_toda_ruta_con_sesion_contesta_403_prueba_vencida()
    {
        $this->prender_la_prueba(Carbon::now()->subHour()->toIso8601String());

        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(403);
        $respuesta->assertJson([
            'error'            => 'prueba_vencida',
            'whatsapp_soporte' => '+5493444622139',
        ]);
        $this->assertSame(PruebaHelper::MENSAJE_VENCIDA, $respuesta->json('message'));
    }

    public function test_con_la_prueba_vigente_las_rutas_siguen_andando_y_el_usuario_trae_prueba()
    {
        $this->prender_la_prueba('2026-12-31T23:59:00-03:00');

        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(200);
        $prueba = $respuesta->json('user.prueba');
        $this->assertTrue($prueba['activa']);
        $this->assertFalse($prueba['vencida']);
        $this->assertSame('2026-12-31T23:59:00-03:00', $prueba['vence_at']);
        $this->assertSame('+5493444622139', $prueba['whatsapp_soporte']);
        $this->assertFalse($prueba['modal_visto']);
        $this->assertArrayHasKey('semilla_presente', $prueba);
    }

    public function test_sin_modo_prueba_el_usuario_trae_prueba_null()
    {
        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('user.prueba'));
    }

    public function test_el_canal_del_admin_no_se_corta_con_la_prueba_vencida()
    {
        $this->prender_la_prueba(Carbon::now()->subHour()->toIso8601String());

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)->getJson('/api/admin-sync/prueba/estado');

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('vencida'));
    }

    public function test_el_403_encola_el_hito_prueba_vencida()
    {
        $this->prender_la_prueba(Carbon::now()->subHour()->toIso8601String());
        Queue::fake();

        $this->getJson('/api/user')->assertStatus(403);

        Queue::assertPushed(RegistrarHitoDePruebaJob::class, function ($job) {
            return $job->tipo === 'prueba.vencida';
        });
    }

    public function test_fuera_de_una_instancia_de_prueba_expired_at_no_corta_nada()
    {
        $this->prender_la_prueba(Carbon::now()->subHour()->toIso8601String());
        config(['app.env' => 'production', 'app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();

        $this->getJson('/api/user')->assertStatus(200);
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 2. El login
    |------------------------------------------------------------------------------------------------
    */

    private function dueno_con_clave($expired_at)
    {
        $doc = 'prueba-' . substr(uniqid('', true), -10);

        $dueno = User::create([
            'name'         => 'Dueño de la prueba',
            'company_name' => 'Negocio en prueba',
            'doc_number'   => $doc,
            'email'        => 'prueba-' . uniqid() . '@test.local',
            'password'     => Hash::make('clave-larga-8'),
        ]);
        DB::table('users')->where('id', $dueno->id)->update(['expired_at' => $expired_at]);
        PruebaHelper::activar_desde_payload($dueno, ['whatsapp_soporte' => '+5493444622139']);
        DB::table('users')->where('id', $dueno->id)->update(['expired_at' => $expired_at]);
        PruebaHelper::olvidar_cache();

        return $dueno;
    }

    public function test_el_login_con_la_prueba_vencida_contesta_el_403_y_no_credenciales_incorrectas()
    {
        $dueno = $this->dueno_con_clave(Carbon::now()->subDay()->format('Y-m-d H:i:s'));
        auth()->logout();

        $respuesta = $this->postJson('/login', ['doc_number' => $dueno->doc_number, 'password' => 'clave-larga-8']);

        $respuesta->assertStatus(403);
        $respuesta->assertJson(['error' => 'prueba_vencida', 'whatsapp_soporte' => '+5493444622139']);
        $this->assertFalse(auth()->check(), 'La sesión que abrió Auth::attempt se cierra.');
    }

    public function test_el_login_con_la_prueba_vigente_entra_y_encola_el_ingreso()
    {
        $dueno = $this->dueno_con_clave(Carbon::now()->addDays(5)->format('Y-m-d H:i:s'));
        auth()->logout();
        Queue::fake();

        $respuesta = $this->postJson('/login', ['doc_number' => $dueno->doc_number, 'password' => 'clave-larga-8']);

        $respuesta->assertStatus(200);
        $this->assertTrue((bool) $respuesta->json('login'));
        $this->assertTrue((bool) $respuesta->json('user.prueba.activa'));

        Queue::assertPushed(RegistrarHitoDePruebaJob::class, function ($job) use ($dueno) {
            return $job->tipo === 'prueba.ingreso' && $job->owner_id === $dueno->id && $job->datos['via'] === 'login';
        });
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 3. C3
    |------------------------------------------------------------------------------------------------
    */

    public function test_c3_fija_el_vencimiento_y_lo_devuelve_en_iso()
    {
        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->postJson('/api/admin-sync/prueba/vencimiento', ['vence_at' => '2026-11-10T14:00:00-03:00']);

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['vence_at' => '2026-11-10T14:00:00-03:00']);
        $this->assertSame('2026-11-10 14:00:00', (string) DB::table('users')->where('id', $this->dueno->id)->value('expired_at'));
    }

    public function test_c3_con_null_saca_el_vencimiento()
    {
        $this->prender_la_prueba('2026-11-10T14:00:00-03:00');

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->postJson('/api/admin-sync/prueba/vencimiento', ['vence_at' => null]);

        $respuesta->assertStatus(200);
        $respuesta->assertExactJson(['vence_at' => null]);
        $this->assertNull(DB::table('users')->where('id', $this->dueno->id)->value('expired_at'));

        // La experiencia sigue: el modo prueba no se apaga con el vencimiento.
        PruebaHelper::olvidar_cache();
        $this->assertTrue(PruebaHelper::activa($this->dueno->id));
        $this->getJson('/api/user')->assertStatus(200);
    }

    public function test_c3_rechaza_un_vence_at_que_no_es_fecha()
    {
        $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->postJson('/api/admin-sync/prueba/vencimiento', ['vence_at' => 'mañana'])
            ->assertStatus(422);
    }

    public function test_c3_el_estado_tiene_la_forma_del_plan()
    {
        $this->prender_la_prueba('2026-11-10T14:00:00-03:00');

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)->getJson('/api/admin-sync/prueba/estado');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure(['vence_at', 'vencida', 'semilla_presente', 'hitos', 'activa']);
        $this->assertSame('2026-11-10T14:00:00-03:00', $respuesta->json('vence_at'));
        $this->assertFalse($respuesta->json('vencida'));
        $this->assertIsBool($respuesta->json('semilla_presente'));
        $this->assertSame(PruebaHelper::TIPOS_DE_HITO, array_keys($respuesta->json('hitos')));
        $this->assertSame(array_fill(0, 11, null), array_values($respuesta->json('hitos')));
    }

    public function test_c3_sin_clave_contesta_401()
    {
        config(['services.admin_api.require_api_key' => true]);

        $this->getJson('/api/admin-sync/prueba/estado')->assertStatus(401);
        $this->postJson('/api/admin-sync/prueba/vencimiento', ['vence_at' => null])->assertStatus(401);
    }

    /*
    |------------------------------------------------------------------------------------------------
    | 4. Las rutas del SPA
    |------------------------------------------------------------------------------------------------
    */

    public function test_modal_visto_se_marca_una_vez_y_el_estado_lo_refleja()
    {
        $this->prender_la_prueba('2026-12-31T23:59:00-03:00');

        $this->getJson('/api/prueba/estado')->assertStatus(200)->assertJsonPath('prueba.modal_visto', false);

        $respuesta = $this->postJson('/api/prueba/modal-visto');

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('prueba.modal_visto'));
        $this->assertNotNull(DB::table('prueba_config')->where('owner_id', $this->dueno->id)->value('modal_visto_at'));
    }

    public function test_las_rutas_del_spa_fuera_del_modo_prueba_contestan_null_y_no_escriben()
    {
        $this->getJson('/api/prueba/estado')->assertStatus(200)->assertExactJson(['prueba' => null]);
        $this->postJson('/api/prueba/modal-visto')->assertStatus(200)->assertExactJson(['prueba' => null]);
        $this->assertSame(0, DB::table('prueba_config')->where('owner_id', $this->dueno->id)->count());
    }
}
