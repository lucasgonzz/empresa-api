<?php

namespace Tests\Feature\Demo;

use App\Models\DemoIngresoToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `es_sesion_demo` viaja en la respuesta de GET /api/user (mision 52).
 *
 * Por que ahi y no en una llamada propia: esa respuesta el arranque de empresa-spa YA la paga
 * para todos los usuarios --App.vue despacha auth/me--, asi que el marcador no cuesta ni un
 * request ni una query, y sobrevive al F5 porque la fuente es la cookie de sesion y no una
 * variable de JavaScript.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class MarcadorDeSesionDemoTest extends TestCase
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

        $this->actingAs($this->user, 'web');
    }

    /**
     * Crea un token de ingreso vigente y devuelve su id, para marcar la sesion como demo.
     *
     * @return int
     */
    protected function token_de_ingreso()
    {
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', 'zz-ingreso-mision-52-' . uniqid('', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ]);

        return $token->id;
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS 40 CLIENTES REALES.
     *
     * En una sesion normal la respuesta trae el marcador en false, y el endpoint no paga
     * ninguna query nueva por el: session()->has() lee la sesion que el request ya cargo.
     *
     * @group demo
     * @return void
     */
    public function test_un_cliente_real_recibe_el_marcador_en_false_y_sin_pagar_queries()
    {
        $queries_de_demo = array();

        DB::listen(function ($query) use (&$queries_de_demo) {
            if (strpos($query->sql, 'demo_') !== false) {
                $queries_de_demo[] = $query->sql;
            }
        });

        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(200);

        $this->assertFalse(
            $respuesta->json('user.es_sesion_demo'),
            'Una sesion normal no puede venir marcada como demo.'
        );

        $this->assertSame(
            array(),
            $queries_de_demo,
            'El marcador sale de la sesion, no de la base: no puede agregar ninguna query.'
        );
    }

    /**
     * Dentro de una sesion de demo, el marcador viene en true.
     *
     * @group demo
     * @return void
     */
    public function test_en_una_sesion_de_demo_el_marcador_viene_en_true()
    {
        $respuesta = $this->withSession(['demo_ingreso_token_id' => $this->token_de_ingreso()])
            ->getJson('/api/user');

        $respuesta->assertStatus(200);

        $this->assertTrue($respuesta->json('user.es_sesion_demo'));
    }

    /**
     * Y tambien por la rama del bypass de login maestro, que es la que menos se prueba y donde
     * saltearse la linea deja el bug vivo sin que se note.
     *
     * @group demo
     * @return void
     */
    public function test_el_marcador_tambien_viaja_por_la_rama_del_bypass_de_login_maestro()
    {
        $respuesta = $this->withSession([
                'demo_ingreso_token_id' => $this->token_de_ingreso(),
                /** La clave que enciende el bypass en AuthController::is_master_login_activity_bypass_enabled(). */
                'master_login_bypass_user_last_activity' => true,
            ])
            ->getJson('/api/user');

        $respuesta->assertStatus(200);

        $this->assertTrue(
            $respuesta->json('user.es_sesion_demo'),
            'La rama del bypass tiene que traer el marcador igual que la normal.'
        );
    }

    /**
     * 🔴 El marcador es una propiedad que NO existe como columna. Si el $user que la recibe
     * terminara en un save(), Eloquent intentaria escribir una columna inexistente y el request
     * moriria con un 500.
     *
     * Lo que se mide NO es "que no haya UPDATE sobre users": este metodo hace uno legitimo y
     * preexistente --checkUserLastActivity() escribe last_activity y session_id, que es
     * justamente lo que la rama del bypass de login maestro evita, segun su propio docblock--.
     * Lo que se mide es que NINGUN UPDATE mencione la columna inventada, y que el request
     * termine en 200. Las dos cosas juntas son la prueba de que la propiedad no se persiste.
     *
     * @group demo
     * @return void
     */
    public function test_el_marcador_no_termina_escrito_en_ninguna_columna()
    {
        $updates_con_el_marcador = array();

        DB::listen(function ($query) use (&$updates_con_el_marcador) {
            if (strpos($query->sql, 'es_sesion_demo') !== false) {
                $updates_con_el_marcador[] = $query->sql;
            }
        });

        $respuesta = $this->withSession(['demo_ingreso_token_id' => $this->token_de_ingreso()])
            ->getJson('/api/user');

        /** Si se hubiera intentado guardar, esto seria un 500 por columna desconocida. */
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('user.es_sesion_demo'));

        $this->assertSame(
            array(),
            $updates_con_el_marcador,
            'es_sesion_demo no puede aparecer en ninguna query: no existe como columna.'
        );
    }
}
