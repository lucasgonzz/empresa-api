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
 * ────────────────────────────────────────────────────────────────────────────────────────────
 * SEGUNDA VUELTA (10/9/2026, misión redireccion-version-sesion-pwa). Liberar el candado no
 * alcanzaba: la SESIÓN del frente origen seguía abierta, porque el cierre dependía del mismo
 * `/logout` best-effort de arriba. Cuando el login automático en el destino fallaba y el usuario
 * volvía al frente origen a entrar a mano, se encontraba con la sesión vieja colgada en vez de
 * la pantalla de login. Ahora create_version_session_token() también CIERRA la sesión del
 * origen, en la misma request y después de emitir el token. Los tres tests del final cubren eso.
 * ────────────────────────────────────────────────────────────────────────────────────────────
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
     * (medido en empresa_testing_s9 y de nuevo en empresa_testing_s3) lo tiene en 0, y con 0
     * minutos de ventana `ya_paso_el_tiempo()` da
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
         * Sigue siendo la MISMA aplicación de test -las llamadas de un mismo método comparten
         * el contenedor, y con él el guard `web` en memoria que dejó actingAs()-, no una request
         * real desde otro host. Alcanza para probar el endpoint:
         * login_from_version_session_token() no lee el guard actual, solo el token, y por eso
         * "quién esté actingAs" en este punto es irrelevante para el resultado.
         *
         * (Lo que NO persiste entre llamadas es un cookie jar: Laravel solo manda las cookies que
         * se le pasen a mano con withCookie()/withCookies(). Acá decía lo contrario.)
         */
        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertTrue($login_por_transferencia->json('login'));
        $this->assertSame($this->user->id, $login_por_transferencia->json('user.id'));
        $this->assertFalse($login_por_transferencia->json('user_last_activity'));
    }

    /**
     * 🔴 EL PEDIDO DE LUCAS DEL 10/9/2026: al entrar por el frente en desuso, la sesión tiene que
     * quedar CERRADA ahí, en la misma request que emite el token, sin depender del `POST /logout`
     * que el SPA dispara después -best-effort, con `.catch()` silencioso y redirigiendo igual
     * aunque falle-.
     *
     * Se mide con DOS sondas distintas, y hacen falta las dos:
     *
     * 1. **El propio `/version-session-token`**, que está detrás del middleware `auth` (ver
     *    routes/web.php) y es la única ruta autenticada de web.php. Si la sesión del origen
     *    siguiera abierta, una segunda llamada devolvería otro token con 200; con la sesión
     *    cerrada tiene que dar 401. Esto prueba que corrió `Auth::logout()`.
     * 2. **Las claves propias adentro de la sesión.** 🔴 Esta segunda sonda no es adorno: la
     *    primera se satisface con `Auth::logout()` a secas, que solo borra las claves del guard.
     *    Sin ella se podrían sacar los cinco `forget()`, el `invalidate()` y el
     *    `regenerateToken()` del controlador y los tests seguirían en verde -o sea, la parte del
     *    cierre que de verdad vacía la sesión del frente origen, que es LO QUE PIDIÓ LUCAS,
     *    quedaría sin cubrir-. Por eso la sesión se siembra a mano con withSession() antes de
     *    llamar: si el vaciado no corre, esas claves siguen ahí.
     *
     * @return void
     */
    public function test_crear_el_token_de_transferencia_cierra_la_sesion_del_origen()
    {
        $this->el_candado_esta_tomado_por_la_version_origen();

        $respuesta = $this->actingAs($this->user, 'web')
            ->withSession([
                'auth_user' => $this->user->id,
                'owner' => $this->user->id,
                'session_id' => self::CANDADO_DE_LA_VERSION_ORIGEN,
                'skip_offline_articles_sync' => true,
            ])
            ->postJson('/version-session-token');

        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('token'), 'Tiene que devolver el token de transferencia.');

        $this->assertGuest('web');

        /** Sonda 2: la sesión quedó vacía, no solo deslogueada. */
        $respuesta->assertSessionMissing('auth_user');
        $respuesta->assertSessionMissing('owner');
        $respuesta->assertSessionMissing('session_id');
        $respuesta->assertSessionMissing('skip_offline_articles_sync');

        /** Sonda 1: y la ruta autenticada ya no la deja pasar. */
        $segunda_llamada = $this->postJson('/version-session-token');

        $segunda_llamada->assertStatus(401);
    }

    /**
     * El camino feliz del cierre: cerrar la sesión del origen NO puede romper la transferencia.
     * El token se emite antes del cierre justo por esto -necesita al usuario autenticado-, y
     * tiene que seguir sirviendo en el frente destino aunque en el origen ya no quede sesión.
     *
     * Es el mismo ángulo que `test_el_login_automatico_en_la_version_destino_sigue_funcionando_con_el_token`,
     * pero con el cierre del origen VERIFICADO en el medio: sin ese 401 intermedio no se estaría
     * probando que el token sobrevive al cierre, solo que el token funciona.
     *
     * @return void
     */
    public function test_el_token_sigue_sirviendo_en_el_destino_despues_de_cerrar_la_sesion_del_origen()
    {
        $this->el_candado_esta_tomado_por_la_version_origen();

        $token_response = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);
        $plain_token = $token_response->json('token');

        /** El origen quedó cerrado en esa misma request. */
        $this->postJson('/version-session-token')->assertStatus(401);

        /** Y el token emitido antes del cierre sigue entrando en el destino. */
        $login_por_transferencia = $this->postJson('/login-from-version-session-token', [
            'token' => $plain_token,
        ]);

        $login_por_transferencia->assertStatus(200);
        $this->assertTrue(
            $login_por_transferencia->json('login'),
            'Cerrar la sesión del origen no puede invalidar el token que se emitió antes de cerrarla.'
        );
        $this->assertSame($this->user->id, $login_por_transferencia->json('user.id'));
        $this->assertFalse($login_por_transferencia->json('user_last_activity'));
    }

    /**
     * 🔴 LO QUE EL PEDIDO BUSCA DE VERDAD: que si el login automático en el destino falla, el
     * usuario pueda entrar A MANO en el frente origen. Para eso tienen que valer las dos cosas
     * a la vez, y acá se comprueban juntas:
     *
     *   1. la sesión del origen está cerrada (si no, se encuentra la app vieja colgada en vez de
     *      la pantalla de login), y
     *   2. el candado está libre (si no, el login manual choca con "cuenta en uso en otro
     *      dispositivo").
     *
     * `test_el_login_manual_funciona_aunque_el_logout_posterior_nunca_llegue` ya cubre el punto 2
     * solo; este agrega el 1 y prueba el escenario completo tal como lo vive el usuario.
     *
     * @return void
     */
    public function test_el_usuario_puede_entrar_a_mano_en_el_origen_despues_de_pedir_el_token()
    {
        $password_original = $this->user->password;
        $this->user->password = bcrypt(self::PASSWORD_DE_PRUEBA);
        $this->user->save();

        $this->el_candado_esta_tomado_por_la_version_origen();

        $token_response = $this->actingAs($this->user, 'web')
            ->postJson('/version-session-token');

        $token_response->assertStatus(200);

        /** Punto 1: no quedó sesión abierta en el frente origen. */
        $this->postJson('/version-session-token')->assertStatus(401);

        /**
         * Punto 2: y el login manual ahí mismo entra. Nada más pasó en el medio: ni el `/logout`
         * del SPA, ni el consumo del token en el destino.
         */
        $login_manual = $this->postJson('/login', [
            'doc_number' => $this->user->doc_number,
            'password' => self::PASSWORD_DE_PRUEBA,
        ]);

        $login_manual->assertStatus(200);
        $this->assertTrue(
            $login_manual->json('login'),
            'Con la sesión cerrada y el candado libre, el login manual en el origen tiene que entrar.'
        );
        $this->assertFalse(
            $login_manual->json('user_last_activity'),
            'No puede aparecer el cartel de "cuenta en uso en otro dispositivo": la sesión que lo tenía ya se cerró.'
        );

        $this->user->password = $password_original;
        $this->user->save();
    }
}
