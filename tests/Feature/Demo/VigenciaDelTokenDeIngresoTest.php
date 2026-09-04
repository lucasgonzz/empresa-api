<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoIngresoTokenHelper;
use App\Models\DemoIngresoToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la vigencia del token de ingreso a la demo (DemoSessionVigente y
 * DemoIngresoTokenHelper), mision cruzada `demo-vigencia-turno` (17/8/2026), actualizados por
 * la mision `demo-sesion-magic-link-no-expira` (4/9/2026).
 *
 * Tres cosas se prueban aca:
 *
 * 1. El 500 de produccion: `DemoSessionVigente::cerrarSesionExpirada()` llamaba a
 *    `Auth::logout()` sin guard. Cuando `auth:sanctum` ya autentico al usuario en el mismo
 *    request (Laravel lo prioriza por delante via `$middlewarePriority`, aunque en
 *    Kernel.php este declarado al reves), `Auth::shouldUse('sanctum')` ya muto el guard por
 *    defecto, y `Auth::logout()` resolvia `RequestGuard` (sin metodo `logout()`) en vez de
 *    `SessionGuard`. Medido en produccion el 17/8/2026 (storage/logs/laravel.log).
 *
 * 2. El bypass de vigencia en local (`APP_ENV=local`): la revocacion del token deja de
 *    bloquear, para poder testear la demo sin pelearse con el horario del turno. La
 *    EXISTENCIA del registro nunca se saltea, ni siquiera en local.
 *
 * 3. 🔴 Desde el 4/9/2026 (pedido de Lucas, un lead se comio un 401 a los ~50 minutos en
 *    pleno uso), `DemoSessionVigente` YA NO corta por vencimiento de turno (`expires_at`):
 *    una sesion de demo ya abierta no se corta sola, solo por revocacion explicita o porque
 *    el registro no existe. El vencimiento de turno SIGUE cortando el CANJE de un token
 *    nuevo (`DemoIngresoTokenHelper::resolver()`, gate de entrada) — eso no cambio, y los
 *    tests de `resolver_*` mas abajo lo siguen probando igual.
 *
 * Usuario 500 (mismo patron que Catalogos/Expenses/Stock/Sales): sembrado en la base de
 * testing del slot. Si no esta, los tests se saltean en vez de dar un falso rojo.
 */
class VigenciaDelTokenDeIngresoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * GET autenticado con el Referer del dominio stateful de Sanctum (SANCTUM_STATEFUL_DOMAINS
     * en .env.testing: `empresa.local:8183`). Sin esto, `EnsureFrontendRequestsAreStateful::
     * fromFrontend()` da false, la sesion nunca arranca (`StartSession` ni corre), y
     * `DemoSessionVigente` corta en su guarda temprana #1 (`!hasSession()`) sin mirar el
     * marcador -- cualquier aserto de 401 sale falso, y cualquier aserto de 200 pasa vacio
     * (pasaria igual con el codigo roto). Se detecto corriendo esta suite: tres tests que
     * esperaban 401 devolvian 200 porque la sesion jamas se activaba.
     *
     * @param string $uri
     * @return \Illuminate\Testing\TestResponse
     */
    protected function get_con_sesion_stateful($uri)
    {
        return $this->withHeaders(['referer' => 'http://empresa.local:8183/'])
            ->getJson($uri);
    }

    /**
     * Mismo criterio que `get_con_sesion_stateful()`, para un POST.
     *
     * @param string $uri
     * @return \Illuminate\Testing\TestResponse
     */
    protected function post_con_sesion_stateful($uri)
    {
        return $this->withHeaders(['referer' => 'http://empresa.local:8183/'])
            ->postJson($uri);
    }

    /**
     * Crea un token de ingreso para el usuario 500 con la vigencia pedida.
     *
     * @param string|null $plain_token
     * @param \Carbon\Carbon|null $expires_at Null = ya vencido (una hora atras).
     * @param \Carbon\Carbon|null $revoked_at Null = no revocado.
     * @return array{token: DemoIngresoToken, plain: string}
     */
    protected function crear_token($plain_token = null, $expires_at = null, $revoked_at = null)
    {
        $plain = $plain_token ?? 'zz_token_' . uniqid();

        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', $plain),
            'user_id'    => 500,
            'expires_at' => $expires_at ?? Carbon::now()->subHour(),
            'revoked_at' => $revoked_at,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    // -----------------------------------------------------------------------------------
    // DemoSessionVigente, contra una ruta real (grupo 'api' + 'auth:sanctum')
    // -----------------------------------------------------------------------------------

    /**
     * 🔴 EL CAMBIO DE COMPORTAMIENTO CENTRAL DE ESTA MISION (4/9/2026). Un turno vencido ya
     * NO corta una sesion de demo que ya estaba abierta -- solo lo hacia la revocacion
     * explicita o la inexistencia del registro. Antes de esta mision este mismo caso daba
     * 401 `demo_expirada`; ahora tiene que dejar pasar el request con 200.
     *
     * @test
     */
    public function una_sesion_de_demo_vencida_por_turno_sigue_pasando()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $creado = $this->crear_token();
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /**
     * 🔴 EL TEST QUE PRUEBA EL 500 DE PRODUCCION. Si se revierte el guard explicito
     * (`Auth::guard('web')->logout()` -> `Auth::logout()`), este test tiene que ponerse
     * rojo con un 500 y una excepcion, no solo fallar la aserción de status. Es el unico
     * camino que sigue pasando por `cerrarSesionExpirada()` en `testing` despues de esta
     * mision: la revocacion explicita.
     *
     * @test
     */
    public function una_sesion_de_demo_con_token_revocado_tambien_cierra_sin_explotar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $creado = $this->crear_token(null, Carbon::now()->addHour(), Carbon::now());
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'demo_expirada']);
    }

    /**
     * El caso que de verdad prueba el pedido de Lucas del 4/9/2026: un lead que lleva un buen
     * rato usando la demo, con el turno terminado hace HORAS (no hace un ratito), sigue
     * pudiendo usarla. Es la reproduccion del incidente real: un lead entrado por Magic Link
     * se comio un "Unauthenticated" a los ~50 minutos de uso, en pleno recorrido.
     *
     * @test
     */
    public function un_turno_vencido_hace_horas_no_corta_una_sesion_de_demo_en_uso()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $creado = $this->crear_token(null, Carbon::now()->subHours(5), null);
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /** @test */
    public function una_sesion_de_demo_vigente_deja_pasar_el_request()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $creado = $this->crear_token(null, Carbon::now()->addHour(), null);
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /**
     * El control: sin el marcador de sesion, DemoSessionVigente no toca nada. Es el camino
     * de un cliente real (o de este mismo usuario de testing fuera de una demo).
     *
     * @test
     */
    public function sin_marcador_de_demo_en_sesion_el_request_sigue_normal()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $this->actingAs($user, 'web');

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /**
     * Sigue siendo cierto despues de esta mision, pero ya no por el mismo motivo: antes
     * pasaba por el bypass de local (`bypass_vigencia_local()`), ahora pasa directo porque
     * `DemoSessionVigente` ni siquiera mira `expires_at`. El test verifica lo mismo desde
     * afuera y no hace falta tocarlo.
     *
     * @test
     */
    public function en_local_una_sesion_de_demo_vencida_sigue_pasando()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'local']);

        $creado = $this->crear_token();
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /** @test */
    public function en_local_una_sesion_con_token_revocado_tambien_sigue_pasando()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'local']);

        $creado = $this->crear_token(null, Carbon::now()->addHour(), Carbon::now());
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(200);
    }

    /**
     * 🔴 El bypass de local nunca saltea la EXISTENCIA del registro. Un token_id que no
     * resuelve a ninguna fila sigue cerrando la sesion, en cualquier entorno.
     *
     * @test
     */
    public function en_local_un_token_id_inexistente_sigue_bloqueando()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'local']);

        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => 999999999]);

        $response = $this->get_con_sesion_stateful('/api/user');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'demo_expirada']);
    }

    // -----------------------------------------------------------------------------------
    // DemoIngresoTokenHelper::resolver(), directo
    // -----------------------------------------------------------------------------------

    /** @test */
    public function resolver_devuelve_null_si_el_hash_no_matchea_ningun_token()
    {
        config(['app.env' => 'testing']);
        $this->assertNull(DemoIngresoTokenHelper::resolver('token_que_no_existe'));

        config(['app.env' => 'local']);
        $this->assertNull(
            DemoIngresoTokenHelper::resolver('token_que_no_existe'),
            'Ni siquiera en local un hash sin fila resuelve algo.'
        );
    }

    /** @test */
    public function resolver_en_testing_no_devuelve_un_token_vencido()
    {
        config(['app.env' => 'testing']);
        $creado = $this->crear_token('zz_plano_vencido');

        $this->assertNull(DemoIngresoTokenHelper::resolver('zz_plano_vencido'));
    }

    /** @test */
    public function resolver_en_testing_no_devuelve_un_token_revocado()
    {
        config(['app.env' => 'testing']);
        $creado = $this->crear_token('zz_plano_revocado', Carbon::now()->addHour(), Carbon::now());

        $this->assertNull(DemoIngresoTokenHelper::resolver('zz_plano_revocado'));
    }

    /** @test */
    public function resolver_en_local_devuelve_un_token_vencido()
    {
        config(['app.env' => 'local']);
        $creado = $this->crear_token('zz_plano_vencido_local');

        $resuelto = DemoIngresoTokenHelper::resolver('zz_plano_vencido_local');

        $this->assertNotNull($resuelto);
        $this->assertEquals($creado['token']->id, $resuelto->id);
    }

    /** @test */
    public function resolver_en_local_devuelve_un_token_revocado()
    {
        config(['app.env' => 'local']);
        $creado = $this->crear_token('zz_plano_revocado_local', Carbon::now()->addHour(), Carbon::now());

        $resuelto = DemoIngresoTokenHelper::resolver('zz_plano_revocado_local');

        $this->assertNotNull($resuelto);
        $this->assertEquals($creado['token']->id, $resuelto->id);
    }

    /** @test */
    public function bypass_vigencia_local_es_true_solo_con_app_env_local()
    {
        config(['app.env' => 'local']);
        $this->assertTrue(DemoIngresoTokenHelper::bypass_vigencia_local());

        config(['app.env' => 'testing']);
        $this->assertFalse(DemoIngresoTokenHelper::bypass_vigencia_local());

        config(['app.env' => 'production']);
        $this->assertFalse(DemoIngresoTokenHelper::bypass_vigencia_local());
    }

    // -----------------------------------------------------------------------------------
    // Heartbeat (mision `demo-sesion-magic-link-no-expira`, 4/9/2026)
    // -----------------------------------------------------------------------------------

    /**
     * El caso normal: el SPA le pega desde una sesion de demo con el panel montado. No
     * necesita persistir nada, solo devolver 200 -- el efecto que importa (refrescar la
     * sesion) lo hace `StartSession` antes de llegar aca.
     *
     * @test
     */
    public function heartbeat_con_sesion_de_demo_autenticada_devuelve_200_ok()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        config(['app.env' => 'testing']);

        $creado = $this->crear_token();
        $this->actingAs($user, 'web');
        $this->withSession(['demo_ingreso_token_id' => $creado['token']->id]);

        $response = $this->post_con_sesion_stateful('/api/demo/heartbeat');

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
    }

    /**
     * Un request sin autenticar contra esta ruta no tiene por que reventar. En la practica
     * el frontend nunca le pega fuera de una demo, pero la ruta vive en el mismo grupo `api`
     * que corre para los ~40 clientes reales, y un 500 ahi si seria un problema.
     *
     * @test
     */
    public function heartbeat_sin_autenticar_no_revienta()
    {
        $response = $this->post_con_sesion_stateful('/api/demo/heartbeat');

        $response->assertStatus(401);
    }
}
