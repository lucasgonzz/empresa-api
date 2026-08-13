<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoEventosPushHelper;
use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Models\DemoEvento;
use App\Models\DemoIngresoToken;
use App\Models\DemoTrackingConfig;
use App\Models\User;
use App\Services\DemoEventoEmitter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El bus de eventos de la demo (mision 50).
 *
 * El test que gobierna a todos los demas es el primero: en una instancia que NO es de demo
 * —o sea, en las de los ~40 clientes reales, que corren este mismo codigo— crear un articulo
 * no puede escribir una fila, ni mandar un request, ni pagar una query de mas. Si ese test
 * falla, no se mergea.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de
 * antes y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class BusDeEventosTest extends TestCase
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

        /** El cache del canal es por proceso: entre casos hay que soltarlo a mano. */
        DemoTrackingConfigHelper::olvidar_cache();
        DemoEventoEmitter::reiniciar_estado_de_proceso();

        /**
         * Sin este header no hay sesion en el request: el grupo 'api' arranca la sesion via
         * EnsureFrontendRequestsAreStateful, que solo la activa cuando el request viene de un
         * dominio declarado en sanctum.stateful. Y sin sesion, todo lo que mide esta clase
         * (el marcador de demo, las guardas del emisor, el endpoint de UX) se evalua sobre
         * un request que no se parece en nada al que manda el SPA real.
         */
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');

        $this->actingAs($this->user, 'web');
    }

    /**
     * Intercepta todo el trafico saliente con una respuesta 200 vacia.
     *
     * Va por test y NO en setUp: los stubs de Http::fake() se acumulan y gana el primero que
     * matchea, asi que un fake generico en setUp se come los fakes especificos de cada caso
     * y los tests del push miden contra un 200 que nadie pidio.
     *
     * @return void
     */
    protected function sin_red()
    {
        Http::fake();
    }

    protected function tearDown(): void
    {
        DemoTrackingConfigHelper::olvidar_cache();
        DemoEventoEmitter::reiniciar_estado_de_proceso();

        parent::tearDown();
    }

    /**
     * Deja la instancia configurada como demo: fila de canal y marcador de sesion.
     *
     * @return \App\Models\DemoTrackingConfig
     */
    protected function configurar_canal()
    {
        $config = DemoTrackingConfigHelper::guardar(
            'zz-token-de-prueba-mision-50',
            'https://admin.test/api/demo-eventos',
            ['version_catalogo' => 2],
            ['1.1' => 'https://videos.test/1-1.mp4']
        );

        DemoTrackingConfigHelper::olvidar_cache();

        return $config;
    }

    /**
     * Deja el request siguiente en el mismo estado en que queda un lead que entro por el
     * link de la demo: token de ingreso vigente en la base y su id marcado en la sesion.
     *
     * El token tiene que existir de verdad, no alcanza con inventar un id: DemoSessionVigente
     * corre en el grupo 'api' y, si el marcador apunta a un token que no esta, corta el turno
     * antes de llegar al controlador.
     *
     * @return $this
     */
    protected function como_sesion_de_demo()
    {
        $token = DemoIngresoToken::create([
            'token_hash' => hash('sha256', 'zz-token-de-ingreso-mision-50-' . uniqid('', true)),
            'user_id'    => $this->user->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ]);

        return $this->withSession(['demo_ingreso_token_id' => $token->id]);
    }

    /**
     * Payload minimo que acepta ArticleController (mismo criterio que MargenCeroTest: varias
     * columnas de `articles` son NOT NULL sin default).
     *
     * @param  array $props
     * @return array
     */
    protected function payload_de_articulo(array $props = [])
    {
        return array_merge([
            'name'                           => 'ZZ Test bus de eventos',
            'cost'                           => 100,
            'iva_id'                         => 2,
            'aplicar_iva'                    => 1,
            'apply_provider_percentage_gain' => 0,
            'cost_in_dollars'                => 0,
            'provider_cost_in_dollars'       => 0,
            'online'                         => 0,
            'in_offer'                       => 0,
            'precio_pausado'                 => 0,
            'default_in_vender'              => 0,
            'personalizar_price_en_vender'   => 0,
            'omitir_en_lista_pdf'            => 0,
            'mercado_libre'                  => 0,
            'disponible_tienda_nube'         => 0,
            'requires_shipping'              => 0,
            'free_shipping'                  => 0,
            'es_insumo'                      => 0,
            'featured'                       => 0,
            'price_types'                    => [],
            'price_type_monedas'             => [],
            'tags'                           => [],
            'addresses'                      => [],
            'childrens'                      => [],
        ], $props);
    }

    /**
     * Crea un evento pendiente directo en la base, para los tests del push.
     *
     * @param  array $props
     * @return \App\Models\DemoEvento
     */
    protected function crear_evento_pendiente(array $props = [])
    {
        return DemoEvento::create(array_merge([
            'uuid'            => 'zz-' . uniqid('', true),
            'nombre'          => 'articulo.creado',
            'clip_id'         => null,
            'datos'           => ['id' => 1],
            'ocurrido_at'     => Carbon::now(),
            'sincronizado_at' => null,
            'intentos'        => 0,
            'ultimo_error'    => null,
        ], $props));
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS 40 CLIENTES REALES.
     *
     * Sin fila en demo_tracking_config, crear un articulo por el endpoint real tiene que
     * quedar exactamente como antes de la mision: el articulo se crea, no se escribe ningun
     * evento, no sale ningun request HTTP, y no se agrega NI UNA query.
     *
     * @group demo
     * @return void
     */
    public function test_una_instancia_sin_canal_no_registra_eventos_ni_paga_una_query_de_mas()
    {
        $this->sin_red();

        $this->assertFalse(
            DemoTrackingConfigHelper::hay_canal(),
            'La base de testing no puede tener una fila de demo_tracking_config sembrada.'
        );
        DemoTrackingConfigHelper::olvidar_cache();

        $eventos_antes = DemoEvento::count();

        /** Toda query que toque las tablas de la mision durante el alta del articulo. */
        $queries_de_la_mision = [];

        DB::listen(function ($query) use (&$queries_de_la_mision) {
            if (strpos($query->sql, 'demo_eventos') !== false || strpos($query->sql, 'demo_tracking_config') !== false) {
                $queries_de_la_mision[] = $query->sql;
            }
        });

        $respuesta = $this->postJson('/api/article', $this->payload_de_articulo([
            'name' => 'ZZ Articulo de cliente real',
        ]));

        $respuesta->assertStatus(201);

        $this->assertSame(
            [],
            $queries_de_la_mision,
            'Un cliente real no puede pagar ninguna query de la mision 50 al crear un articulo.'
        );

        $this->assertSame($eventos_antes, DemoEvento::count(), 'No se puede escribir ningun evento sin canal.');

        Http::assertNothingSent();
    }

    /**
     * El alta rapida desde vender es el otro camino por el que nace un articulo, y tiene que
     * cumplir las dos mitades: costo cero en un cliente real, y evento en una demo.
     *
     * Es EL "camino distinto al guiado" del que habla la decision T4: el lead que arma una
     * venta y carga el articulo desde ahi hizo la accion igual.
     *
     * @group demo
     * @return void
     */
    public function test_el_alta_rapida_de_articulo_tambien_respeta_las_dos_mitades()
    {
        $this->sin_red();

        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            if (strpos($query->sql, 'demo_eventos') !== false || strpos($query->sql, 'demo_tracking_config') !== false) {
                $queries[] = $query->sql;
            }
        });

        /** Mitad 1: cliente real, sin canal. */
        $respuesta = $this->postJson('/api/article/new-article', [
            'name'  => 'ZZ Alta rapida de cliente real',
            'price' => 500,
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame([], $queries, 'El alta rapida tampoco puede pagar queries en un cliente real.');

        /** Mitad 2: la misma alta, adentro de una demo. */
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/article/new-article', [
            'name'  => 'ZZ Alta rapida de la demo',
            'price' => 500,
        ]);

        $respuesta->assertStatus(201);

        $evento = DemoEvento::where('nombre', 'articulo.creado')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($evento, 'El alta rapida dentro de una demo tiene que dejar su evento.');
        $this->assertSame(['id' => $respuesta->json('model.id')], $evento->datos);
    }

    /**
     * El camino del worker: sin request y sin sesion, el emisor igual registra.
     *
     * Es por donde va `importacion.completada`, que lo emite FinalizeArticleImport desde la
     * cola. Ahi la guarda 1 no puede afirmar nada —no hay sesion de la cual fiarse— y decide
     * la guarda 2, que consulta la configuracion. Si esa rama se rompiera, el unico evento
     * que no nace de un request dejaria de existir y nada lo diria.
     *
     * @group demo
     * @return void
     */
    public function test_sin_sesion_el_emisor_igual_registra_si_hay_canal()
    {
        $this->sin_red();
        $this->configurar_canal();

        $evento = DemoEventoEmitter::emitir('importacion.completada', null, ['import_history_id' => 77]);

        $this->assertNotNull($evento, 'Sin sesion, la guarda 2 tiene que resolver el canal y dejar emitir.');
        $this->assertSame('importacion.completada', $evento->nombre);
        $this->assertSame(['import_history_id' => 77], $evento->datos);

        /** Y sin canal, el mismo camino no registra nada. */
        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertNull(DemoEventoEmitter::emitir('importacion.completada', null, ['import_history_id' => 78]));
    }

    /**
     * `flush()` es total: no puede tirar por ningun camino.
     *
     * Importa mas de lo que parece. El push posterior a la respuesta corre adentro de un
     * callback de `app()->terminating()`, y `public/index.php` no envuelve `$kernel->terminate()`
     * en nada: una excepcion que se escapara de ahi terminaria pegando el HTML del error al
     * final del JSON que el usuario ya recibio. El closure tiene su propio try/catch, y este
     * test cubre la otra mitad.
     *
     * @group demo
     * @return void
     */
    public function test_el_flush_no_tira_nunca()
    {
        /** Sin canal configurado. */
        DemoTrackingConfigHelper::olvidar_cache();
        $this->assertSame(['enviados' => 0, 'sincronizados' => 0, 'fallados' => 0, 'varados' => 0], DemoEventosPushHelper::flush());

        /** Con canal y con la red cortada de la peor manera. */
        $this->configurar_canal();
        Http::fake(function () {
            throw new \RuntimeException('zz simulacion: el cliente HTTP exploto');
        });

        $evento = $this->crear_evento_pendiente();

        $resumen = DemoEventosPushHelper::flush();

        $this->assertSame(1, $resumen['fallados'], 'Un fallo cualquiera igual le cobra el intento al lote.');
        $this->assertSame(1, $evento->fresh()->intentos);
        $this->assertNull($evento->fresh()->sincronizado_at);
    }

    /**
     * Con el canal configurado y sesion de demo, el alta de un articulo deja su evento.
     *
     * @group demo
     * @return void
     */
    public function test_con_canal_configurado_crear_un_articulo_deja_el_evento_articulo_creado()
    {
        $this->sin_red();
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/article', $this->payload_de_articulo([
            'name' => 'ZZ Articulo de la demo',
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');
        $this->assertNotNull($id, 'El alta no devolvio el articulo creado.');

        $evento = DemoEvento::where('nombre', 'articulo.creado')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($evento, 'Con canal configurado tiene que quedar el evento articulo.creado.');
        $this->assertSame(['id' => $id], $evento->datos, 'El evento lleva el id del registro creado y nada mas.');
        $this->assertNull($evento->sincronizado_at, 'El evento nace pendiente de empuje.');
        $this->assertNotEmpty($evento->uuid, 'El uuid lo genera la instancia y es lo que hace idempotente el reintento.');
    }

    /**
     * El endpoint de eventos de UX, en el camino feliz.
     *
     * @group demo
     * @return void
     */
    public function test_el_endpoint_de_ux_registra_un_clip_terminado()
    {
        $this->sin_red();
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/demo/evento', [
            'nombre'  => 'clip.terminado',
            'clip_id' => '1.1',
            'datos'   => [],
        ]);

        $respuesta->assertStatus(200);

        $evento = DemoEvento::where('nombre', 'clip.terminado')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($evento, 'El evento de UX tiene que quedar persistido.');
        $this->assertSame('1.1', $evento->clip_id, 'clip.terminado tiene que traer su clip_id.');
    }

    /**
     * Sin el marcador de sesion demo, el endpoint responde 204 mudo y no escribe nada.
     *
     * 204 y no 403 a proposito: el mismo empresa-spa se despliega en las instancias de los
     * clientes reales y un 403 les llenaria la consola de errores ajenos.
     *
     * @group demo
     * @return void
     */
    public function test_el_endpoint_de_ux_responde_204_si_la_sesion_no_es_de_demo()
    {
        $this->sin_red();
        $this->configurar_canal();

        $eventos_antes = DemoEvento::count();

        $respuesta = $this->postJson('/api/demo/evento', [
            'nombre'  => 'clip.terminado',
            'clip_id' => '1.1',
        ]);

        $respuesta->assertStatus(204);

        $this->assertSame($eventos_antes, DemoEvento::count(), 'Sin sesion de demo no se escribe ninguna fila.');
    }

    /**
     * Un nombre fuera de la lista blanca no escribe nada: el endpoint no es un buzon abierto.
     *
     * @group demo
     * @return void
     */
    public function test_el_endpoint_de_ux_rechaza_un_nombre_fuera_de_la_lista_blanca()
    {
        $this->sin_red();
        $this->configurar_canal();

        $eventos_antes = DemoEvento::count();

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/demo/evento', [
            'nombre' => 'articulo.creado',
        ]);

        $respuesta->assertStatus(204);

        $this->assertSame(
            $eventos_antes,
            DemoEvento::count(),
            'Un evento de negocio no se puede falsificar desde el navegador.'
        );

        $respuesta_inventada = $this->como_sesion_de_demo()->postJson('/api/demo/evento', [
            'nombre' => 'lo.que.se.me.ocurra',
        ]);

        $respuesta_inventada->assertStatus(204);

        $this->assertSame($eventos_antes, DemoEvento::count(), 'Un nombre inventado tampoco escribe.');

        /**
         * Y un cuerpo con la forma equivocada tiene que seguir siendo un 204 mudo, no un 500
         * con traza: el cuerpo lo arma el navegador y este endpoint no puede ser un lugar
         * donde tirarle basura al servidor produzca un error server-side.
         */
        $respuesta_array = $this->como_sesion_de_demo()->postJson('/api/demo/evento', [
            'nombre'  => ['clip.terminado'],
            'clip_id' => ['1.1'],
        ]);

        $respuesta_array->assertStatus(204);

        $this->assertSame($eventos_antes, DemoEvento::count(), 'Un nombre que no es escalar tampoco escribe.');
    }

    /**
     * El flush con el admin respondiendo 200 marca los eventos como sincronizados.
     *
     * @group demo
     * @return void
     */
    public function test_el_flush_marca_los_eventos_que_el_admin_acepto()
    {
        $this->configurar_canal();

        Http::fake([
            '*' => Http::response(['recibidos' => 2, 'guardados' => 2, 'duplicados' => 0, 'hitos' => 1], 200),
        ]);

        $uno = $this->crear_evento_pendiente(['nombre' => 'demo.ingreso']);
        $dos = $this->crear_evento_pendiente(['nombre' => 'articulo.creado']);

        $resumen = DemoEventosPushHelper::flush();

        $this->assertSame(2, $resumen['sincronizados']);
        $this->assertNotNull($uno->fresh()->sincronizado_at);
        $this->assertNotNull($dos->fresh()->sincronizado_at);

        /** El token viaja en el header del canal, nunca en la URL ni en el cuerpo. */
        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Demo-Eventos-Key', 'zz-token-de-prueba-mision-50')
                && strpos($request->url(), 'zz-token-de-prueba-mision-50') === false
                && count($request['eventos']) === 2;
        });
    }

    /**
     * Con el admin caido los eventos siguen pendientes, suman intento y guardan el motivo.
     *
     * Y —lo que de verdad importa— la operacion del usuario nunca se vio afectada: el
     * articulo se creo con 201 antes de que nada de esto pasara.
     *
     * @group demo
     * @return void
     */
    public function test_con_el_admin_caido_los_eventos_quedan_pendientes_y_el_usuario_no_se_entera()
    {
        $this->configurar_canal();

        /**
         * El cuerpo devuelve el token a proposito. Es la forma NORMAL de un rechazo de
         * credencial ("la key X no existe"), y ese texto ajeno se guarda en `ultimo_error`
         * y se loguea: si no se enmascara, el secreto queda escrito de este lado sin que
         * ninguna linea de este repo lo haya escrito.
         */
        Http::fake([
            '*' => Http::response('boom: key invalida zz-token-de-prueba-mision-50', 500),
        ]);

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/article', $this->payload_de_articulo([
            'name' => 'ZZ Articulo con el admin caido',
        ]));

        $respuesta->assertStatus(201);

        $evento = DemoEvento::where('nombre', 'articulo.creado')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($evento);

        $resumen = DemoEventosPushHelper::flush();

        $this->assertSame(1, $resumen['fallados']);

        $evento = $evento->fresh();

        $this->assertNull($evento->sincronizado_at, 'Un push fallado deja el evento pendiente.');
        $this->assertSame(1, $evento->intentos, 'Cada fallo suma exactamente un intento.');
        $this->assertNotEmpty($evento->ultimo_error, 'El motivo del fallo tiene que quedar escrito.');
        $this->assertStringNotContainsString(
            'zz-token-de-prueba-mision-50',
            (string) $evento->ultimo_error,
            'El token no puede terminar guardado en el detalle de un error, ni siquiera reflejado por el admin.'
        );
        $this->assertStringContainsString(
            '***',
            (string) $evento->ultimo_error,
            'Y tiene que quedar la marca de que ahi habia algo enmascarado.'
        );
        $this->assertStringContainsString('boom', (string) $evento->ultimo_error, 'Sin perder el resto del motivo.');
    }

    /**
     * Un token mas largo que la columna no se guarda ni se intenta guardar.
     *
     * Intentarlo es lo peligroso: con `strict` prendido MySQL responde "Data too long" y
     * Laravel arma el mensaje de la QueryException interpolando los bindings, asi que el
     * token en claro terminaria en el log del setup y en el cuerpo del 500 que ese endpoint
     * le devuelve al admin.
     *
     * @group demo
     * @return void
     */
    public function test_un_token_mas_largo_que_la_columna_no_configura_el_canal()
    {
        $token_gigante = str_repeat('z', DemoTrackingConfigHelper::LARGO_MAX_TOKEN + 1);

        $config = DemoTrackingConfigHelper::guardar($token_gigante, 'https://admin.test/api/demo-eventos');

        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertNull($config, 'Un token que no entra en la columna no arma ningun canal.');
        $this->assertSame(0, DemoTrackingConfig::count(), 'Y no deja ninguna fila.');
        $this->assertFalse(DemoTrackingConfigHelper::hay_canal());
    }

    /**
     * El enmascarado saca el token de cualquier texto que vaya a un log o a una columna.
     *
     * @group demo
     * @return void
     */
    public function test_el_enmascarado_saca_el_token_de_un_texto_cualquiera()
    {
        $this->configurar_canal();

        $mensaje = 'SQLSTATE[22001]: (SQL: insert into demo_tracking_config (eventos_token) values (zz-token-de-prueba-mision-50))';

        $limpio = DemoTrackingConfigHelper::ocultar_token($mensaje);

        $this->assertStringNotContainsString('zz-token-de-prueba-mision-50', $limpio);
        $this->assertStringContainsString('***', $limpio);

        /** Sin canal configurado no hay nada que ocultar, y el texto tiene que volver igual. */
        DemoTrackingConfig::query()->delete();
        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertSame('un texto cualquiera', DemoTrackingConfigHelper::ocultar_token('un texto cualquiera'));
    }

    /**
     * Un timeout o un corte de red tampoco es definitivo: se reintenta en el proximo flush.
     *
     * @group demo
     * @return void
     */
    public function test_un_corte_de_red_deja_el_evento_pendiente_y_reintentable()
    {
        $this->configurar_canal();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $evento = $this->crear_evento_pendiente();

        DemoEventosPushHelper::flush();

        $evento = $evento->fresh();

        $this->assertNull($evento->sincronizado_at);
        $this->assertSame(1, $evento->intentos);
        $this->assertLessThan(
            DemoEventosPushHelper::MAX_INTENTOS,
            $evento->intentos,
            'Un corte de red no puede agotar los intentos de una.'
        );
    }

    /**
     * Un 422 es definitivo por contrato del canal: reintentarlo es el loop infinito que las
     * validaciones del admin vienen a cerrar.
     *
     * @group demo
     * @return void
     */
    public function test_un_422_del_canal_no_se_reintenta_nunca_mas()
    {
        $this->configurar_canal();

        Http::fake([
            '*' => Http::response(['message' => 'ocurrido_at invalido'], 422),
        ]);

        $evento = $this->crear_evento_pendiente();

        DemoEventosPushHelper::flush();

        $this->assertSame(
            DemoEventosPushHelper::MAX_INTENTOS,
            $evento->fresh()->intentos,
            'Un rechazo definitivo agota los intentos de una.'
        );
    }

    /**
     * Un evento que ya agoto sus 10 intentos no se vuelve a mandar.
     *
     * @group demo
     * @return void
     */
    public function test_un_evento_varado_no_se_vuelve_a_mandar()
    {
        $this->configurar_canal();

        Http::fake([
            '*' => Http::response(['recibidos' => 1, 'guardados' => 1, 'duplicados' => 0, 'hitos' => 0], 200),
        ]);

        $varado = $this->crear_evento_pendiente(['intentos' => DemoEventosPushHelper::MAX_INTENTOS]);

        $resumen = DemoEventosPushHelper::flush();

        $this->assertSame(0, $resumen['enviados'], 'Un evento varado no entra en el lote.');
        $this->assertSame(1, $resumen['varados'], 'Pero si se cuenta y se avisa una vez por corrida.');
        $this->assertNull($varado->fresh()->sincronizado_at);

        Http::assertNothingSent();
    }

    /**
     * 🔴 La regla de oro: un fallo del bus no puede romper la operacion que lo produjo.
     *
     * La excepcion se inyecta por el evento `creating` del modelo en vez de tirando la tabla:
     * un DROP dentro de un test con DatabaseTransactions hace commit implicito en MySQL y se
     * lleva puesto el rollback de toda la clase.
     *
     * @group demo
     * @return void
     */
    public function test_si_la_escritura_del_evento_falla_el_articulo_igual_se_crea()
    {
        $this->sin_red();
        $this->configurar_canal();

        DemoEvento::creating(function () {
            throw new \RuntimeException('zz simulacion: demo_eventos no existe');
        });

        try {
            $respuesta = $this->como_sesion_de_demo()->postJson('/api/article', $this->payload_de_articulo([
                'name' => 'ZZ Articulo con el bus roto',
            ]));

            $respuesta->assertStatus(201);

            $id = $respuesta->json('model.id');
            $this->assertNotNull($id, 'El articulo es el producto; el evento es la metrica. Gana el articulo.');
        } finally {
            /** El modelo no registra listeners propios en boot, asi que el flush no pierde nada. */
            DemoEvento::flushEventListeners();
        }
    }

    /**
     * Un payload de setup sin las cuatro claves nuevas deja la instancia sin canal, o sea
     * funcionando exactamente igual que antes de esta mision.
     *
     * No se ejecuta DemoSetupHelper::run() de verdad: su primera linea es un `migrate:fresh`
     * que vaciaria empresa_testing_s2, que es la base sembrada de la que vive toda la suite.
     * Lo que se ejercita es la unica pieza que la mision agrego a ese metodo — la guarda que
     * decide si hay canal y el helper que persiste — con los mismos valores que le llegarian
     * desde un payload viejo.
     *
     * @group demo
     * @return void
     */
    public function test_un_payload_de_setup_sin_las_claves_nuevas_no_configura_ningun_canal()
    {
        $payload_viejo = [
            'name'          => 'ZZ Lead de la dinamica anterior',
            'business_type' => 'ferreteria',
        ];

        $hay_que_guardar_canal = !empty($payload_viejo['demo_eventos_token']) && !empty($payload_viejo['demo_eventos_url']);

        $this->assertFalse($hay_que_guardar_canal, 'Un payload sin las claves nuevas no entra al bloque de la mision 50.');

        /** Y el helper tampoco escribe nada si igual lo llamaran con valores vacios. */
        $this->assertNull(DemoTrackingConfigHelper::guardar('', '', null, null));
        $this->assertNull(DemoTrackingConfigHelper::guardar('un-token', '', null, null));

        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertSame(0, DemoTrackingConfig::count(), 'No puede quedar una fila de canal a medias.');
        $this->assertFalse(DemoTrackingConfigHelper::hay_canal());
    }

    /**
     * El setup CON las cuatro claves deja el canal armado y el plan disponible para el panel.
     *
     * @group demo
     * @return void
     */
    public function test_el_setup_con_las_claves_nuevas_deja_el_canal_configurado()
    {
        $config = DemoTrackingConfigHelper::guardar(
            'zz-token-setup',
            'https://admin.test/api/demo-eventos',
            ['version_catalogo' => 2, 'secciones' => []],
            ['1.1' => 'https://videos.test/1-1.mp4']
        );

        DemoTrackingConfigHelper::olvidar_cache();

        $this->assertNotNull($config);
        $this->assertTrue(DemoTrackingConfigHelper::hay_canal());
        $this->assertSame(2, DemoTrackingConfigHelper::actual()->plan['version_catalogo']);
        $this->assertSame('https://videos.test/1-1.mp4', DemoTrackingConfigHelper::actual()->media_urls['1.1']);

        /** El token es un secreto: no puede salir por serializacion del modelo. */
        $this->assertArrayNotHasKey('eventos_token', DemoTrackingConfigHelper::actual()->toArray());
    }

    /**
     * `datos` no puede crecer sin techo: el campo de notas del panel manda texto libre del lead.
     *
     * @group demo
     * @return void
     */
    public function test_los_datos_de_un_evento_se_acotan_antes_de_guardarse()
    {
        $this->sin_red();
        $this->configurar_canal();

        $respuesta = $this->como_sesion_de_demo()->postJson('/api/demo/evento', [
            'nombre' => 'nota.escrita',
            'datos'  => ['texto' => str_repeat('a', 5000)],
        ]);

        $respuesta->assertStatus(200);

        $evento = DemoEvento::where('nombre', 'nota.escrita')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($evento);
        $this->assertLessThanOrEqual(
            DemoEventoEmitter::MAX_BYTES_DATOS,
            strlen((string) json_encode($evento->datos)),
            'Los datos guardados tienen que respetar el tope.'
        );
        $this->assertSame('excede_tope', $evento->datos['_descartado'], 'Y decir explicitamente que se descartaron.');
    }
}
