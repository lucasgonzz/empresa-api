<?php

namespace Tests\Feature\ActividadDeClientes;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\ActividadDeClientes\ResumenIaActividadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión actividad-de-clientes-y-oferta-por-whatsapp — unidad 3: el endpoint del ERP y la lectura
 * de la IA.
 *
 * Los cinco modos de falla que se miden acá, que son los que hacen daño de verdad:
 *
 *   1. Que la pantalla de un comercio muestre la actividad de un cliente de OTRO. La tenencia es lo
 *      único que no puede fallar, y falla en silencio: devuelve un 200 con datos plausibles.
 *   2. Que la puerta quede abierta sin la extensión o sin autenticar.
 *   3. 🔴 Que se le pague una llamada a Anthropic por un cliente que no hizo NADA. El párrafo que
 *      volvería no podría decir más que "no hizo nada", que es lo que la pantalla ya muestra sola.
 *   4. Que el gasto de esa llamada no quede imputado, o quede mezclado con el de otro proceso: sin
 *      la fila de `ai_token_usages` con su `proceso` propio, el costo de esta pantalla no se puede
 *      separar del resto al mirar los números.
 *   5. Que el bloque de DATOS del prompt se contamine con las instrucciones de redacción. Es la
 *      separación que el repo ya blinda en ofertas y que se mantiene por el mismo motivo de fondo.
 *
 * 🔴 NINGÚN TEST DE ESTE ARCHIVO PUEDE SALIR A LA RED. El `.env.testing` tiene una clave REAL de
 * Anthropic y cada llamada se paga; peor todavía, el servicio se traga el error de conexión, así que
 * un test que sale a la red igual da OK. Es una clase de error ya cometida en este repo. Dos
 * defensas, y las dos estructurales:
 *
 *   - El setUp() deja `services.anthropic.api_key` en null, así que el endpoint del resumen corta
 *     con un 422 ANTES de armar el cliente HTTP.
 *   - La única forma de que un test de acá tenga clave es fakear_anthropic(), que registra el
 *     Http::fake() PRIMERO y recién después setea la clave. No hay ningún camino en el archivo que
 *     ponga una clave sin un stub ya puesto.
 *
 * El comercio es el usuario 500 (el que resuelve config('app.USER_ID') en testing) porque el
 * endpoint se gatea con una extensión, que es una relación de un usuario real. Pero los datos que se
 * miden son PROPIOS del test —su cliente, su comprador, su artículo y sus eventos, creados adentro
 * de la transacción—: no dependen de que el sembrador de la unidad 2 haya corrido.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Endpoint_y_resumen_ia_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea las dos rutas. */
    const SLUG = 'tracking_buyers';

    /** La ruta de los números. */
    const RUTA = 'api/actividad-de-clientes';

    /** La ruta de la lectura en criollo. */
    const RUTA_RESUMEN = 'api/actividad-de-clientes/resumen';

    /** @var User El comercio de los tests */
    protected $user;

    /** @var User Un segundo comercio, para medir el aislamiento */
    protected $otro_comercio;

    /** @var ExtencionEmpresa */
    protected $extencion;

    /** @var Client */
    protected $cliente;

    /** @var Buyer */
    protected $comprador;

    /** @var Article */
    protected $articulo;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // 🔴 Sin esto los tests salen a la API real de Anthropic: la clave del .env.testing es REAL.
        config(['services.anthropic.api_key' => null]);

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable y fuera de
        // Model::unguarded() (que solo aplica a db:seed) el create falla.
        $this->extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$this->extencion) {
            $this->extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Tracking de compradores',
            ]);
        }

        $this->user->extencions()->syncWithoutDetaching([$this->extencion->id]);
        $this->user->load('extencions');

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio actividad U3',
            'email'    => 'actividad-u3-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->cliente   = $this->cliente_de('Ferreteria del endpoint');
        $this->comprador = $this->comprador_de($this->cliente);
        $this->articulo  = $this->articulo_de('Cable HDMI del endpoint');
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * @param  string    $nombre
     * @param  User|null $owner
     * @return Client
     */
    protected function cliente_de($nombre, $owner = null)
    {
        return Client::create([
            'name'    => $nombre,
            'user_id' => is_null($owner) ? $this->user->id : $owner->id,
        ]);
    }

    /**
     * @param  Client|null $cliente Sin cliente queda el caso NORMAL de la tienda: un comprador suelto
     * @param  User|null   $owner
     * @return Buyer
     */
    protected function comprador_de($cliente = null, $owner = null)
    {
        return Buyer::create([
            'name'                    => 'Comprador ' . uniqid(),
            'email'                   => 'buyer-u3-' . uniqid() . '@test.local',
            'user_id'                 => is_null($owner) ? $this->user->id : $owner->id,
            'comercio_city_client_id' => is_null($cliente) ? null : $cliente->id,
        ]);
    }

    /**
     * @param  string    $nombre
     * @param  User|null $owner
     * @return Article
     */
    protected function articulo_de($nombre, $owner = null)
    {
        return Article::create([
            'name'    => $nombre,
            'user_id' => is_null($owner) ? $this->user->id : $owner->id,
        ]);
    }

    /**
     * Un evento crudo de la tienda. Lo que no se pasa queda en su default.
     *
     * @param  array $datos
     * @return void
     */
    protected function evento(array $datos)
    {
        DB::table('buyer_tracking_events')->insert(array_merge([
            'user_id'       => $this->user->id,
            'buyer_id'      => $this->comprador->id,
            'visitor_id'    => '33333333-3333-4333-8333-333333333333',
            'session_id'    => '44444444-4444-4444-8444-444444444444',
            'event_type'    => 'product_view',
            'article_id'    => null,
            'search_term'   => null,
            'results_count' => null,
            'quantity'      => null,
            'amount'        => null,
            'dwell_ms'      => null,
            'order_id'      => null,
            // 🔴 La ventana se mide por occurred_at, nunca por created_at.
            'occurred_at'   => now()->subDays(1),
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $datos));
    }

    /**
     * La escena mínima con la que la respuesta tiene TODAS las listas del contrato no vacías: si
     * alguna quedara vacía, el assertJsonStructure no miraría sus claves de adentro y el test
     * pasaría sin verificar la mitad del shape.
     *
     * @return void
     */
    protected function sembrar_escena()
    {
        $this->evento(['article_id' => $this->articulo->id, 'dwell_ms' => 48000, 'occurred_at' => now()->subDays(1)]);
        $this->evento(['article_id' => $this->articulo->id, 'dwell_ms' => 12000, 'occurred_at' => now()->subDays(2)]);
        // dwell_ms en null a propósito: SUM() de puros nulls devuelve NULL y el servicio lo castea.
        $this->evento(['article_id' => $this->articulo->id, 'occurred_at' => now()->subDays(3)]);

        $this->evento([
            'event_type'    => 'search',
            'search_term'   => 'taladro percutor',
            'results_count' => 0,
            'occurred_at'   => now()->subDays(2),
        ]);
        $this->evento([
            'event_type'    => 'search',
            'search_term'   => 'taladro percutor',
            'results_count' => 0,
            'occurred_at'   => now()->subDays(2),
        ]);

        $this->evento([
            'event_type'  => 'cart_add',
            'article_id'  => $this->articulo->id,
            'quantity'    => 1,
            'occurred_at' => now()->subDays(1),
        ]);

        $this->evento([
            'event_type'  => 'checkout_complete',
            'amount'      => 15400.50,
            'order_id'    => 9001,
            'occurred_at' => now()->subDays(1),
        ]);
    }

    /**
     * @return void
     */
    protected function autenticar()
    {
        $this->actingAs($this->user, 'web');
    }

    /**
     * 🔴 EL ÚNICO LUGAR DEL ARCHIVO QUE PONE UNA CLAVE DE ANTHROPIC, y registra el stub ANTES de
     * ponerla. El orden no es estético: si la clave se seteara primero y el fake después, cualquier
     * error en el medio dejaría al test con clave real y sin stub, o sea saliendo a la red y
     * pagando.
     *
     * @param  array $cuerpo Lo que contesta el stub
     * @param  int   $status
     * @return void
     */
    protected function fakear_anthropic(array $cuerpo, $status = 200)
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($cuerpo, $status),
        ]);

        config(['services.anthropic.api_key' => 'clave-de-prueba']);
    }

    /**
     * La respuesta típica de Anthropic, con su bloque `usage`.
     *
     * @param  string $texto
     * @return array
     */
    protected function respuesta_de_anthropic($texto = 'Estuvo mirando bastante el cable HDMI y no lo compro.')
    {
        return [
            'model'   => 'claude-modelo-de-prueba',
            'content' => [
                ['type' => 'text', 'text' => $texto],
            ],
            'usage'   => [
                'input_tokens'                => 812,
                'output_tokens'               => 96,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------
    // El GET
    // ------------------------------------------------------------------------------------------

    /**
     * El shape completo de §3.2, clave por clave. La SPA está escrita contra este contrato: una
     * clave que falte no rompe el endpoint, rompe la pantalla del otro lado.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_endpoint_devuelve_la_actividad_del_cliente()
    {
        $this->autenticar();
        $this->sembrar_escena();

        $response = $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id . '&periodo=30');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'actividad' => [
                'fuente',
                'periodo',
                'desde',
                'hasta',
                'hay_datos',
                'cliente'     => ['client_id', 'nombre'],
                'compradores' => ['*' => ['buyer_id', 'nombre']],
                'totales'     => [
                    'vistas',
                    'articulos_distintos',
                    'tiempo_total_segundos',
                    'busquedas',
                    'busquedas_sin_resultado',
                    'agregados_al_carrito',
                    'quitados_del_carrito',
                    'checkouts_empezados',
                    'compras',
                    'compras_sin_articulo',
                    'monto_comprado',
                    'ultima_actividad',
                ],
                'articulos' => ['*' => [
                    'article_id', 'nombre', 'vistas', 'tiempo_segundos', 'agregados_al_carrito', 'comprados', 'ultima_vez',
                ]],
                'busquedas' => ['*' => [
                    'termino', 'veces', 'resultados', 'sin_resultado', 'ultima_vez',
                ]],
                'linea_de_tiempo' => ['*' => [
                    'id', 'tipo', 'tipo_texto', 'article_id', 'articulo', 'termino',
                    'resultados', 'cantidad', 'monto', 'segundos', 'cuando',
                ]],
            ],
        ]);

        $actividad = $response->json('actividad');

        $this->assertSame('eventos', $actividad['fuente'], 'Con periodo 30 se lee de los eventos crudos.');
        $this->assertTrue($actividad['hay_datos']);
        $this->assertSame($this->cliente->id, $actividad['cliente']['client_id']);
        $this->assertSame(3, $actividad['totales']['vistas']);
        $this->assertSame(1, $actividad['totales']['articulos_distintos']);
        $this->assertSame(60, $actividad['totales']['tiempo_total_segundos'], '48s + 12s + un null que vale cero.');
        $this->assertSame(2, $actividad['totales']['busquedas']);
        $this->assertSame(2, $actividad['totales']['busquedas_sin_resultado']);
        $this->assertSame(1, $actividad['totales']['agregados_al_carrito']);
        $this->assertSame(1, $actividad['totales']['compras']);
        $this->assertEquals(15400.5, $actividad['totales']['monto_comprado']);
        $this->assertTrue($actividad['busquedas'][0]['sin_resultado'], 'results_count = 0 es "busco y no encontro nada".');

        /*
         * 🔴 El checkout de esta escena viene SIN `article_id` —como puede venir de la tienda de
         * verdad— y el contrato exige que eso se pueda ver. Sin `compras_sin_articulo`, el
         * `comprados = 0` del artículo se lee como "no lo compró" cuando lo que hay es "compró algo
         * y no sabemos qué": es la clave que vuelve honesto al detalle.
         */
        $this->assertSame(
            1,
            $actividad['totales']['compras_sin_articulo'],
            'la compra que llegó sin article_id tiene que informarse, no desaparecer.'
        );
        $this->assertSame(
            0,
            $actividad['articulos'][0]['comprados'],
            'de este artículo no hay una sola compra atribuida: 0, y con compras_sin_articulo al lado para poder leerlo.'
        );
    }

    /**
     * 🔴 "QUÉ COMPRÓ" ES UNA DE LAS CINCO SEÑALES QUE SE PIDIERON, Y LLEGA POR ARTÍCULO.
     *
     * Se mide la escena mixta, que es la única que distingue los tres estados posibles: un artículo
     * con compra atribuida, uno sin ninguna, y compras que la tienda no pudo atribuir a ninguno.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_endpoint_informa_que_articulos_compro_y_cuantas_compras_quedaron_sin_atribuir()
    {
        $this->autenticar();

        $otro = $this->articulo_de('Amoladora comprada');

        $this->evento(['article_id' => $this->articulo->id, 'dwell_ms' => 30000, 'occurred_at' => now()->subDays(3)]);
        $this->evento(['article_id' => $otro->id, 'dwell_ms' => 30000, 'occurred_at' => now()->subDays(3)]);

        $this->evento([
            'event_type'  => 'checkout_complete',
            'article_id'  => $otro->id,
            'amount'      => 8000,
            'occurred_at' => now()->subDays(2),
        ]);

        // La compra que la tienda no atribuyó a ningún artículo.
        $this->evento([
            'event_type'  => 'checkout_complete',
            'article_id'  => null,
            'amount'      => 1200,
            'occurred_at' => now()->subDays(1),
        ]);

        $actividad = $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id . '&periodo=30')
            ->assertStatus(200)
            ->json('actividad');

        $this->assertSame(2, $actividad['totales']['compras']);
        $this->assertSame(1, $actividad['totales']['compras_sin_articulo']);

        $por_id = [];

        foreach ($actividad['articulos'] as $articulo) {
            $por_id[$articulo['article_id']] = $articulo;
        }

        $this->assertSame(1, $por_id[$otro->id]['comprados']);
        $this->assertSame(0, $por_id[$this->articulo->id]['comprados']);
        $this->assertSame(30, $por_id[$otro->id]['tiempo_segundos'], 'la compra no suma tiempo mirando el artículo.');
    }

    /**
     * 🔴 §4bis punto 4: el servicio resuelve el cliente desde los COMPRADORES, así que un cliente sin
     * ninguno vuelve con `cliente = null` aunque se haya entrado por client_id. El controller sabe
     * por dónde se entró y lo pisa. Sin ese pisado la pantalla no puede dibujar el cartel de "este
     * cliente no tiene ningún comprador asociado": no sabría de quién está hablando.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_cliente_sin_compradores_igual_dice_de_quien_esta_hablando()
    {
        $this->autenticar();

        $huerfano = $this->cliente_de('Cliente sin comprador de la tienda');

        $response = $this->getJson(self::RUTA . '?client_id=' . $huerfano->id);

        $response->assertStatus(200);

        $actividad = $response->json('actividad');

        $this->assertFalse($actividad['hay_datos']);
        $this->assertSame([], $actividad['compradores']);
        $this->assertNotNull(
            $actividad['cliente'],
            'Se entro por client_id: el endpoint tiene que decir de quien esta hablando aunque no haya compradores.'
        );
        $this->assertSame($huerfano->id, $actividad['cliente']['client_id']);
        $this->assertSame('Cliente sin comprador de la tienda', $actividad['cliente']['nombre']);
    }

    /**
     * La otra puerta: por `buyer_id` se ve al comprador que NO tiene cliente asociado, que es el
     * caso normal de la tienda y el que desde Clientes del ERP no se puede alcanzar.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_comprador_sin_cliente_asociado_se_ve_por_buyer_id_y_su_cliente_viene_null()
    {
        $this->autenticar();

        $suelto = $this->comprador_de(null);

        DB::table('buyer_tracking_events')->insert([
            'user_id'     => $this->user->id,
            'buyer_id'    => $suelto->id,
            'visitor_id'  => '55555555-5555-4555-8555-555555555555',
            'session_id'  => '66666666-6666-4666-8666-666666666666',
            'event_type'  => 'product_view',
            'article_id'  => $this->articulo->id,
            'dwell_ms'    => 5000,
            'occurred_at' => now()->subDays(1),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $response = $this->getJson(self::RUTA . '?buyer_id=' . $suelto->id);

        $response->assertStatus(200);

        $actividad = $response->json('actividad');

        $this->assertNull($actividad['cliente'], 'El comprador no tiene cliente asociado: no hay a quien nombrar.');
        $this->assertTrue($actividad['hay_datos']);
        $this->assertSame(1, $actividad['totales']['vistas']);
        $this->assertCount(1, $actividad['compradores']);
        $this->assertSame($suelto->id, $actividad['compradores'][0]['buyer_id']);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function sin_la_extencion_el_endpoint_devuelve_403()
    {
        $this->autenticar();

        $this->user->extencions()->detach($this->extencion->id);
        $this->user->load('extencions');

        $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id)->assertStatus(403);
        $this->postJson(self::RUTA_RESUMEN, ['client_id' => $this->cliente->id])->assertStatus(403);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function sin_autenticar_devuelve_401()
    {
        // Sin autenticar() a propósito: no hay sesión.
        $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id)->assertStatus(401);
        $this->postJson(self::RUTA_RESUMEN, ['client_id' => $this->cliente->id])->assertStatus(401);
    }

    /**
     * 🔴 404 y NUNCA 403: quién existe y quién no en la lista de clientes es información del
     * comercio, y un 403 contra un id ajeno confirmaría que ese cliente existe en otra cuenta.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_client_id_de_otro_comercio_devuelve_404()
    {
        $this->autenticar();

        $ajeno = $this->cliente_de('Cliente del otro comercio', $this->otro_comercio);

        $this->getJson(self::RUTA . '?client_id=' . $ajeno->id)->assertStatus(404);
        $this->postJson(self::RUTA_RESUMEN, ['client_id' => $ajeno->id])->assertStatus(404);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function un_buyer_id_de_otro_comercio_devuelve_404()
    {
        $this->autenticar();

        $ajeno = $this->comprador_de(null, $this->otro_comercio);

        $this->getJson(self::RUTA . '?buyer_id=' . $ajeno->id)->assertStatus(404);
        $this->postJson(self::RUTA_RESUMEN, ['buyer_id' => $ajeno->id])->assertStatus(404);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function sin_client_id_ni_buyer_id_devuelve_422()
    {
        $this->autenticar();

        $this->getJson(self::RUTA)->assertStatus(422);
        $this->postJson(self::RUTA_RESUMEN, [])->assertStatus(422);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function con_los_dos_a_la_vez_devuelve_422()
    {
        $this->autenticar();

        $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id . '&buyer_id=' . $this->comprador->id)
            ->assertStatus(422);

        $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'buyer_id'  => $this->comprador->id,
        ])->assertStatus(422);
    }

    /**
     * 🔴 UNA SOLA VALIDACIÓN PARA LOS DOS MÉTODOS: por eso cada caso se prueba contra el GET Y contra
     * el POST. Dos validaciones distintas del mismo contrato es cómo se llega a que el GET acepte
     * algo que el POST rechaza, y ahí la pantalla dibuja una actividad que después no puede resumir.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_periodo_fuera_de_la_lista_blanca_devuelve_422()
    {
        $this->autenticar();

        foreach ([365, 1, -1, 'abc', 30.5] as $periodo) {
            $this->getJson(self::RUTA . '?client_id=' . $this->cliente->id . '&periodo=' . $periodo)
                ->assertStatus(422, 'periodo: ' . var_export($periodo, true));

            $this->postJson(self::RUTA_RESUMEN, [
                'client_id' => $this->cliente->id,
                'periodo'   => $periodo,
            ])->assertStatus(422, 'periodo: ' . var_export($periodo, true));
        }
    }

    // ------------------------------------------------------------------------------------------
    // El resumen
    // ------------------------------------------------------------------------------------------

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function el_resumen_devuelve_texto_plano_y_no_persiste_nada()
    {
        $this->autenticar();
        $this->sembrar_escena();

        $conversaciones = AiConversation::count();
        $mensajes       = AiMessage::count();

        $this->fakear_anthropic($this->respuesta_de_anthropic('Miro 3 veces el cable y no lo compro.'));

        $response = $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'periodo'   => 30,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['resumen' => 'Miro 3 veces el cable y no lo compro.']);

        $this->assertSame($conversaciones, AiConversation::count(), 'El resumen no crea conversaciones del chat.');
        $this->assertSame($mensajes, AiMessage::count(), 'El resumen no crea mensajes del chat.');
    }

    /**
     * 🔴 El gasto de esta pantalla tiene que poder separarse del resto: `proceso` propio, el comercio
     * como dueño de la plata y el empleado que apretó el botón como persona.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_resumen_registra_el_consumo_de_tokens_con_su_proceso_propio()
    {
        $this->autenticar();
        $this->sembrar_escena();

        $antes = AiTokenUsage::where('proceso', ResumenIaActividadService::PROCESO)->count();

        $this->fakear_anthropic($this->respuesta_de_anthropic());

        $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'periodo'   => 30,
        ])->assertStatus(200);

        $filas = AiTokenUsage::where('proceso', ResumenIaActividadService::PROCESO)
            ->orderByDesc('id')
            ->get();

        $this->assertCount($antes + 1, $filas, 'Una llamada a la IA tiene que dejar exactamente una fila de consumo.');

        $fila = $filas[0];

        $this->assertSame('actividad_cliente', (string) $fila->proceso);
        $this->assertSame($this->user->id, (int) $fila->user_id, 'La plata la paga el comercio.');
        $this->assertSame($this->user->id, (int) $fila->auth_user_id, 'El pedido lo hizo una persona, no un job.');
        $this->assertSame(812, (int) $fila->input_tokens);
        $this->assertSame(96, (int) $fila->output_tokens);
        $this->assertNotSame('', (string) $fila->modelo);
    }

    /**
     * @group actividad-de-clientes
     * @test
     */
    public function sin_clave_de_anthropic_el_resumen_devuelve_422_amigable_y_no_sale_a_la_red()
    {
        $this->autenticar();
        $this->sembrar_escena();

        // La clave ya viene en null del setUp(); el fake es la red de contención.
        Http::fake();

        $response = $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'periodo'   => 30,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'El resumen con IA no está disponible en esta cuenta.']);

        Http::assertNothingSent();
    }

    /**
     * Un 529 es "volvé a intentar en un rato" y no "algo está roto": el comerciante tiene que leer
     * eso y no el body crudo de una respuesta HTTP.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_529_de_la_ia_devuelve_el_mensaje_amigable()
    {
        $this->autenticar();
        $this->sembrar_escena();

        $this->fakear_anthropic(['error' => ['type' => 'overloaded_error']], 529);

        $response = $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'periodo'   => 30,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.',
        ]);
    }

    /**
     * 🔴 EL TEST QUE CUIDA LA PLATA. Un cliente sin una sola señal en el periodo no se manda a la IA:
     * la llamada se paga y el párrafo que volvería no podría decir más que "no hizo nada", que es lo
     * que la pantalla ya muestra sola con su cartel de vacío.
     *
     * Ojo con lo que mide: acá HAY clave y HAY stub, así que si el guard no existiera la llamada
     * saldría (contra el fake) y el endpoint contestaría 200. El assertNothingSent es la aserción de
     * verdad.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function sin_actividad_no_se_llama_a_la_ia()
    {
        $this->autenticar();

        // Sin sembrar_escena(): el cliente existe, tiene comprador, y no hizo absolutamente nada.
        $this->fakear_anthropic($this->respuesta_de_anthropic());

        $response = $this->postJson(self::RUTA_RESUMEN, [
            'client_id' => $this->cliente->id,
            'periodo'   => 30,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Todavía no hay actividad de este cliente en la tienda para ese periodo.',
        ]);

        Http::assertNothingSent();
    }

    /**
     * 🔴 armar_datos() son DATOS y armar_prompt() son datos + reglas. La separación se blinda igual
     * que en ofertas: si alguien mueve una regla de redacción adentro del bloque de datos, el día que
     * ese bloque viaje solo se lleva la orden de escribir un párrafo pegada.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_prompt_no_lleva_instrucciones_de_redaccion_adentro_del_bloque_de_datos()
    {
        $servicio = new ResumenIaActividadService();

        $actividad = [
            'fuente'          => 'eventos',
            'periodo'         => 30,
            'desde'           => '2026-07-18',
            'hasta'           => '2026-08-17',
            'hay_datos'       => true,
            'cliente'         => ['client_id' => 1, 'nombre' => 'Ferreteria Lopez'],
            'compradores'     => [['buyer_id' => 1, 'nombre' => 'Lucas gonzalez']],
            'totales'         => [
                'vistas'                  => 12,
                'articulos_distintos'     => 4,
                'tiempo_total_segundos'   => 610,
                'busquedas'               => 5,
                'busquedas_sin_resultado' => 2,
                'agregados_al_carrito'    => 3,
                'quitados_del_carrito'    => 1,
                'checkouts_empezados'     => 1,
                'compras'                 => 1,
                'monto_comprado'          => 15400.5,
                'ultima_actividad'        => '2026-08-16 19:42:00',
            ],
            'articulos'       => [[
                'article_id'           => 33,
                'nombre'               => 'Cable HDMI 2m',
                'vistas'               => 5,
                'tiempo_segundos'      => 240,
                'agregados_al_carrito' => 1,
                'ultima_vez'           => '2026-08-16 19:42:00',
            ]],
            'busquedas'       => [[
                'termino'       => 'taladro percutor',
                'veces'         => 3,
                'resultados'    => 0,
                'sin_resultado' => true,
                'ultima_vez'    => '2026-08-15 10:00:00',
            ]],
            'linea_de_tiempo' => [],
        ];

        $datos  = $servicio->armar_datos($actividad, 'Ferreteria Lopez');
        $prompt = $servicio->armar_prompt($actividad, 'Ferreteria Lopez');

        foreach (['Reglas', 'oraciones', 'markdown'] as $palabra) {
            $this->assertStringNotContainsStringIgnoringCase(
                $palabra,
                $datos,
                'El bloque de datos no puede llevar instrucciones de redaccion: "' . $palabra . '".'
            );
            $this->assertStringContainsStringIgnoringCase(
                $palabra,
                $prompt,
                'Las reglas de redaccion viven en armar_prompt(): "' . $palabra . '".'
            );
        }

        // Los datos sí tienen que estar en los dos: el prompt envuelve al bloque, no lo reemplaza.
        $this->assertStringContainsString('Cable HDMI 2m', $datos);
        $this->assertStringContainsString($datos, $prompt, 'armar_prompt() tiene que envolver el bloque tal cual.');
        $this->assertStringContainsString('NO ENCONTRO NINGUN ARTICULO', $datos, 'Buscar y no encontrar nada se dice con letras.');
    }

    /**
     * 🔴 LA IA NO PUEDE AFIRMAR "NO LO COMPRÓ" SOBRE ALGO DE LO QUE NO TIENE UN SOLO DATO.
     *
     * El prompt traía la regla "Si miro mucho un articulo y no lo compro, decilo" mientras el bloque
     * de datos NO llevaba una sola compra por artículo — tres renglones abajo de "No inventes ni
     * recalcules ningun numero". O sea que la instrucción y los datos se contradecían, y de las dos
     * la que gana es la instrucción: la IA afirmaba.
     *
     * Se mide que los datos ahora traigan las dos cosas —lo que compró por artículo y cuántas
     * compras no se pudieron atribuir— y que la regla pida decirlo como algo que NO FIGURA.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_bloque_de_datos_lleva_lo_que_compro_por_articulo_y_lo_que_no_se_pudo_atribuir()
    {
        $servicio = new ResumenIaActividadService();

        $actividad = [
            'fuente'      => 'eventos',
            'periodo'     => 30,
            'desde'       => '2026-07-18',
            'hasta'       => '2026-08-17',
            'hay_datos'   => true,
            'cliente'     => ['client_id' => 1, 'nombre' => 'Ferreteria Lopez'],
            'compradores' => [['buyer_id' => 1, 'nombre' => 'Lucas gonzalez']],
            'totales'     => [
                'vistas'                  => 12,
                'articulos_distintos'     => 2,
                // 🔴 220 segundos: es donde floor y round se separan (3 minutos, no 4).
                'tiempo_total_segundos'   => 220,
                'busquedas'               => 0,
                'busquedas_sin_resultado' => 0,
                'agregados_al_carrito'    => 1,
                'quitados_del_carrito'    => 0,
                'checkouts_empezados'     => 1,
                'compras'                 => 3,
                'compras_sin_articulo'    => 2,
                'monto_comprado'          => 15400.5,
                'ultima_actividad'        => '2026-08-16 19:42:00',
            ],
            'articulos'   => [
                [
                    'article_id'           => 33,
                    'nombre'               => 'Cable HDMI 2m',
                    'vistas'               => 5,
                    'tiempo_segundos'      => 220,
                    'agregados_al_carrito' => 1,
                    'comprados'            => 1,
                    'ultima_vez'           => '2026-08-16 19:42:00',
                ],
                [
                    'article_id'           => 34,
                    'nombre'               => 'Taladro percutor',
                    'vistas'               => 7,
                    'tiempo_segundos'      => 0,
                    'agregados_al_carrito' => 0,
                    'comprados'            => 0,
                    'ultima_vez'           => '2026-08-15 10:00:00',
                ],
            ],
            'busquedas'       => [],
            'linea_de_tiempo' => [],
        ];

        $datos  = $servicio->armar_datos($actividad, 'Ferreteria Lopez');
        $prompt = $servicio->armar_prompt($actividad, 'Ferreteria Lopez');

        $this->assertStringContainsString(
            'Compras que la tienda NO informo de que articulo eran: 2',
            $datos,
            'sin este número, un artículo en cero se lee como "no lo compró" y puede ser "no sabemos qué compró".'
        );

        $this->assertStringContainsString('lo compro 1 veces en la tienda', $datos, 'el artículo comprado lo dice.');
        $this->assertStringContainsString(
            'el tracking no le vio ninguna compra de este articulo',
            $datos,
            '"el tracking no le vio una compra" y "no lo compró" no son lo mismo, y la diferencia se dice.'
        );

        // 🔴 Los minutos van calculados y con la misma convención que la pantalla: 220 s son 3.
        $this->assertStringContainsString('220 segundos mirandolo (3 minutos)', $datos);
        $this->assertStringContainsString('220 segundos (3 minutos)', $datos);
        $this->assertStringNotContainsString('(4 minutos)', $datos, '220 segundos no son 4 minutos: la pantalla dice 3.');

        // Y la regla del prompt ya no le pide afirmar una compra que no puede saber.
        $this->assertStringContainsString(
            'no figura que lo haya comprado',
            $prompt,
            'la regla tiene que pedir decirlo como algo que no figura, nunca como un hecho.'
        );
    }
}
