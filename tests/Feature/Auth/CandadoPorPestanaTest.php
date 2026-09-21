<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Helpers\AuthHelper;
use App\Models\User;
use App\Notifications\SessionForcedLogoutNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión candado-sesion-por-pestana (19/9/2026): el candado de sesión única entre DISPOSITIVOS
 * DISTINTOS no cambia (ver CandadoEntrePestanasDelMismoNavegadorTest y ExpulsarOtroDispositivoTest,
 * de la misión candado-sesion-refresh del 9/9/2026). Lo que se agrega es la capacidad de
 * ENDURECERLO para que separe también pestañas del MISMO navegador, apagada por defecto
 * (`users.bloquear_pestanas_duplicadas = false`) y prendida por Lucas, por cliente, desde el admin.
 *
 * 🔴 MISMA TÉCNICA que CandadoEntrePestanasDelMismoNavegadorTest, y por el mismo motivo: dos
 * llamadas HTTP sucesivas dentro de UN test de Laravel NO encadenan cookies por defecto, así que
 * cada una arranca con un `session()->getId()` nuevo -eso simularía "otro dispositivo", no "la
 * misma pestaña"-. Medido acá mismo: las tres primeras versiones de estos tests, escritas con
 * `getJson()` sucesivos, fallaban con 403 aun con el flag apagado, precisamente por eso. La forma
 * fiel de simular "dos pestañas reales del mismo navegador" es construir una única
 * `Illuminate\Session\Store` con un id fijo (la MISMA cookie) y llamar a
 * `AuthHelper::checkUserLastActivity()` directo, atando el header `X-Tab-Id` a un
 * `Illuminate\Http\Request` propio bindeado como 'request' -que es de donde lee
 * `request()->header('X-Tab-Id')`-.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class CandadoPorPestanaTest extends TestCase
{
    use DatabaseTransactions;

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
     * Deja el candado abierto (recién logueado / vencido de antes), con la ventana de actividad
     * realmente vigente por 60 minutos -mismo hallazgo que el resto de la suite de Auth para el
     * usuario 500 sembrado (con activity_minutes=0 el candado se consideraría vencido siempre y
     * el test no ejercitaría el camino real)-.
     *
     * @param bool $estricto Valor de `bloquear_pestanas_duplicadas` para este test.
     * @return void
     */
    protected function el_candado_esta_abierto($estricto)
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = null;
        $this->user->last_activity = null;
        $this->user->bloquear_pestanas_duplicadas = $estricto;
        $this->user->save();
    }

    /**
     * Construye una instancia de sesión Laravel apuntando a un id fijo -la MISMA cookie que
     * compartirían dos pestañas del mismo navegador-. Mismo mecanismo que
     * CandadoEntrePestanasDelMismoNavegadorTest::nueva_instancia_de_sesion_con_id().
     *
     * @param string $id
     * @return \Illuminate\Session\Store
     */
    protected function nueva_instancia_de_sesion_con_id($id)
    {
        $handler = $this->app['session']->getHandler();
        $store = new Store('laravel_session', $handler, $id);
        $store->start();
        return $store;
    }

    /**
     * Bindea un Request propio con el header X-Tab-Id ya seteado, que es de donde
     * AuthHelper::checkUserLastActivity() lo lee (`request()->header('X-Tab-Id')`).
     *
     * @param string $tab_id
     * @return void
     */
    protected function con_tab_id($tab_id)
    {
        $request = Request::create('/api/user', 'GET');
        $request->headers->set('X-Tab-Id', $tab_id);
        $this->app->instance('request', $request);
    }

    /**
     * 🔴 EL TEST MÁS IMPORTANTE DE LOS DOS: protege la regresión del fix candado-sesion-refresh
     * (9/9/2026). Con el flag apagado (default de TODO el parque existente), dos pestañas del
     * mismo navegador -mismo session()->getId(), X-Tab-Id DISTINTO cada una- tienen que seguir
     * conviviendo exactamente igual que antes de esta misión.
     *
     * @return void
     */
    public function test_con_el_flag_apagado_dos_pestanas_conviven_aunque_manden_x_tab_id_distinto()
    {
        $this->el_candado_esta_abierto(false);

        $sesion_del_navegador = Str::random(40);
        $store = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);

        $binding_original_de_sesion = $this->app['session'];
        $binding_original_de_request = $this->app->bound('request') ? $this->app['request'] : null;
        $auth_helper = new AuthHelper();

        /** Ver el comentario de "calentar el guard" en CandadoEntrePestanasDelMismoNavegadorTest. */
        Auth::guard('web')->setUser($this->user->fresh());

        try {
            $this->app->instance('session', $store);

            $this->con_tab_id('tab-a');
            $resultado_a = $auth_helper->checkUserLastActivity();
            $this->assertTrue($resultado_a, 'La pestaña A tiene que poder tomar el candado abierto.');

            $this->con_tab_id('tab-b');
            $resultado_b = $auth_helper->checkUserLastActivity();
            $this->assertTrue(
                $resultado_b,
                'Con el flag apagado, un X-Tab-Id distinto no puede rechazar a la misma sesión de navegador.'
            );

            $store->save();
        } finally {
            $this->app->instance('session', $binding_original_de_sesion);
            if ($binding_original_de_request !== null) {
                $this->app->instance('request', $binding_original_de_request);
            }
        }

        $usuario_actualizado = $this->user->fresh();
        $this->assertStringNotContainsString(
            ':',
            (string) $usuario_actualizado->session_id,
            'Con el flag apagado, session_id nunca lleva el sufijo :tabId -tiene que quedar igual que antes de esta misión.'
        );
    }

    /**
     * 🔴 EL CASO NUEVO: con el flag prendido, la pestaña B (otro X-Tab-Id, misma sesión de
     * navegador) pierde el candado que tomó la pestaña A.
     *
     * @return void
     */
    public function test_con_el_flag_prendido_dos_x_tab_id_distintos_se_pisan()
    {
        $this->el_candado_esta_abierto(true);

        $sesion_del_navegador = Str::random(40);
        $store = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);

        $binding_original_de_sesion = $this->app['session'];
        $binding_original_de_request = $this->app->bound('request') ? $this->app['request'] : null;
        $auth_helper = new AuthHelper();

        Auth::guard('web')->setUser($this->user->fresh());

        try {
            $this->app->instance('session', $store);

            $this->con_tab_id('tab-a');
            $resultado_a = $auth_helper->checkUserLastActivity();
            $this->assertTrue($resultado_a, 'La pestaña A tiene que poder tomar el candado abierto.');

            $this->con_tab_id('tab-b');
            $resultado_b = $auth_helper->checkUserLastActivity();
            $this->assertFalse(
                $resultado_b,
                'Con el flag prendido, una pestaña con otro X-Tab-Id tiene que perder el candado de la primera.'
            );

            $store->save();
        } finally {
            $this->app->instance('session', $binding_original_de_sesion);
            if ($binding_original_de_request !== null) {
                $this->app->instance('request', $binding_original_de_request);
            }
        }

        $usuario_actualizado = $this->user->fresh();
        $this->assertStringEndsWith(
            ':tab-a',
            (string) $usuario_actualizado->session_id,
            'El candado tiene que haber quedado en poder de la pestaña A (la que lo tomó primero), no de B.'
        );
    }

    /**
     * `AuthController::get_user()` distingue el 403 de "misma sesión, otra pestaña" del de
     * "dispositivo distinto" con la clave nueva `misma_sesion_otra_pestana`. Se invoca el
     * controller directo (no por HTTP) con el mismo armado de sesión/request que el resto de
     * esta suite: es la única forma de fijar de antemano "candado tomado por tab-a, este
     * request es tab-b, MISMO navegador" sin depender de que dos HTTP calls compartan cookie.
     *
     * @return void
     */
    public function test_get_user_avisa_misma_sesion_otra_pestana_cuando_corresponde()
    {
        $this->el_candado_esta_abierto(true);

        $sesion_del_navegador = Str::random(40);
        $store = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);

        $binding_original_de_sesion = $this->app['session'];
        $binding_original_de_request = $this->app->bound('request') ? $this->app['request'] : null;
        $auth_helper = new AuthHelper();

        Auth::guard('web')->setUser($this->user->fresh());

        try {
            $this->app->instance('session', $store);

            // Tab-a toma el candado primero.
            $this->con_tab_id('tab-a');
            $this->assertTrue($auth_helper->checkUserLastActivity());

            // Tab-b (mismo navegador, mismo session()->getId()) pide get_user() y tiene que
            // perder -pero con el aviso nuevo, no con el 403 plano de "dispositivo distinto".
            $this->con_tab_id('tab-b');

            $controller = new \App\Http\Controllers\CommonLaravel\AuthController();
            $respuesta = $controller->get_user();

            $store->save();
        } finally {
            $this->app->instance('session', $binding_original_de_sesion);
            if ($binding_original_de_request !== null) {
                $this->app->instance('request', $binding_original_de_request);
            }
        }

        $this->assertSame(403, $respuesta->getStatusCode());
        $datos = json_decode($respuesta->getContent(), true);
        $this->assertNull($datos['user']);
        $this->assertTrue(
            $datos['misma_sesion_otra_pestana'] ?? false,
            'Es la MISMA sesión de navegador (mismo session()->getId()) con otro X-Tab-Id: tiene que avisarlo con la clave nueva.'
        );
    }

    /**
     * El botón del modal nuevo ("Usar esta pestaña"): sin contraseña, reclama el candado para la
     * pestaña que lo pidió y expulsa a la otra por el mismo broadcast que ya usa login_forzado().
     *
     * @return void
     */
    public function test_forzar_pestana_reclama_el_candado_y_avisa_a_la_pestana_vieja()
    {
        Notification::fake();

        $this->el_candado_esta_abierto(true);

        $sesion_del_navegador = Str::random(40);
        $store = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);

        $binding_original_de_sesion = $this->app['session'];
        $binding_original_de_request = $this->app->bound('request') ? $this->app['request'] : null;
        $auth_helper = new AuthHelper();

        Auth::guard('web')->setUser($this->user->fresh());

        try {
            $this->app->instance('session', $store);

            $this->con_tab_id('tab-a');
            $this->assertTrue($auth_helper->checkUserLastActivity());

            $this->con_tab_id('tab-b');
            $this->assertFalse(
                $auth_helper->checkUserLastActivity(),
                'Precondición del test: tab-b tiene que empezar habiendo perdido el candado de tab-a.'
            );

            // El controller completo (no solo el helper): forzar_pestana() también dispara la
            // notificación y arma la respuesta con la forma de login()/login_forzado().
            $controller = new \App\Http\Controllers\CommonLaravel\AuthController();
            $respuesta = $controller->forzar_pestana(request());

            $store->save();
        } finally {
            $this->app->instance('session', $binding_original_de_sesion);
            if ($binding_original_de_request !== null) {
                $this->app->instance('request', $binding_original_de_request);
            }
        }

        $datos = json_decode($respuesta->getContent(), true);

        $this->assertTrue(
            $datos['login'],
            'Sin contraseña -la cookie ya autentica- tiene que poder reclamar la pestaña.'
        );
        $this->assertSame($this->user->id, $datos['user']['id']);

        $usuario_final = $this->user->fresh();
        $this->assertStringEndsWith(
            ':tab-b',
            (string) $usuario_final->session_id,
            'Tras forzar-pestana, el candado tiene que quedar tomado por la pestaña que lo pidió (tab-b), no por tab-a.'
        );

        Notification::assertSentTo($this->user, SessionForcedLogoutNotification::class);
    }
}
