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

        /**
         * La precondicion "esta instancia no es una demo" se ESTABLECE, no se asume.
         *
         * Se aprendio midiendo: una fila de demo_tracking_config sembrada a mano fuera de la
         * suite —para mirar el panel en el navegador— dejo este test en rojo en la corrida
         * completa mientras pasaba aislado, y el gate del carril lo leyo como una regresion
         * del codigo. Un test que depende de que la base este limpia no prueba lo que dice
         * probar: prueba el estado de la base. Como la clase usa DatabaseTransactions, este
         * borrado se revierte al final de cada caso.
         */
        DemoTrackingConfig::query()->delete();

        /**
         * Idem con los eventos: el progreso que devuelve el endpoint sale de esta tabla, asi
         * que un evento sembrado a mano fuera de la suite --o dejado por otra clase-- da vuelta
         * el resultado de la mitad de estos casos. Misma leccion que la fila de canal.
         */
        DemoEvento::query()->delete();

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
     * Crea un evento local, que es de donde sale el progreso para restaurar (mision 52).
     *
     * @param string $nombre
     * @param string|null $clip_id
     * @param array $datos
     * @param bool $sincronizado
     * @return \App\Models\DemoEvento
     */
    protected function crear_evento($nombre, $clip_id = null, array $datos = [], $sincronizado = false)
    {
        return DemoEvento::create([
            'uuid'            => 'zz-51-' . uniqid('', true),
            'nombre'          => $nombre,
            'clip_id'         => $clip_id,
            'datos'           => $datos,
            'ocurrido_at'     => Carbon::now(),
            'sincronizado_at' => $sincronizado ? Carbon::now() : null,
            'intentos'        => 0,
            'ultimo_error'    => null,
        ]);
    }

    /**
     * Sin ningun evento, todos los clips vienen sin marcar y las notas vacias.
     *
     * @group demo
     * @return void
     */
    public function test_sin_eventos_ningun_clip_esta_visto_y_las_notas_estan_vacias()
    {
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);
        $this->assertSame('', $respuesta->json('notas'));

        foreach ($respuesta->json('secciones.0.clips') as $clip) {
            $this->assertFalse($clip['visto'], 'El clip ' . $clip['id'] . ' no deberia estar visto.');
            $this->assertFalse($clip['abierto'], 'El clip ' . $clip['id'] . ' no deberia estar abierto.');
        }
    }

    /**
     * Un `clip.terminado` marca ESE clip como visto y no los demas.
     *
     * @group demo
     * @return void
     */
    public function test_un_clip_terminado_marca_solo_ese_clip()
    {
        $this->configurar_canal();

        $this->crear_evento('clip.abierto', '1.1');
        $this->crear_evento('clip.terminado', '1.1');

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);

        $clips = $respuesta->json('secciones.0.clips');

        $this->assertSame('1.1', $clips[0]['id']);
        $this->assertTrue($clips[0]['visto'], 'El clip terminado tiene que volver visto.');
        $this->assertTrue($clips[0]['abierto']);

        $this->assertFalse($clips[1]['visto'], 'Y el otro clip no.');
        $this->assertFalse($clips[1]['abierto']);
    }

    /**
     * Las notas son las del ULTIMO `nota.escrita`: el panel manda el texto completo, no
     * incrementos, asi que el ultimo evento es el estado actual del campo.
     *
     * @group demo
     * @return void
     */
    public function test_las_notas_son_las_del_ultimo_evento()
    {
        $this->configurar_canal();

        $this->crear_evento('nota.escrita', null, ['texto' => 'primera version']);
        $this->crear_evento('nota.escrita', null, ['texto' => 'la que vale']);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);
        $this->assertSame('la que vale', $respuesta->json('notas'));
    }

    /**
     * 🔴 Un evento sin sincronizar cuenta igual.
     *
     * Es la mitad que sostiene la decision de derivar el progreso de la tabla LOCAL: la mision
     * 50 se construyo para que la demo funcione con el admin caido, y si para restaurar la
     * pantalla hiciera falta que el admin ya se hubiera enterado, una caida dejaria al lead
     * mirando de nuevo videos que ya vio.
     *
     * @group demo
     * @return void
     */
    public function test_un_evento_sin_sincronizar_cuenta_igual()
    {
        $this->configurar_canal();

        $evento = $this->crear_evento('clip.terminado', '1.1', [], false);

        $this->assertNull($evento->sincronizado_at, 'El evento tiene que estar pendiente de empuje.');

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);
        $this->assertTrue(
            $respuesta->json('secciones.0.clips.0.visto'),
            'Con el admin caido el lead igual tiene que recuperar su progreso.'
        );
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS 40 CLIENTES REALES.
     *
     * Sin sesion de demo, el endpoint responde 204 sin pagar NI UNA query y sin escribir nada.
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

    /**
     * 🔴 Y con sesion de demo pero SIN canal, tampoco se toca el progreso.
     *
     * Este es el test que le faltaba al de arriba, y la diferencia importa: aquel sale por la
     * primera guarda --la de sesion-- y nunca llega a evaluar el canal, asi que su medicion de
     * "cero queries" la cumple una rama que ya cubre otro test. Si alguien moviera
     * progreso_por_clip() por encima de la guarda del canal, aquel seguiria en verde. Este no.
     *
     * @group demo
     * @return void
     */
    public function test_con_sesion_de_demo_pero_sin_canal_no_se_consulta_el_progreso()
    {
        $this->assertSame(0, DemoTrackingConfig::count());

        $queries_de_progreso = [];

        DB::listen(function ($query) use (&$queries_de_progreso) {
            if (strpos($query->sql, 'demo_eventos') !== false) {
                $queries_de_progreso[] = $query->sql;
            }
        });

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);

        $this->assertSame(
            [],
            $queries_de_progreso,
            'Sin canal no se puede consultar el progreso: el endpoint tiene que cortar antes.'
        );
    }

    /**
     * Las notas de mas de 2 KB no se pierden en el F5.
     *
     * El emisor descarta `datos` por encima del tope y guarda una marca en su lugar. Si el
     * endpoint tomara el ultimo evento a secas, encontraria ese sin texto, devolveria vacio y
     * el panel le PISARIA las notas al lead con el campo en blanco -- teniendo el texto bueno
     * ahi al lado, en el evento anterior.
     *
     * @group demo
     * @return void
     */
    public function test_un_evento_de_notas_descartado_no_borra_las_notas_anteriores()
    {
        $this->configurar_canal();

        $this->crear_evento('nota.escrita', null, ['texto' => 'lo que el lead escribio']);
        /** Asi queda una nota que excedio el tope: el emisor le saca el texto. */
        $this->crear_evento('nota.escrita', null, ['_descartado' => 'excede_tope', '_bytes' => 5000]);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);
        $this->assertSame(
            'lo que el lead escribio',
            $respuesta->json('notas'),
            'Un evento descartado no puede borrarle las notas al lead.'
        );
    }
}
