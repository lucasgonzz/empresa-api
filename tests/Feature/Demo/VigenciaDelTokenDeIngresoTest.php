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
 * DemoIngresoTokenHelper), mision cruzada `demo-vigencia-turno` (17/8/2026).
 *
 * Dos cosas se prueban aca:
 *
 * 1. El 500 de produccion: `DemoSessionVigente::cerrarSesionExpirada()` llamaba a
 *    `Auth::logout()` sin guard. Cuando `auth:sanctum` ya autentico al usuario en el mismo
 *    request (Laravel lo prioriza por delante via `$middlewarePriority`, aunque en
 *    Kernel.php este declarado al reves), `Auth::shouldUse('sanctum')` ya muto el guard por
 *    defecto, y `Auth::logout()` resolvia `RequestGuard` (sin metodo `logout()`) en vez de
 *    `SessionGuard`. Medido en produccion el 17/8/2026 (storage/logs/laravel.log).
 *
 * 2. El bypass de vigencia en local (`APP_ENV=local`): el vencimiento y la revocacion del
 *    token dejan de bloquear, para poder testear la demo sin pelearse con el horario del
 *    turno. La EXISTENCIA del registro nunca se saltea, ni siquiera en local.
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
     * 🔴 EL TEST QUE PRUEBA EL 500 DE PRODUCCION. Si se revierte el guard explicito
     * (`Auth::guard('web')->logout()` -> `Auth::logout()`), este test tiene que ponerse
     * rojo con un 500 y una excepcion, no solo fallar la aserción de status.
     *
     * @test
     */
    public function una_sesion_de_demo_vencida_cierra_con_401_y_no_con_500()
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

        $response->assertStatus(401);
        $response->assertJson([
            'error'   => 'demo_expirada',
            'message' => 'Tu turno de demo termino.',
        ]);
    }

    /** @test */
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

    /** @test */
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
}
