<?php

namespace Tests\Feature\ElLocal;

use App\Http\Controllers\Helpers\VersionSessionTransferHelper;
use App\Models\DemoIngresoToken;
use App\Models\LocalActividadEvento;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ElLocal\Concerns\ConfiguraElLocal;
use Tests\TestCase;

/**
 * `login`, `actividad` y `logout` de «El local» (misión el-local-fase-2, 3/11/2026), por los endpoints reales.
 *
 * DatabaseTransactions: la base de testing del slot ya está sembrada, con el usuario 500 como dueño.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class LocalActividadDeSesionTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguraElLocal;

    const CLAVE = 'clave-de-salida-del-test-del-local';
    const DUENO = 500;
    const PASSWORD_DE_PRUEBA = 'zz-password-testing';

    /** @var User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurar_el_local();

        $this->user = User::find(self::DUENO);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        /** Sin este header no hay sesion en el request (ver BusDeEventosTest). */
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');

        $this->user->password = bcrypt(self::PASSWORD_DE_PRUEBA);
        /*
         * Candado siempre vencido: cada request del test abre una sesión nueva, y con el candado vigente el segundo
         * se rechazaría con 403. Los dos tests del candado lo prenden ellos.
         */
        $this->user->activity_minutes = 0;
        $this->user->session_id = null;
        $this->user->last_activity = null;
        $this->user->save();

        /* Nada de esto puede salir a la red en el request del usuario. */
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 1], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return \Illuminate\Support\Collection */
    private function eventos($tipo)
    {
        return LocalActividadEvento::where('tipo', $tipo)->get();
    }

    private function entrar($doc_number = null)
    {
        return $this->postJson('/login', [
            'doc_number' => is_null($doc_number) ? $this->user->doc_number : $doc_number,
            'password'   => self::PASSWORD_DE_PRUEBA,
        ]);
    }

    /** @test */
    public function el_login_emite_con_el_nombre_del_usuario_y_sin_tocar_la_red()
    {
        $respuesta = $this->entrar();

        $this->assertTrue($respuesta->json('login'));

        $logins = $this->eventos('login');
        $this->assertCount(1, $logins);
        $this->assertSame($this->user->name, $logins[0]->usuario);
        $this->assertSame(self::DUENO, (int) $logins[0]->owner_id);

        Http::assertNothingSent();
    }

    /**
     * Un empleado se anota a nombre de su dueño, que es el que tiene la clave.
     *
     * @test
     */
    public function el_login_de_un_empleado_se_anota_al_dueno_con_el_nombre_del_empleado()
    {
        $empleado = User::create([
            'name'       => 'Empleado del local',
            'email'      => 'empleado-local-' . uniqid() . '@test.local',
            'doc_number' => 'zz-local-' . uniqid(),
            'password'   => bcrypt(self::PASSWORD_DE_PRUEBA),
            'owner_id'   => self::DUENO,
        ]);

        $this->assertTrue($this->entrar($empleado->doc_number)->json('login'));

        $logins = $this->eventos('login');
        $this->assertCount(1, $logins);
        $this->assertSame('Empleado del local', $logins[0]->usuario);
        $this->assertSame(self::DUENO, (int) $logins[0]->owner_id);
    }

    /** @test */
    public function un_login_rechazado_no_emite()
    {
        $respuesta = $this->postJson('/login', ['doc_number' => $this->user->doc_number, 'password' => 'otra-clave']);

        $this->assertFalse($respuesta->json('login'));
        $this->assertCount(0, $this->eventos('login'));
    }

    /**
     * Con el candado tomado por otro dispositivo el login no entra, y por lo tanto el negocio tampoco.
     *
     * @test
     */
    public function un_login_frenado_por_el_candado_no_emite()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = 'candado-tomado-por-otro-dispositivo';
        $this->user->last_activity = Carbon::now();
        $this->user->save();

        $respuesta = $this->entrar();

        $this->assertFalse($respuesta->json('login'));
        $this->assertTrue($respuesta->json('user_last_activity'));
        $this->assertCount(0, $this->eventos('login'));
    }

    /**
     * 🔴 El login maestro es alguien mirando, no el comercio: no puede mostrar al negocio entrando al local.
     *
     * @test
     */
    public function el_login_maestro_no_emite()
    {
        $respuesta = $this->entrar($this->user->doc_number . ' login');

        $this->assertTrue($respuesta->json('login'));
        $this->assertSame(self::DUENO, $respuesta->json('user.id'));
        $this->assertCount(0, LocalActividadEvento::get());
    }

    /**
     * 🔴 Lo que importa de todo esto: una cola rota no puede dejar a nadie afuera de su sistema.
     *
     * @test
     */
    public function si_no_se_puede_encolar_el_login_entra_igual()
    {
        LocalActividadEvento::creating(function () {
            throw new \RuntimeException('Tabla rota');
        });

        $respuesta = $this->entrar();

        LocalActividadEvento::flushEventListeners();

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('login'));
        $this->assertSame($this->user->id, $respuesta->json('user.id'));
    }

    /**
     * El camino del botón «cerrar la otra sesión e ingresar acá»: también es el negocio entrando.
     *
     * @test
     */
    public function el_login_forzado_emite()
    {
        Notification::fake();

        $this->user->activity_minutes = 60;
        $this->user->session_id = 'candado-tomado-por-otro-dispositivo';
        $this->user->last_activity = Carbon::now();
        $this->user->save();

        $respuesta = $this->postJson('/login-forzado', [
            'doc_number' => $this->user->doc_number,
            'password'   => self::PASSWORD_DE_PRUEBA,
        ]);

        $this->assertTrue($respuesta->json('login'));
        $this->assertCount(1, $this->eventos('login'));
    }

    /**
     * 🔴 Cambiar de versión no es entrar: la persona ya estaba adentro, y el `login` lo emitió el frente de origen.
     *
     * @test
     */
    public function el_login_por_cambio_de_version_no_emite()
    {
        $token = VersionSessionTransferHelper::create_for_user($this->user->id, false, false);

        $respuesta = $this->postJson('/login-from-version-session-token', ['token' => $token]);

        $this->assertTrue($respuesta->json('login'));
        $this->assertCount(0, $this->eventos('login'));
    }

    /**
     * 🔴 Retomar el candado desde otra pestaña tampoco: es la misma persona, en el mismo navegador.
     *
     * @test
     */
    public function retomar_la_pestana_no_emite_login()
    {
        Notification::fake();

        $respuesta = $this->actingAs($this->user, 'sanctum')->postJson('/api/session-lock/forzar-pestana');

        $this->assertTrue($respuesta->json('login'));
        $this->assertCount(0, $this->eventos('login'));
    }

    /** @test */
    public function el_logout_emite_con_el_nombre_del_usuario()
    {
        $this->actingAs($this->user)->postJson('/logout')->assertStatus(200);

        $logouts = $this->eventos('logout');
        $this->assertCount(1, $logouts);
        $this->assertSame($this->user->name, $logouts[0]->usuario);
    }

    /**
     * 🔴 El logout de una sesión de soporte no es que el comercio se fue.
     *
     * @test
     * @dataProvider sesiones_eximidas
     */
    public function el_logout_de_una_sesion_eximida_no_emite($clave_de_sesion)
    {
        $this->actingAs($this->user)
            ->withSession([$clave_de_sesion => 1])
            ->postJson('/logout')
            ->assertStatus(200);

        $this->assertCount(0, $this->eventos('logout'));
    }

    /** Las tres sesiones de AuthController::debe_omitir_candado_de_actividad(). */
    public function sesiones_eximidas()
    {
        return [
            'login maestro'     => ['master_login_bypass_user_last_activity'],
            'demo'              => ['demo_ingreso_token_id'],
            'acceso de soporte' => ['acceso_soporte_token_id'],
        ];
    }

    /**
     * Cambiar de versión no es irse.
     *
     * @test
     */
    public function cambiar_de_version_no_emite_logout()
    {
        $this->actingAs($this->user)->postJson('/version-session-token')->assertStatus(200);

        $this->assertCount(0, $this->eventos('logout'));
    }

    /**
     * El throttle: como máximo una `actividad` cada 5 minutos, por muchos requests que haya.
     *
     * @test
     */
    public function la_actividad_se_emite_como_maximo_cada_5_minutos_por_usuario()
    {
        $inicio = Carbon::now();
        Carbon::setTestNow($inicio);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(200);
        $this->assertCount(1, $this->eventos('actividad'));
        $this->assertSame($this->user->name, $this->eventos('actividad')[0]->usuario);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(200);
        Carbon::setTestNow($inicio->copy()->addMinutes(4));
        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(200);
        $this->assertCount(1, $this->eventos('actividad'), 'Dentro de los 5 minutos no se repite.');

        Carbon::setTestNow($inicio->copy()->addMinutes(6));
        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(200);
        $this->assertCount(2, $this->eventos('actividad'), 'Pasados los 5 minutos sale la siguiente.');

        Http::assertNothingSent();
    }

    /**
     * El throttle es por persona: dos empleados usando el sistema a la vez son dos eventos.
     *
     * @test
     */
    public function el_throttle_de_actividad_es_por_usuario()
    {
        $empleado = User::create([
            'name'     => 'Empleado del local',
            'email'    => 'empleado-local-' . uniqid() . '@test.local',
            'password' => bcrypt(self::PASSWORD_DE_PRUEBA),
            'owner_id' => self::DUENO,
        ]);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user');
        $this->actingAs($empleado, 'sanctum')->getJson('/api/user');

        $actividades = $this->eventos('actividad');
        $this->assertCount(2, $actividades);
        $this->assertSame([self::DUENO, self::DUENO], [(int) $actividades[0]->owner_id, (int) $actividades[1]->owner_id]);
    }

    /**
     * El login arranca el contador: el primer request después de entrar no manda otro evento por el mismo hecho.
     *
     * @test
     */
    public function despues_del_login_no_sale_una_actividad_enseguida()
    {
        $this->entrar();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user');

        $this->assertCount(1, $this->eventos('login'));
        $this->assertCount(0, $this->eventos('actividad'));
    }

    /**
     * 🔴 La SPA llama estas rutas sola con la pestaña quieta (cada 20 s y cada 5 min). Si contaran, un comercio con
     * tienda y una pestaña abierta figuraría activo toda la noche.
     *
     * @test
     * @dataProvider rutas_automaticas
     */
    public function lo_que_la_spa_llama_sola_no_cuenta_como_actividad($ruta)
    {
        /* El 200 importa: un request rechazado tampoco cuenta, y el test pasaría sin mirar la lista. */
        $this->actingAs($this->user, 'sanctum')->getJson($ruta)->assertStatus(200);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    public function rutas_automaticas()
    {
        return [
            'compradores'         => ['/api/buyer'],
            'con query string'    => ['/api/buyer?page=1'],
            'pedidos sin confirmar' => ['/api/order/unconfirmed/models'],
        ];
    }

    /**
     * @test
     * @dataProvider sesiones_eximidas
     */
    public function una_sesion_eximida_no_cuenta_como_actividad($clave_de_sesion)
    {
        /* DemoSessionVigente exige que el token de la demo exista, si no corta el request antes. */
        $valor = $clave_de_sesion === 'demo_ingreso_token_id' ? $this->token_de_demo()->id : 1;

        $this->actingAs($this->user, 'sanctum')
            ->withSession([$clave_de_sesion => $valor])
            ->getJson('/api/user')
            ->assertStatus(200);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    /**
     * Una demo revocada: `DemoSessionVigente` vacía la sesión y rechaza. Nadie fue atendido.
     *
     * @test
     */
    public function una_demo_revocada_no_cuenta_como_actividad()
    {
        $token = $this->token_de_demo(['revoked_at' => Carbon::now()]);

        $respuesta = $this->actingAs($this->user, 'sanctum')
            ->withSession(['demo_ingreso_token_id' => $token->id])
            ->getJson('/api/user');

        $this->assertGreaterThanOrEqual(400, $respuesta->getStatusCode());
        $this->assertCount(0, $this->eventos('actividad'));
    }

    /**
     * El candado lo tiene otro dispositivo: `get_user()` contesta 403 y este request no se atendió.
     *
     * @test
     */
    public function un_request_rechazado_por_el_candado_no_cuenta_como_actividad()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = 'candado-tomado-por-otro-dispositivo';
        $this->user->last_activity = Carbon::now();
        $this->user->save();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(403);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    /**
     * @param  array $datos
     * @return DemoIngresoToken
     */
    private function token_de_demo(array $datos = [])
    {
        return DemoIngresoToken::create(array_merge([
            'token_hash' => hash('sha256', uniqid('el-local', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHour(),
        ], $datos));
    }

    /**
     * La app ComercioCity IA o una clave MCP no son una persona usando el sistema en un navegador.
     *
     * @test
     */
    public function un_bearer_no_cuenta_como_actividad()
    {
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('Authorization', 'Bearer token-que-no-es-de-la-app')
            ->getJson('/api/user')
            ->assertStatus(200);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    /**
     * Una ruta pública, sin nadie adentro.
     *
     * @test
     */
    public function un_request_sin_usuario_no_cuenta_como_actividad()
    {
        $this->getJson('/api/user')->assertStatus(401);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    /** @test */
    public function sin_configuracion_no_hay_actividad()
    {
        config(['services.admin_api.inbound_key' => null]);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/user')->assertStatus(200);

        $this->assertCount(0, $this->eventos('actividad'));
    }

    /** @test */
    public function si_no_se_puede_encolar_la_actividad_el_request_responde_igual()
    {
        LocalActividadEvento::creating(function () {
            throw new \RuntimeException('Tabla rota');
        });

        $respuesta = $this->actingAs($this->user, 'sanctum')->getJson('/api/user');

        LocalActividadEvento::flushEventListeners();

        $respuesta->assertStatus(200);
    }
}
