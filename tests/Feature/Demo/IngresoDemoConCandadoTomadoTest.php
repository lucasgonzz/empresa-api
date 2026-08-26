<?php

namespace Tests\Feature\Demo;

use App\Models\DemoIngresoToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Un lead con su magic link valido tiene que poder entrar SIEMPRE, aunque otra sesion tenga
 * tomado el candado de sesion unica.
 *
 * EL BUG QUE ESTO PROTEGE (medido el 26/8/2026 contra demo.comerciocity.com en produccion):
 *
 *     POST /api/demo/ingreso  -> 200 {"ok":true}      el token validaba bien
 *     GET  /api/user          -> 403 {"user":null}    y el pedido siguiente rebotaba
 *
 * 6 de 6 veces seguidas. La SPA mandaba al lead a /login sin explicar nada, y el lead no tenia
 * forma de destrabarlo: el candado solo se suelta con un logout desde la sesion que lo tomo, o
 * esperando USER_ACTIVITY_MINUTES (60 minutos por defecto). Alcanzaba con que Lucas tuviera la
 * demo abierta para dejar afuera al lead.
 *
 * La causa: AuthHelper::checkUserLastActivity() guarda `session_id` y `last_activity` en el
 * usuario, y AuthController::get_user() devolvia 403 cuando el pedido llegaba desde otra sesion.
 * DemoIngresoController hacia el login pero nunca tocaba ese candado.
 *
 * 🔴 EL SEGUNDO TEST ES EL QUE IMPORTA IGUAL O MAS: el candado tiene que seguir valiendo para el
 * login normal, que es donde protege de verdad a los 40 clientes reales. Si la exencion se filtra
 * a ese camino, el arreglo es peor que el bug.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class IngresoDemoConCandadoTomadoTest extends TestCase
{
    use DatabaseTransactions;

    /** Valor de `session_id` con el que se simula que OTRA sesion tiene el candado. */
    const CANDADO_DE_OTRO = 'candado-tomado-por-otra-sesion';

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

        $this->actingAs($this->user, 'web');
    }

    /**
     * Deja el candado tomado por una sesion que no es la de este request.
     *
     * `last_activity` se pone en ahora para que `ya_paso_el_tiempo()` de false: si estuviera
     * vencido, checkUserLastActivity() tomaria el candado y el test no probaria nada.
     *
     * @return void
     */
    protected function otra_sesion_toma_el_candado()
    {
        $this->user->session_id = self::CANDADO_DE_OTRO;
        $this->user->last_activity = Carbon::now();
        $this->user->save();
    }

    /**
     * Crea un token de ingreso vigente y devuelve su id, para marcar la sesion como demo.
     *
     * @return int
     */
    protected function token_de_ingreso()
    {
        $token = DemoIngresoToken::create(array(
            'token_hash' => hash('sha256', 'zz-candado-' . uniqid('', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ));

        return $token->id;
    }

    /**
     * El caso del bug: sesion de demo con el candado tomado por otro.
     *
     * @group demo
     * @return void
     */
    public function test_el_lead_entra_aunque_otra_sesion_tenga_el_candado_tomado()
    {
        $this->otra_sesion_toma_el_candado();

        $respuesta = $this->withSession(array('demo_ingreso_token_id' => $this->token_de_ingreso()))
            ->getJson('/api/user');

        $respuesta->assertStatus(200);

        $this->assertSame(
            $this->user->id,
            $respuesta->json('user.id'),
            'El lead tiene que recibir su usuario, no un 403 sin explicacion.'
        );

        $this->assertTrue(
            $respuesta->json('user.es_sesion_demo'),
            'Y tiene que seguir viniendo marcada como sesion de demo.'
        );
    }

    /**
     * 🔴 LA NO REGRESION QUE PROTEGE A LOS CLIENTES REALES.
     *
     * Un login normal con el candado tomado por otra sesion tiene que seguir rechazado. La
     * exencion de la demo no puede haberse filtrado a este camino.
     *
     * @group demo
     * @return void
     */
    public function test_un_login_normal_sigue_rechazado_si_otra_sesion_tiene_el_candado()
    {
        $this->otra_sesion_toma_el_candado();

        /** Sin marcador de demo y sin bypass de login maestro: es un cliente real. */
        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(403);
        $this->assertNull($respuesta->json('user'));
    }

    /**
     * La sesion de demo NO se roba el candado: convive con la que lo tenia.
     *
     * Es la decision de Lucas del 26/8/2026, con la alternativa sobre la mesa. Si el magic link
     * se lo robara, entrar el lead expulsaria a Lucas de la demo justo mientras acompaña la
     * llamada. Este test es el que lo sostiene en el tiempo.
     *
     * @group demo
     * @return void
     */
    public function test_la_sesion_de_demo_no_le_roba_el_candado_a_la_otra()
    {
        $this->otra_sesion_toma_el_candado();
        $ultima_actividad = $this->user->fresh()->last_activity;

        $this->withSession(array('demo_ingreso_token_id' => $this->token_de_ingreso()))
            ->getJson('/api/user')
            ->assertStatus(200);

        $despues = $this->user->fresh();

        $this->assertSame(
            self::CANDADO_DE_OTRO,
            $despues->session_id,
            'La sesion de demo no puede pisar el session_id de la que tenia el candado.'
        );

        $this->assertEquals(
            (string) $ultima_actividad,
            (string) $despues->last_activity,
            'Ni refrescarle el last_activity: eso alargaria el candado ajeno sin que nadie lo pida.'
        );
    }
}
