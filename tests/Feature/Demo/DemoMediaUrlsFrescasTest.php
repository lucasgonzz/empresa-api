<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\User;
use App\Services\DemoMediaUrlsFetcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /api/demo/plan pidiendole al admin las URLs frescas de los videos
 * (mision demo-panel-recorrido).
 *
 * El test que gobierna es el ultimo grupo: NINGUNA respuesta del admin puede romperle el panel
 * al lead. 404 (admin viejo), 401 (lead de la dinamica anterior), red caida, cuerpo con otra
 * forma -- todo cae al `media_urls` guardado y el panel sale igual. Y sin sesion de demo no se
 * le pregunta NADA al admin, que es lo que sostiene el costo cero en las instancias de los ~40
 * clientes reales.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes
 * y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class DemoMediaUrlsFrescasTest extends TestCase
{
    use DatabaseTransactions;

    /** URL del canal de eventos que deja el setup. De aca se deriva la del endpoint de lectura. */
    const EVENTOS_URL = 'https://admin.test/api/demo-eventos';

    /** La derivada, o sea lo que este repo tiene que pedirle al admin. */
    const MEDIA_URLS_URL = 'https://admin.test/api/demo-media-urls';

    const TOKEN = 'zz-token-media-urls-frescas';

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
         * La precondicion se ESTABLECE, no se asume: una fila de canal o un evento sembrados a
         * mano fuera de la suite --para mirar el panel en el navegador-- dan vuelta el resultado
         * de estos casos y el gate del carril lo lee como una regresion del codigo. Misma leccion
         * que en PlanDeLaDemoTest. Con DatabaseTransactions estos borrados se revierten.
         */
        DemoTrackingConfig::query()->delete();
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
     * El mapa VIEJO, el que quedo congelado en el setup.
     *
     * Es la foto del bug medido el 17/8/2026: el setup corrio a las 15:02 con una sola clave y
     * los links entraron al admin a las 15:51.
     *
     * @return array
     */
    protected function mapa_guardado()
    {
        return ['intro' => 'https://videos.test/intro.mp4'];
    }

    /**
     * El mapa que hoy tiene el admin, con los clips del plan ya cargados.
     *
     * @return array
     */
    protected function mapa_fresco()
    {
        return [
            '1.1' => 'https://videos.test/fresca-1-1.mp4',
            '1.10' => 'https://videos.test/fresca-1-10.mp4',
        ];
    }

    /**
     * Deja el canal configurado con el plan y el mapa viejo.
     *
     * @return void
     */
    protected function configurar_canal()
    {
        DemoTrackingConfigHelper::guardar(
            self::TOKEN,
            self::EVENTOS_URL,
            $this->plan_de_ejemplo(),
            $this->mapa_guardado()
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
            'token_hash' => hash('sha256', 'zz-ingreso-media-urls-' . uniqid('', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ]);

        return $this->withSession(['demo_ingreso_token_id' => $token->id]);
    }

    /**
     * Las URLs de los clips tal como salen en la respuesta del plan.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @return array Mapa clip_id => url|null.
     */
    protected function urls_de_la_respuesta($respuesta)
    {
        $urls = [];

        foreach ($respuesta->json('secciones.0.clips') as $clip) {
            $urls[$clip['id']] = $clip['url'];
        }

        return $urls;
    }

    /**
     * Comprueba que el panel salio con el mapa GUARDADO, o sea que la falla del admin no se vio.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @param string $porque
     * @return void
     */
    protected function assert_cayo_al_mapa_guardado($respuesta, $porque)
    {
        $respuesta->assertStatus(200);

        $urls = $this->urls_de_la_respuesta($respuesta);

        $this->assertCount(2, $urls, 'El plan tiene que salir entero igual (' . $porque . ').');
        $this->assertNull($urls['1.1'], 'Con el admin fallando se usa el mapa guardado (' . $porque . ').');
        $this->assertNull($urls['1.10'], 'Con el admin fallando se usa el mapa guardado (' . $porque . ').');

        $this->assertSame(
            $this->mapa_guardado(),
            DemoTrackingConfig::query()->first()->media_urls,
            'Una respuesta que no sirve NO puede pisar el mapa guardado (' . $porque . ').'
        );
    }

    /**
     * Camino feliz: el admin devuelve el mapa fresco, el panel sale con esas URLs y el mapa
     * guardado queda refrescado para la proxima vez que el admin no conteste.
     *
     * @group demo
     * @return void
     */
    public function test_el_admin_devuelve_las_urls_frescas_y_el_plan_sale_con_esas()
    {
        $this->configurar_canal();

        Http::fake([
            self::MEDIA_URLS_URL => Http::response(['media_urls' => $this->mapa_fresco()], 200),
        ]);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(200);

        $urls = $this->urls_de_la_respuesta($respuesta);

        $this->assertSame('https://videos.test/fresca-1-1.mp4', $urls['1.1']);
        $this->assertSame('https://videos.test/fresca-1-10.mp4', $urls['1.10']);

        $this->assertSame(
            $this->mapa_fresco(),
            DemoTrackingConfig::query()->first()->media_urls,
            'El mapa fresco queda guardado como cache para cuando el admin no conteste.'
        );
    }

    /**
     * 🔴 La forma EXACTA de la peticion, que es el contrato con admin-api.
     *
     * GET a la URL derivada, con la key del canal de eventos en X-Demo-Eventos-Key. Si esto
     * cambia de este lado sin cambiar del otro, el panel vuelve a quedar sin videos y el unico
     * sintoma es una linea de log.
     *
     * @group demo
     * @return void
     */
    public function test_la_peticion_al_admin_es_un_get_con_la_key_del_canal()
    {
        $this->configurar_canal();

        Http::fake([
            self::MEDIA_URLS_URL => Http::response(['media_urls' => $this->mapa_fresco()], 200),
        ]);

        $this->como_sesion_de_demo()->getJson('/api/demo/plan')->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === self::MEDIA_URLS_URL
                && $request->hasHeader('X-Demo-Eventos-Key', self::TOKEN)
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    /**
     * La derivacion de la URL: del ULTIMO segmento `demo-eventos` sale `demo-media-urls`.
     *
     * Se deriva y no se recibe en el payload del setup a proposito: una URL nueva solo llegaria
     * en el proximo setup, que es exactamente el problema que esta mision resuelve. El valor de
     * produccion de Lucas esta en la lista, con la carpeta y el `public/` y todo.
     *
     * @group demo
     * @return void
     */
    public function test_la_url_del_endpoint_se_deriva_del_ultimo_segmento()
    {
        $casos = [
            'https://admin.test/api/demo-eventos' => 'https://admin.test/api/demo-media-urls',
            /** El valor real de hoy. */
            'http://localhost/empresa/admin-api/public/api/demo-eventos' => 'http://localhost/empresa/admin-api/public/api/demo-media-urls',
            /** Con barra final, el ultimo segmento sigue siendo el que hay que reemplazar. */
            'https://admin.test/api/demo-eventos/' => 'https://admin.test/api/demo-media-urls/',
            /** Solo el ULTIMO: un host o una carpeta que se llame igual no se toca. */
            'https://demo-eventos.test/api/demo-eventos' => 'https://demo-eventos.test/api/demo-media-urls',
            /** La query no es camino y viaja tal cual. */
            'https://admin.test/api/demo-eventos?v=2' => 'https://admin.test/api/demo-media-urls?v=2',
        ];

        foreach ($casos as $eventos_url => $esperada) {
            $this->assertSame(
                $esperada,
                DemoMediaUrlsFetcher::url_del_endpoint($eventos_url),
                'Mal derivada la URL de ' . $eventos_url
            );
        }

        /** Una URL que no tiene ese segmento no se inventa: se usa el mapa guardado. */
        $this->assertNull(DemoMediaUrlsFetcher::url_del_endpoint('https://admin.test/api/otra-cosa'));
        $this->assertNull(DemoMediaUrlsFetcher::url_del_endpoint(''));
        $this->assertNull(DemoMediaUrlsFetcher::url_del_endpoint(null));
    }

    /**
     * Admin viejo, sin el endpoint desplegado: 404 y el panel sale con lo guardado.
     *
     * Es la mitad "empresa nueva + admin viejo" de la compatibilidad hacia atras.
     *
     * @group demo
     * @return void
     */
    public function test_un_404_del_admin_cae_al_mapa_guardado()
    {
        $this->configurar_canal();

        Http::fake(['*' => Http::response('<!DOCTYPE html> not found', 404)]);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $this->assert_cayo_al_mapa_guardado($respuesta, '404 del admin');
    }

    /**
     * El admin rechaza la key: 401 y el panel sale con lo guardado.
     *
     * Es el lead de la dinamica anterior, cuyo token el admin ya no reconoce.
     *
     * @group demo
     * @return void
     */
    public function test_un_401_del_admin_cae_al_mapa_guardado()
    {
        $this->configurar_canal();

        Http::fake(['*' => Http::response(['error' => 'no autorizado'], 401)]);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $this->assert_cayo_al_mapa_guardado($respuesta, '401 del admin');
    }

    /**
     * El admin no contesta (red caida, DNS, timeout): el panel sale igual.
     *
     * @group demo
     * @return void
     */
    public function test_el_admin_que_no_contesta_cae_al_mapa_guardado()
    {
        $this->configurar_canal();

        Http::fake(function () {
            throw new ConnectionException('zz simulacion: cURL error 28, se acabo el timeout');
        });

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $this->assert_cayo_al_mapa_guardado($respuesta, 'el admin no contesta');
    }

    /**
     * Y una excepcion cualquiera del cliente HTTP tampoco escapa.
     *
     * ConnectionException es la esperable; esta prueba la promesa mas ancha, que es la que vale:
     * NINGUNA Throwable sale del fetcher.
     *
     * @group demo
     * @return void
     */
    public function test_una_excepcion_cualquiera_del_cliente_http_cae_al_mapa_guardado()
    {
        $this->configurar_canal();

        Http::fake(function () {
            throw new \RuntimeException('zz simulacion: el cliente HTTP exploto');
        });

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $this->assert_cayo_al_mapa_guardado($respuesta, 'excepcion del cliente HTTP');
    }

    /**
     * Un 200 con una forma que no es el mapa del contrato tampoco se usa.
     *
     * El cuerpo es de otro sistema y el panel indexa este array por clip_id. Aceptar cualquier
     * cosa serializable dejaria el mapa guardado pisado con basura, que es peor que no llamar:
     * el proximo lead entra y ya no tiene ni las viejas.
     *
     * @group demo
     * @return void
     */
    public function test_un_cuerpo_con_forma_inesperada_cae_al_mapa_guardado()
    {
        $cuerpo = null;

        /** Un solo stub, por referencia: Http::fake() acumula y gana el primero que matchea. */
        Http::fake(function () use (&$cuerpo) {
            return Http::response($cuerpo, 200);
        });

        $formas = [
            'sin la clave media_urls' => ['urls' => ['1.1' => 'https://videos.test/x.mp4']],
            'media_urls que no es un mapa' => ['media_urls' => 'https://videos.test/x.mp4'],
            'media_urls como lista' => ['media_urls' => ['https://videos.test/x.mp4']],
            'media_urls vacio' => ['media_urls' => []],
            'valores que no son strings' => ['media_urls' => ['1.1' => ['url' => 'https://videos.test/x.mp4']]],
            'valores vacios' => ['media_urls' => ['1.1' => '   ']],
            'cuerpo que no es un objeto' => ['ok'],
        ];

        foreach ($formas as $descripcion => $forma) {
            $cuerpo = $forma;

            $this->configurar_canal();

            $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

            $this->assert_cayo_al_mapa_guardado($respuesta, $descripcion);
        }
    }

    /**
     * 🔴 Un cuerpo que PARECE el mapa pero viene con status de error no se usa.
     *
     * Sin esta guarda, cualquier intermediario que conteste JSON —un proxy o un portal cautivo
     * de la red del cliente, un gateway que cachea envoltorios de error— podria pisarle el mapa
     * guardado al lead con URLs que el admin nunca emitio. El status es lo que dice si contesto
     * el admin con autoridad; el cuerpo solo no alcanza.
     *
     * Se agrego DESPUES de mutar: sacar la guarda de status dejaba los otros casos en verde,
     * porque ningun cuerpo de error de los de arriba trae un mapa usable.
     *
     * @group demo
     * @return void
     */
    public function test_un_status_de_error_con_un_cuerpo_que_parece_el_mapa_no_se_usa()
    {
        $this->configurar_canal();

        Http::fake(['*' => Http::response(['media_urls' => $this->mapa_fresco()], 502)]);

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $this->assert_cayo_al_mapa_guardado($respuesta, '502 con cuerpo que parece el mapa');
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS ~40 CLIENTES REALES.
     *
     * Sin sesion de demo el endpoint corta en 204 ANTES de llegar al fetcher, asi que no sale ni
     * una peticion al admin. Es lo unico que sostiene el costo cero de esta mision en las
     * instancias que no son de demo: una llamada HTTP de 2 segundos de timeout metida en el
     * camino de un cliente real no es un detalle de performance, es la instancia colgada.
     *
     * @group demo
     * @return void
     */
    public function test_sin_sesion_de_demo_no_se_le_pregunta_nada_al_admin()
    {
        $this->configurar_canal();

        Http::fake();

        $respuesta = $this->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);
        $this->assertSame('', $respuesta->getContent(), 'El 204 no puede traer cuerpo.');

        Http::assertNothingSent();
    }

    /**
     * Y con sesion de demo pero sin canal configurado, tampoco.
     *
     * La diferencia con el de arriba importa: aquel sale por la guarda de sesion y nunca evalua
     * el canal, asi que si alguien moviera el fetcher por encima de esa guarda aquel seguiria en
     * verde. Este no.
     *
     * @group demo
     * @return void
     */
    public function test_con_sesion_de_demo_pero_sin_canal_no_se_le_pregunta_nada_al_admin()
    {
        $this->assertSame(0, DemoTrackingConfig::count(), 'La base de testing no puede tener canal sembrado.');

        Http::fake();

        $respuesta = $this->como_sesion_de_demo()->getJson('/api/demo/plan');

        $respuesta->assertStatus(204);

        Http::assertNothingSent();
    }
}
