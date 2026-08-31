<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Clientes reportaron que al entrar al sistema y ser redirigidos automáticamente a la versión
 * actual (`check_version()` en empresa-spa, ver AuthController::create_version_session_token),
 * a veces el login automático en la versión destino fallaba, y al intentar entrar a mano ahí
 * mismo aparecía "cuenta bloqueada, está siendo utilizada en otro dispositivo" -sin que hubiera
 * ningún otro dispositivo real.
 *
 * LA CAUSA: el candado de sesión única (AuthHelper::checkUserLastActivity()/session_id) solo se
 * liberaba dentro de logout(), y el SPA dispara ese `/logout` como un pedido de red APARTE,
 * después de crear el token de transferencia, justo antes de redirigir. Si ese `/logout` no
 * llegaba a completarse -corte de red, timeout, la pestaña ya navegó- el candado quedaba tomado
 * por la sesión vieja, y ni el login automático en destino ni un login manual posterior podían
 * tomarlo: 60 minutos (USER_ACTIVITY_MINUTES) de bloqueo real sin que hubiera nada bloqueando.
 *
 * EL ARREGLO: create_version_session_token() libera el candado en la MISMA request que genera
 * el token, antes de que el SPA dispare el `/logout` ni redirija a ningún lado.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class CandadoLibreAlCambiarDeVersionTest extends TestCase
{
    use DatabaseTransactions;

    /** Valor de `session_id` con el que se simula que la versión origen tiene el candado tomado. */
    const CANDADO_DE_LA_VERSION_ORIGEN = 'candado-tomado-en-la-version-origen';

    /** Contraseña conocida para poder ejercitar /login (viene hasheada en la base sembrada). */
    const PASSWORD_DE_PRUEBA = 'zz-password-testing';

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
     * Deja el candado tomado, simulando que la versión origen ya tiene una sesión activa.
     *
     * `last_activity` se pone en ahora para que `ya_paso_el_tiempo()` de false: si estuviera
     * vencido, checkUserLastActivity() lo tomaría solo y el test no probaría nada.
     *
     * 🔴 `activity_minutes` se fuerza a 60 explícitamente. El usuario 500 sembrado en esta base
     * (empresa_testing_s9) lo tiene en 0, y con 0 minutos de ventana `ya_paso_el_tiempo()` da
     * `true` SIEMPRE -incluso con `last_activity` recién puesto en `Carbon::now()`, porque el
     * valor que vuelve de la base pierde los microsegundos y `now()` en el chequeo siguiente ya
     * es un instante después-. Medido: sin este seteo, `test_el_login_manual_funciona_...`
     * pasaba igual con el fix revertido, porque el candado se consideraba vencido de entrada y
     * no por el arreglo. Con 60 el candado queda realmente vigente y el test sí ejercita lo que
     * dice ejercitar.
     *
     * @return void
     */
    protected function el_candado_esta_tomado_por_la_version_origen()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = self::CANDADO_DE_LA_VERSION_ORIGEN;
        $this->user->last_activity = Carbon::now();
        $this->user->save();
    }

    /**
     * EL CASO DEL BUG, verificado en el punto exacto donde se produce: crear el token de
     * transferencia tiene que soltar el candado en la MISMA respuesta, sin que nada más -un
     * `/logout` posterior, una redirección- tenga que pasar todavía.
     *
     * @return void
     */
    public function test_crear_el_token_de_transferencia_libera_el_candado_de_inmediato()
    {
        $this->el_candado_esta_tomado_por_la_version_origen();

        $respuesta = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('token'), 'Tiene que devolver el token de transferencia.');

        $usuario_actualizado = $this->user->fresh();

        $this->assertNull(
            $usuario_actualizado->session_id,
            'El candado tiene que quedar libre apenas se crea el token, no recién cuando corra /logout.'
        );
        $this->assertNull(
            $usuario_actualizado->last_activity,
            'Lo mismo para last_activity: liberado ya, no en un segundo paso que puede no llegar.'
        );
    }

    /**
     * 🔴 EL ESCENARIO REAL QUE REPORTARON LOS CLIENTES, de punta a punta: el `/logout` que el
     * SPA dispara después de crear el token NUNCA SE LLAMA (se simula la falla de red/timeout
     * salteándolo directamente), y aun así un login manual posterior tiene que entrar, no
     * chocar contra "cuenta bloqueada en otro dispositivo".
     *
     * @return void
     */
    public function test_el_login_manual_funciona_aunque_el_logout_posterior_nunca_llegue()
    {
        $password_original = $this->user->password;
        $this->user->password = bcrypt(self::PASSWORD_DE_PRUEBA);
        $this->user->save();

        $this->el_candado_esta_tomado_por_la_version_origen();

        /** Paso 1: la versión origen pide el token de transferencia (dispara el redirect). */
        $token_response = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);

        /**
         * Paso 2: el `/logout` de la versión origen NO se llama -es justo el que fallaba en el
         * reporte-, y tampoco se consume el token en la versión destino: se prueba directamente
         * el fallback manual, que es el que el cliente necesitaba y no tenía.
         */
        $login_manual = $this->postJson('/login', [
            'doc_number' => $this->user->doc_number,
            'password' => self::PASSWORD_DE_PRUEBA,
        ]);

        $login_manual->assertStatus(200);
        $this->assertTrue(
            $login_manual->json('login'),
            'El login manual tiene que entrar: el candado ya se liberó al crear el token, aunque el logout nunca haya llegado.'
        );
        $this->assertFalse(
            $login_manual->json('user_last_activity'),
            'No puede aparecer el cartel de "cuenta bloqueada en otro dispositivo": no hay ningún otro dispositivo.'
        );

        $this->user->password = $password_original;
        $this->user->save();
    }

    /**
     * El camino feliz, con el candado tomado de entrada: el token que emite
     * create_version_session_token tiene que seguir sirviendo para el login automático en la
     * versión destino.
     *
     * 🔴 NO es un control independiente del fix -sin el arreglo también falla, porque
     * login_from_version_session_token() encuentra el candado tomado por la versión origen y
     * rechaza-. Es un tercer ángulo del mismo arreglo: confirma que además de liberar el
     * candado, el token en sí sigue siendo válido y consumible.
     *
     * @return void
     */
    public function test_el_login_automatico_en_la_version_destino_sigue_funcionando_con_el_token()
    {
        $this->el_candado_esta_tomado_por_la_version_origen();

        $token_response = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);
        $plain_token = $token_response->json('token');

        /**
         * Sigue siendo la MISMA sesión de test (actingAs() y el cookie jar persisten entre
         * llamadas de un mismo método), no una request real desde otro host. Alcanza para
         * probar el endpoint: login_from_version_session_token() no lee el guard actual, solo
         * el token, y por eso "quién esté actingAs" en este punto es irrelevante para el
         * resultado.
         */
        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertTrue($login_por_transferencia->json('login'));
        $this->assertSame($this->user->id, $login_por_transferencia->json('user.id'));
        $this->assertFalse($login_por_transferencia->json('user_last_activity'));
    }
}
