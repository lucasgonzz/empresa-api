<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Lucas reportó: al entrar con el login maestro y ser redirigido automáticamente a la versión
 * actual del cliente (`check_version()` en empresa-spa), el auto-login en la versión destino NO
 * se hacía con login maestro. Consecuencia: la sesión nueva tomaba el candado de sesión única del
 * cliente real y disparaba la descarga de artículos offline -exactamente lo que el login maestro
 * básico existe para evitar (ver `sincronizar_offline()` en empresa-spa/src/offline/index.js).
 *
 * LA CAUSA: `create_version_session_token()` armaba el token de transferencia con
 * `VersionSessionTransferHelper::create_for_user($auth_user->id)`, sin decir nada sobre el estado
 * de login maestro de la sesión de origen. `login_from_version_session_token()`, del otro lado,
 * FORZABA explícitamente "no es maestro" (`session()->forget(...)` de las dos claves) y encima
 * nunca copiaba `skip_offline_articles_sync` al `user` que devuelve -a diferencia de `login()`,
 * que sí lo hace-, y el SPA destino usa ese `user` directo, sin pasar por un `auth/me` posterior
 * (ver App.vue `created()`).
 *
 * EL ARREGLO: el token de transferencia ahora persiste `master_login_bypass` y
 * `skip_offline_articles_sync` de la sesión de origen (columnas nuevas en
 * `version_session_transfers`), y `login_from_version_session_token()` los reproduce en la API
 * destino: si la sesión de origen era login maestro, la destino también lo es -sin pasar por
 * `checkUserLastActivity()`, igual que un login maestro directo- y el `user` de la respuesta trae
 * `skip_offline_articles_sync` correcto.
 *
 * Las flags de la sesión de origen se seedean con `withSession()` -patrón ya usado por
 * `CandadoLibreAlCambiarDeVersionTest::test_crear_el_token_de_transferencia_cierra_la_sesion_del_origen`
 * y por `MarcadorDeSesionDemoTest`- y no encadenando un `/login` real antes: las llamadas de test
 * NO comparten cookie jar entre sí (ver corrección en `CandadoLibreAlCambiarDeVersionTest`), así
 * que la sesión que `/login` deja no sobrevive a la siguiente llamada si no se la pasa a mano.
 * `withSession()` reproduce exactamente lo que SÍ pasa en el navegador real: la cookie de sesión
 * es la misma entre el `/login` maestro y el `/version-session-token` que dispara `check_version()`.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class LoginMaestroPersisteAlRedirigirVersionTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de sesión que enciende el bypass, igual que en AuthController y en MarcadorDeSesionDemoTest. */
    const CLAVE_BYPASS_LOGIN_MAESTRO = 'master_login_bypass_user_last_activity';

    /** @var \App\Models\User */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        /** Sin este header no hay sesion en el request (ver BusDeEventosTest). */
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Simula que OTRO dispositivo real ya tiene el candado del usuario 500 tomado. Representa el
     * escenario que de verdad prueba el bypass: alguien más (un dispositivo real del cliente)
     * tiene el candado tomado en el momento en que se consume el token en el destino.
     *
     * `activity_minutes` se fuerza a 60: el usuario 500 sembrado lo tiene en 0, y con eso
     * `ya_paso_el_tiempo()` da `true` siempre y el candado se consideraría vencido de entrada,
     * sin probar nada (mismo hallazgo que ya documenta CandadoLibreAlCambiarDeVersionTest).
     *
     * @return void
     */
    protected function otro_dispositivo_real_toma_el_candado()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = 'otro-dispositivo-real-del-cliente';
        $this->user->last_activity = Carbon::now();
        $this->user->save();
    }

    /**
     * 🔴 EL CASO DEL BUG, en su forma más nítida: la sesión de origen es un login maestro BÁSICO
     * (bypass activo + `skip_offline_articles_sync` en true, igual que deja `login()`), dispara la
     * transferencia de versión, y en el destino otro dispositivo real tiene el candado del cliente
     * tomado. Sin el arreglo, `login_from_version_session_token()` corre `checkUserLastActivity()`
     * como si fuera un login cualquiera, encuentra el candado tomado y RECHAZA el login automático
     * -el mismo síntoma que "cuenta bloqueada en otro dispositivo", ahora disparado por el propio
     * login maestro-. Con el arreglo, el bypass viaja con el token y el login maestro entra igual.
     *
     * Y el otro ángulo del mismo pedido: el `user` de la respuesta tiene que traer
     * `skip_offline_articles_sync = true` -es el `user` que App.vue usa directo, sin pasar por
     * `auth/me`-, que es lo que evita la descarga offline en el destino.
     *
     * @return void
     */
    public function test_login_maestro_basico_entra_en_el_destino_sin_tocar_el_candado_del_cliente()
    {
        $token_response = $this->actingAs($this->user, 'web')
            ->withSession([
                self::CLAVE_BYPASS_LOGIN_MAESTRO => true,
                'skip_offline_articles_sync' => true,
            ])
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);
        $plain_token = $token_response->json('token');
        $this->assertNotEmpty($plain_token);

        /** En el destino, otro dispositivo real del cliente tiene el candado tomado. */
        $this->otro_dispositivo_real_toma_el_candado();

        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertTrue(
            $login_por_transferencia->json('login'),
            'El login maestro tiene que entrar en el destino SIN IMPORTAR el candado del cliente real -es justo lo que el bypass existe para garantizar.'
        );
        $this->assertFalse(
            $login_por_transferencia->json('user_last_activity'),
            'No puede aparecer "cuenta en uso en otro dispositivo": un login maestro no compite por ese candado.'
        );
        $this->assertSame($this->user->id, $login_por_transferencia->json('user.id'));
        $this->assertTrue(
            $login_por_transferencia->json('user.skip_offline_articles_sync'),
            'EL PUNTO DEL BUG: el user que recibe el SPA destino (usado directo, sin auth/me) tiene que traer skip_offline_articles_sync en true.'
        );

        /** Y el candado del dispositivo real queda intacto: el bypass no lo pisó. */
        $usuario_actualizado = $this->user->fresh();
        $this->assertSame(
            'otro-dispositivo-real-del-cliente',
            $usuario_actualizado->session_id,
            'procesar_login() en modo bypass no tiene que tocar el candado de ningún dispositivo real.'
        );
    }

    /**
     * El modo `login full` NO omite la descarga offline (solo el básico lo hace, ver
     * `AuthController::login()`), pero SIGUE siendo login maestro: el bypass del candado tiene
     * que viajar igual. Las dos flags son independientes y el arreglo las trata como tales -es la
     * prueba de que no se colapsaron en una sola.
     *
     * @return void
     */
    public function test_login_maestro_full_preserva_el_bypass_pero_no_omite_offline_al_transferir()
    {
        $token_response = $this->actingAs($this->user, 'web')
            ->withSession([
                self::CLAVE_BYPASS_LOGIN_MAESTRO => true,
                /** login_full: bypass activo, pero SIN omitir offline. */
                'skip_offline_articles_sync' => false,
            ])
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);
        $plain_token = $token_response->json('token');

        $this->otro_dispositivo_real_toma_el_candado();

        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertTrue(
            $login_por_transferencia->json('login'),
            'El bypass de candado tiene que seguir activo en modo full, aunque no omita offline.'
        );
        $this->assertFalse($login_por_transferencia->json('user_last_activity'));
        $this->assertFalse(
            $login_por_transferencia->json('user.skip_offline_articles_sync'),
            'El destino tiene que respetar que ESTE login maestro no pidió omitir offline.'
        );
    }

    /**
     * Regresión: una sesión de origen NORMAL (no maestra, las dos flags en false -el estado por
     * default de cualquier login con usuario y contraseña-) que se transfiere de versión tiene que
     * seguir respetando el candado de sesión única del cliente real. El arreglo no puede volver
     * "maestra" a ninguna sesión que no lo era.
     *
     * @return void
     */
    public function test_un_login_normal_sigue_respetando_el_candado_al_transferir()
    {
        $token_response = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);
        $plain_token = $token_response->json('token');

        $this->otro_dispositivo_real_toma_el_candado();

        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertFalse(
            $login_por_transferencia->json('login'),
            'Un login NO maestro tiene que seguir rechazado si otro dispositivo real tiene el candado -esta es la protección real para los 40 clientes, y el arreglo no puede debilitarla.'
        );
        $this->assertTrue($login_por_transferencia->json('user_last_activity'));
    }
}
