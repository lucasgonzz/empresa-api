<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/demo/plan — el plan que consume el panel lateral (mision 51).
 *
 * El test que gobierna es el ultimo: en una instancia que NO es de demo el endpoint responde
 * 204 mudo, sin tocar la base y sin escribir nada. Este mismo codigo corre en las instancias
 * de los ~40 clientes reales.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de
 * antes y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class PlanDeLaDemoTest extends TestCase
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

        DemoTrackingConfigHelper::olvidar_cache();

        /** Sin este header no hay sesion en el request (ver BusDeEventosTest). */
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');

        $this->actingAs($this->user, 'web');
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();

        parent::tearDown();
    }

    /**
     * Plan de ejemplo con la forma que congela el admin (mision 48).
     *
     * @return array
     */
    protected function plan_de_ejemplo()
    {
        return [
            'version_catalogo' => 2,
            'secciones' => [
                [
                    'id' => 'S1 - Listado',
                    'orden' => 1,
                    'clips' => [
                        ['id' => '1.1', 'titulo' => 'Crear un articulo', 'tipo' => 'nucleo', 'practica' => true],
                        ['id' => '1.10', 'titulo' => 'Catalogo PDF por link vivo', 'tipo' => 'biblioteca', 'practica' => false],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array|null $media_urls
     * @return void
     */
    protected function configurar_canal($media_urls = null)
    {
        DemoTrackingConfigHelper::guardar(
            'zz-token-plan-mision-51',
            'https://admin.test/api/demo-eventos',
            $this->plan_de_ejemplo(),
            is_null($media_urls) ? ['1.1' => 'https://videos.test/1-1.mp4'] : $media_urls
        );

        DemoTrackingConfigHelper::olvidar_cache();
    }

    /**
     * Deja el request siguiente como una sesion de demo real (token vigente + marcador).
     *
     * @return $this
     */
    protected function como_sesion_de_demo()
    {
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', 'zz-ingreso-mision-51-' . uniqid('', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ]);

        return $this->withSession(['demo_ingreso_token_id' => $token->id]);
    }

    /**
     * Sin sesion de demo, 204 mudo.
     *
     * @group demo
     * @return void
     */
    public function test_sin_sesion_de_demo_responde_204_sin_cuerpo()
    {
        $this->configurar_canal();

        $respuesta = $this->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);
        $this->assertSame('', $respuesta->getContent(), 'El 204 no puede traer cuerpo.');
    }

    /**
     * Con sesion de demo y plan cargado, las secciones con las URLs resueltas.
     *
     * @group demo
     * @return void
     */
    public function test_con_sesion_de_demo_devuelve_el_plan_con_las_urls_resueltas()
    {
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);

        $secciones = $respuesta->json('secciones');

        $this->assertCount(1, $secciones);
        $this->assertSame('S1 - Listado', $secciones[0]['id']);
        $this->assertSame('Listado', $secciones[0]['titulo'], 'El titulo sale de lo que va despues del guion.');
        $this->assertCount(2, $secciones[0]['clips']);
        $this->assertSame('https://videos.test/1-1.mp4', $secciones[0]['clips'][0]['url']);
        $this->assertSame('nucleo', $secciones[0]['clips'][0]['tipo']);
        $this->assertSame('biblioteca', $secciones[0]['clips'][1]['tipo']);
    }

    /**
     * Un clip sin URL cargada viaja igual, con url null. No se filtra.
     *
     * Si se filtraran, el panel se veria vacio durante todo el desarrollo: hoy solo esta
     * subido intro.mp4.
     *
     * @group demo
     * @return void
     */
    public function test_un_clip_sin_url_viaja_con_url_null_y_no_se_filtra()
    {
        $this->configurar_canal(['1.1' => 'https://videos.test/1-1.mp4']);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);

        $clips = $respuesta->json('secciones.0.clips');

        $this->assertCount(2, $clips, 'El clip sin URL tiene que viajar igual.');
        $this->assertSame('1.10', $clips[1]['id']);
        $this->assertNull($clips[1]['url']);
    }

    /**
     * Con canal pero sin plan (setup viejo), 204: es un caso previsto, no un error.
     *
     * @group demo
     * @return void
     */
    public function test_una_demo_sin_plan_responde_204()
    {
        DemoTrackingConfigHelper::guardar('zz-token-sin-plan', 'https://admin.test/api/demo-eventos', null, null);
        DemoTrackingConfigHelper::olvidar_cache();

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS 40 CLIENTES REALES.
     *
     * Sin fila de configuracion, el endpoint responde 204 sin pagar NI UNA query y sin
     * escribir nada.
     *
     * @group demo
     * @return void
     */
    public function test_una_instancia_sin_canal_responde_204_sin_tocar_la_base()
    {
        $this->assertSame(0, DemoTrackingConfig::count(), 'La base de testing no puede tener canal sembrado.');

        $eventos_antes = DemoEvento::count();
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            if (strpos($query->sql, 'demo_tracking_config') !== false || strpos($query->sql, 'demo_eventos') !== false) {
                $queries[] = $query->sql;
            }
        });

        $respuesta = $this->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);

        $this->assertSame([], $queries, 'Un cliente real no puede pagar ninguna query por este endpoint.');
        $this->assertSame($eventos_antes, DemoEvento::count(), 'Y no se escribe nada.');
    }
}
