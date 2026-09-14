<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Helpers\AuthHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clientes reales reportaron que, al hacer un simple refresh (F5), a veces pierden la sesión y
 * ven "tu cuenta está siendo usada en otro dispositivo" sin que exista tal dispositivo.
 *
 * LA HIPÓTESIS (plan de la misión candado-sesion-refresh, 9/9/2026), CONFIRMADA EMPÍRICAMENTE
 * antes de tocar AuthHelper: carrera entre requests concurrentes del MISMO navegador. El ERP se
 * usa con varias pestañas abiertas a la vez, TODAS comparten la MISMA cookie de sesión Laravel
 * (driver `file`). El check-and-set de AuthHelper::checkUserLastActivity() NO era atómico: si dos
 * requests veían el candado abierto casi al mismo tiempo, CADA UNO generaba su propio session_id
 * al azar (`time().rand()`) y lo escribía por separado -en su copia en memoria de la sesión, y en
 * la fila de `users`-. Sin lock entre procesos PHP, la fila terminaba con el valor de quien ganó
 * el último save() a la base, y el archivo de sesión (compartido por las pestañas) con el valor
 * de quien ganó el último guardado de sesión -no necesariamente el mismo request-. El propio
 * navegador dejaba de matchear contra su propia fila, sin que existiera ningún otro dispositivo.
 *
 * CÓMO SE REPRODUCE ACÁ. PHPUnit corre en un solo proceso, secuencial: dos llamadas HTTP
 * sucesivas dentro del mismo test NO alcanzan para reproducir la carrera (Laravel test no encadena
 * cookies entre llamadas por defecto, así que cada una arranca con un session()->getId() nuevo -
 * eso simularía "otro dispositivo", no "la misma pestaña"; y si se fuerza la MISMA sesión de
 * framework de testing, todo queda perfectamente sincronizado porque es el mismo array en
 * memoria PHP, sin ninguna carrera real que forzar). La forma fiel de simular "dos procesos
 * PHP-FPM distintos, cada uno con su propia copia en memoria, compartiendo el mismo archivo de
 * sesión (misma cookie)" es instanciar dos Illuminate\Session\Store INDEPENDIENTES con el MISMO
 * id: ninguno ve lo que el otro escribe hasta que se persiste explícitamente -exactamente como
 * el guardado real del archivo de sesión, que Laravel hace recién al final del ciclo completo del
 * request (StartSession::terminate()), después de que el controller ya respondió-.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class CandadoEntrePestanasDelMismoNavegadorTest extends TestCase
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
     * realmente vigente por 60 minutos.
     *
     * 🔴 `activity_minutes` se fuerza a 60 explícitamente. El usuario 500 sembrado en esta base
     * (empresa_testing_s8, confirmado igual que empresa_testing_s9 el 31/8) lo tiene en 0, y con 0
     * minutos de ventana `ya_paso_el_tiempo()` da `true` SIEMPRE - el candado se consideraría
     * vencido de entrada en CUALQUIER request, y el test no ejercitaría el camino real.
     *
     * @return void
     */
    protected function el_candado_esta_abierto()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = null;
        $this->user->last_activity = null;
        $this->user->save();
    }

    /**
     * Construye una instancia de sesión Laravel independiente en memoria -como la tendría un
     * proceso PHP-FPM propio-, apuntando al id que se le pase. Dos instancias construidas con el
     * MISMO id simulan dos pestañas del mismo navegador (comparten cookie); dos con id distinto
     * simulan dos navegadores distintos. Usa el mismo handler (driver `file`) que ya configuró la
     * aplicación, para que el archivo de sesión compartido sea real y no un doble mock.
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
     * 🔴 EL CASO DEL BUG: dos procesos (pestañas) del mismo navegador, arrancando casi juntos
     * contra el candado abierto -ninguno ve todavía lo que el otro escribe, porque cada uno tiene
     * su PROPIA copia de sesión en memoria, igual que en producción con PHP-FPM-, no tienen que
     * rechazarse entre sí.
     *
     * @return void
     */
    public function test_dos_procesos_concurrentes_del_mismo_navegador_no_se_rechazan_entre_si()
    {
        $this->el_candado_esta_abierto();

        /**
         * El id que ambas "pestañas" comparten -la MISMA cookie laravel_session-. Tiene que
         * cumplir el formato que exige Store::isValidId() (40 alfanuméricos): un id que no lo
         * cumple es DESCARTADO en silencio por Store::setId(), que genera uno aleatorio en su
         * lugar -y ahí cada instancia terminaría con un id DISTINTO, simulando por error dos
         * navegadores en vez de uno solo compartido.
         */
        $sesion_del_navegador = Str::random(40);

        $store_a = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);
        $store_b = $this->nueva_instancia_de_sesion_con_id($sesion_del_navegador);

        /** Se restaura al terminar, para no dejar el contenedor de test en un estado raro. */
        $binding_original_de_sesion = $this->app['session'];
        $auth_helper = new AuthHelper();

        /**
         * "Calienta" el guard 'web' ANTES de tocar el binding 'session': construirlo resuelve
         * 'session.store' (que a su vez llama a session()->driver()), y eso rompe si 'session'
         * ya apunta a uno de los Store planos de abajo (no tienen driver(), solo lo tiene el
         * SessionManager). Una vez construido y cacheado en el AuthManager, el guard no vuelve a
         * tocar 'session.store' -setUser() solo asigna la propiedad en memoria-, así que después
         * de esta línea es seguro reemplazar 'session' cuantas veces haga falta.
         */
        Auth::guard('web')->setUser($this->user->fresh());

        try {
            /** "Pestaña A": arranca con el candado abierto, gana la carrera y lo toma. */
            $this->app->instance('session', $store_a);
            Auth::guard('web')->setUser($this->user->fresh());
            $resultado_a = $auth_helper->checkUserLastActivity();
            $this->assertTrue($resultado_a, 'La pestaña A tiene que poder tomar el candado abierto.');

            /**
             * "Pestaña B": su copia de sesión (store_b) arrancó ANTES de que A persistiera la
             * suya -la carrera real-, así que no tiene nada en memoria todavía. Relee la fila del
             * usuario fresca (como haría cualquier request nuevo al arrancar, vía Auth()->user()).
             */
            $this->app->instance('session', $store_b);
            Auth::guard('web')->setUser($this->user->fresh());
            $resultado_b = $auth_helper->checkUserLastActivity();

            /**
             * Recién ahora "terminan" los dos requests y persisten su sesión a disco -igual que
             * el middleware StartSession::terminate(), al final del ciclo completo-.
             */
            $store_a->save();
            $store_b->save();
        } finally {
            $this->app->instance('session', $binding_original_de_sesion);
        }

        $this->assertTrue(
            $resultado_b,
            'La pestaña B es el MISMO navegador que A (misma sesión_del_navegador) -no puede rechazarse a si misma.'
        );
    }

    /**
     * 🔴 LA PROTECCIÓN REAL QUE NO SE PUEDE DEBILITAR: un dispositivo GENUINAMENTE distinto
     * -otra cookie de sesión Laravel, jamás compartida con la que tiene el candado- sigue
     * rechazado dentro de la ventana de actividad. Es lo que protege a los ~40 clientes reales de
     * que dos personas compartan una sola cuenta a la vez; este fix no puede tocar esto.
     *
     * @return void
     */
    public function test_un_dispositivo_genuinamente_distinto_sigue_rechazado()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = 'candado-tomado-por-un-dispositivo-genuinamente-distinto';
        $this->user->last_activity = Carbon::now();
        $this->user->save();

        /**
         * Un GET normal, con la sesión propia de ESTE test (el session()->getId() que el arnés
         * de testing genere, cualquiera sea, jamás puede coincidir por casualidad con el string
         * fijo de arriba -no hay forma de que dos navegadores compartan session()->getId() sin
         * compartir cookie real).
         */
        $respuesta = $this->actingAs($this->user, 'web')->getJson('/api/user');

        $respuesta->assertStatus(403, 'Un dispositivo genuinamente distinto tiene que seguir rechazado.');
        $this->assertNull($respuesta->json('user'));
    }
}
