<?php

namespace Tests\Feature\ActividadDeClientes;

use App\Console\Commands\PurgarBuyerTracking;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Services\ActividadDeClientes\ActividadDeClientesService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión actividad-de-clientes-y-oferta-por-whatsapp — unidad 1: el lector del tracking.
 *
 * Lo que estos tests protegen no es "que devuelva algo": es que devuelva LO QUE PASÓ. Un lector de
 * comportamiento que se equivoca no tira ningún error — muestra un número plausible, y el
 * comerciante toma una decisión de plata con él. Los cuatro modos de falla que se miden acá:
 *
 *   1. Que la actividad de un cliente se lea por UN comprador en vez de por todos los suyos, o que
 *      se cuele la de otro comercio: los dos son números que "andan" y están mal.
 *   2. Que la regla de corte por antigüedad no exista y todo salga siempre de los eventos crudos.
 *      Los crudos se purgan a los 90 días, así que un "total de siempre" leído de ahí es la cola de
 *      los últimos 90 días con cara de total, y no hay nada en pantalla que lo denuncie.
 *   3. Que "buscó y no encontró nada" (results_count = 0) se confunda con "no aplica" (null). Es el
 *      dato más accionable de toda la pantalla: hay demanda y no hay oferta.
 *   4. Que un SUM() de puros nulls o un artículo borrado del catálogo rompan la pantalla en vez de
 *      degradar a cero y a "Artículo #N".
 *
 * Cada test arma SU PROPIO comercio con User::create() (más un segundo comercio para el
 * aislamiento): no depende del usuario 500 sembrado en la base de testing, así que no se rompe si
 * alguien cambia el fixture. Molde: MotorDeOfertas/9_Chat_y_tool_de_ofertas_Test:47-58.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites — está escrito en
 * TrackingBuyers/2_Agregacion_Test:34-36.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Lector_de_actividad_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        /*
         * 🔴 El .env.testing tiene una clave REAL de Anthropic y cada llamada se paga. Este servicio
         * no llama a la IA, así que además ninguna request tendría que salir; la línea va igual
         * porque es la clase de error ya cometida en este repo y no se deja al azar.
         */
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio actividad',
            'company_name' => 'Ferreteria actividad',
            'email'        => 'actividad-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio actividad',
            'email'    => 'actividad-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * @param  User|null $owner
     * @return ActividadDeClientesService
     */
    protected function servicio($owner = null)
    {
        return new ActividadDeClientesService(is_null($owner) ? $this->comercio->id : $owner->id);
    }

    /**
     * @param  string    $nombre
     * @param  User|null $owner
     * @return Client
     */
    protected function cliente($nombre, $owner = null)
    {
        return Client::create([
            'name'    => $nombre,
            'user_id' => is_null($owner) ? $this->comercio->id : $owner->id,
        ]);
    }

    /**
     * @param  string    $nombre
     * @param  User|null $owner
     * @return Article
     */
    protected function articulo($nombre, $owner = null)
    {
        return Article::create([
            'name'    => $nombre,
            'user_id' => is_null($owner) ? $this->comercio->id : $owner->id,
        ]);
    }

    /**
     * Un comprador de la tienda, atado (o no) a un cliente del ERP. El vínculo es opcional y manual,
     * y el buyer SIN cliente es el caso normal, no el borde.
     *
     * @param  Client|null $cliente
     * @param  User|null   $owner
     * @return Buyer
     */
    protected function comprador($cliente = null, $owner = null)
    {
        return Buyer::create([
            'name'                    => 'Comprador ' . uniqid(),
            'email'                   => 'buyer-' . uniqid() . '@test.local',
            'user_id'                 => is_null($owner) ? $this->comercio->id : $owner->id,
            'comercio_city_client_id' => is_null($cliente) ? null : $cliente->id,
        ]);
    }

    /**
     * Un evento crudo de la tienda. Los campos que no se pasan quedan en su default.
     * Molde literal de TrackingBuyers/2_Agregacion_Test::sembrar_evento():114-128.
     *
     * @param  array $datos
     * @return void
     */
    protected function evento(array $datos)
    {
        DB::table('buyer_tracking_events')->insert(array_merge([
            'user_id'       => $this->comercio->id,
            'buyer_id'      => null,
            'visitor_id'    => '11111111-1111-4111-8111-111111111111',
            'session_id'    => '22222222-2222-4222-8222-222222222222',
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
     * Una fila del agregado diario, escrita a mano. Es lo que en producción deja
     * `tracking:agregar-buyers` y lo único que sobrevive a la purga de los 90 días.
     *
     * @param  array $datos
     * @return void
     */
    protected function agregado(array $datos)
    {
        DB::table('buyer_tracking_daily')->insert(array_merge([
            'user_id'        => $this->comercio->id,
            'fecha'          => now()->subDays(200)->format('Y-m-d'),
            'buyer_id'       => null,
            'article_id'     => null,
            'event_type'     => 'product_view',
            'search_term'    => null,
            'results_count'  => null,
            'total'          => 1,
            'visitantes'     => 1,
            'dwell_ms_total' => 0,
            'amount_total'   => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $datos));
    }

    /**
     * Una compra REAL: la venta y su línea de article_purchases, con la misma fecha. Es lo que el
     * filtro de ventas reales acepta (venta no borrada y que no sea consolidación de facturación).
     *
     * @param  Client  $client
     * @param  Article $article
     * @param  int     $dias_atras
     * @return void
     */
    protected function comprar($client, $article, $dias_atras)
    {
        $fecha = now()->subDays($dias_atras);

        $sale = Sale::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $client->id,
            'created_at' => $fecha,
        ]);

        ArticlePurchase::create([
            'sale_id'    => $sale->id,
            'client_id'  => $client->id,
            'article_id' => $article->id,
            'amount'     => 1,
            'created_at' => $fecha,
        ]);
    }

    /**
     * 🔴 Un cliente del ERP puede tener MÁS DE UN Buyer (se registró dos veces, o la familia
     * comparte la cuenta) y toda su actividad viene SUMADA entre ellos. Agrupando por comprador
     * saldrían dos filas del mismo artículo y la pantalla mostraría la mitad del interés real.
     * Es el mismo criterio de CriteriosDeOfertaService::vistas_con_tiempo():408-412.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_actividad_de_un_cliente_suma_los_buyers_de_ese_cliente()
    {
        $cliente = $this->cliente('Ferreteria Lopez');
        $uno     = $this->comprador($cliente);
        $dos     = $this->comprador($cliente);
        $art     = $this->articulo('Cable HDMI 2m');

        foreach ([2, 3] as $dias) {
            $this->evento(['buyer_id' => $uno->id, 'article_id' => $art->id, 'dwell_ms' => 3000, 'occurred_at' => now()->subDays($dias)]);
        }

        foreach ([4, 5, 6] as $dias) {
            $this->evento(['buyer_id' => $dos->id, 'article_id' => $art->id, 'dwell_ms' => 1000, 'occurred_at' => now()->subDays($dias)]);
        }

        $this->evento([
            'buyer_id'    => $dos->id,
            'event_type'  => 'checkout_complete',
            'amount'      => 15400.50,
            'occurred_at' => now()->subDays(2),
        ]);

        $servicio   = $this->servicio();
        $buyer_ids  = $servicio->buyer_ids_de_un_cliente($cliente->id);
        $actividad  = $servicio->actividad($buyer_ids, 30);

        $this->assertCount(2, $buyer_ids, 'los dos compradores del cliente tienen que entrar a la consulta');
        $this->assertCount(2, $actividad['compradores']);
        $this->assertEquals($cliente->id, $actividad['cliente']['client_id']);
        $this->assertTrue($actividad['hay_datos']);

        $this->assertCount(1, $actividad['articulos'], 'los dos compradores miraron el MISMO artículo: es una sola fila');
        $this->assertSame($art->id, $actividad['articulos'][0]['article_id']);
        $this->assertSame(5, $actividad['articulos'][0]['vistas'], 'las vistas de los dos compradores se suman');
        // 2 x 3000 ms + 3 x 1000 ms = 9000 ms
        $this->assertSame(9, $actividad['articulos'][0]['tiempo_segundos']);

        $this->assertSame(5, $actividad['totales']['vistas']);
        $this->assertSame(1, $actividad['totales']['articulos_distintos']);
        $this->assertSame(9, $actividad['totales']['tiempo_total_segundos']);
        $this->assertSame(1, $actividad['totales']['compras']);
        $this->assertSame(15400.5, $actividad['totales']['monto_comprado']);
    }

    /**
     * 🔴 Las consultas de tracking NO llevan user_id (le darían vuelta la elección de índice al
     * optimizador): la tenencia la da `buyers.user_id` al resolver los buyer_id. Este test es el que
     * prueba que ese aislamiento existe de verdad y no de palabra.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_actividad_de_otro_comercio_no_se_ve()
    {
        $mio      = $this->cliente('Cliente propio');
        $mi_buyer = $this->comprador($mio);
        $mi_art   = $this->articulo('Articulo propio');

        $ajeno       = $this->cliente('Cliente ajeno', $this->otro_comercio);
        $buyer_ajeno = $this->comprador($ajeno, $this->otro_comercio);
        $art_ajeno   = $this->articulo('Articulo ajeno', $this->otro_comercio);

        $this->evento(['buyer_id' => $mi_buyer->id, 'article_id' => $mi_art->id, 'occurred_at' => now()->subDays(3)]);

        foreach ([1, 2, 3, 4, 5] as $dias) {
            $this->evento([
                'user_id'     => $this->otro_comercio->id,
                'buyer_id'    => $buyer_ajeno->id,
                'article_id'  => $art_ajeno->id,
                'occurred_at' => now()->subDays($dias),
            ]);
        }

        $servicio = $this->servicio();

        $this->assertSame([], $servicio->buyer_ids_de_un_cliente($ajeno->id), 'un cliente de otro comercio no tiene compradores para mí');
        $this->assertSame([], $servicio->buyer_ids_de_un_comprador($buyer_ajeno->id), 'un comprador de otro comercio no es mío');

        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($mio->id), 30);

        $this->assertSame(1, $actividad['totales']['vistas'], 'sin el filtro por buyer_id entrarían las 5 vistas del otro comercio');
        $this->assertCount(1, $actividad['articulos']);
        $this->assertSame($mi_art->id, $actividad['articulos'][0]['article_id']);
    }

    /**
     * 🔴 La diferencia entre las DOS PUERTAS de la pantalla, y está a propósito: el vínculo
     * `buyers.comercio_city_client_id` es opcional y manual, así que un comprador sin cliente
     * asociado no se puede alcanzar desde Clientes del ERP y sí desde Tienda Online → Clientes.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_buyer_sin_cliente_no_aparece_por_client_id_pero_si_por_buyer_id()
    {
        $cliente     = $this->cliente('Cliente con comprador');
        $con_cliente = $this->comprador($cliente);
        $huerfano    = $this->comprador(null);
        $art         = $this->articulo('Articulo de las dos puertas');

        foreach ([1, 2] as $dias) {
            $this->evento(['buyer_id' => $con_cliente->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays($dias)]);
        }

        foreach ([1, 2, 3] as $dias) {
            $this->evento(['buyer_id' => $huerfano->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays($dias)]);
        }

        $servicio = $this->servicio();

        $por_cliente = $servicio->buyer_ids_de_un_cliente($cliente->id);
        $this->assertSame([$con_cliente->id], $por_cliente, 'el buyer sin cliente no se alcanza por client_id');
        $this->assertSame(2, $servicio->actividad($por_cliente, 30)['totales']['vistas']);

        $por_buyer = $servicio->buyer_ids_de_un_comprador($huerfano->id);
        $this->assertSame([$huerfano->id], $por_buyer);

        $actividad = $servicio->actividad($por_buyer, 30);
        $this->assertSame(3, $actividad['totales']['vistas'], 'por buyer_id SÍ se ve al comprador sin cliente');
        $this->assertNull($actividad['cliente'], 'un comprador sin cliente asociado no tiene cliente que nombrar');
    }

    /**
     * 🔴 LA REGLA DE CORTE, primera mitad: un periodo corto se lee de los EVENTOS crudos, que son los
     * únicos que tienen la hora y el detalle. Lo que ya no tiene evento crudo (porque la purga se lo
     * llevó) NO aparece, igual que en producción.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function con_un_periodo_de_30_dias_se_lee_de_los_eventos_y_no_del_agregado()
    {
        $cliente = $this->cliente('Cliente con historia');
        $buyer   = $this->comprador($cliente);
        $nuevo   = $this->articulo('Articulo mirado hace poco');
        $viejo   = $this->articulo('Articulo que solo vive en el agregado');

        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $nuevo->id, 'occurred_at' => now()->subDays(10)]);
        $this->agregado([
            'buyer_id'   => $buyer->id,
            'article_id' => $viejo->id,
            'fecha'      => now()->subDays(200)->format('Y-m-d'),
            'total'      => 7,
        ]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);

        $this->assertSame(ActividadDeClientesService::FUENTE_EVENTOS, $actividad['fuente']);
        $this->assertSame([$nuevo->id], array_column($actividad['articulos'], 'article_id'), 'el artículo del agregado viejo no puede aparecer en una ventana de 30 días');
        $this->assertNotEmpty($actividad['linea_de_tiempo'], 'con los eventos crudos SÍ hay línea de tiempo');
    }

    /**
     * 🔴 LA REGLA DE CORTE, segunda mitad: "todo el historial" se lee del AGREGADO, que es lo único
     * que sobrevive a la purga de 90 días. Y como el agregado no guarda la hora del evento, no hay
     * línea de tiempo posible; y como el rollup corre a las 03:30 sobre el día anterior, el `hasta`
     * es AYER y la pantalla lo tiene que decir.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function con_periodo_cero_se_lee_del_agregado_y_aparece_lo_que_ya_no_tiene_evento_crudo()
    {
        $cliente = $this->cliente('Cliente con historia larga');
        $buyer   = $this->comprador($cliente);
        $nuevo   = $this->articulo('Articulo mirado hace poco');
        $viejo   = $this->articulo('Articulo de hace 200 dias');

        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $nuevo->id, 'occurred_at' => now()->subDays(10)]);
        $this->agregado([
            'buyer_id'       => $buyer->id,
            'article_id'     => $viejo->id,
            'fecha'          => now()->subDays(200)->format('Y-m-d'),
            'total'          => 7,
            'visitantes'     => 2,
            'dwell_ms_total' => 42000,
        ]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), ActividadDeClientesService::PERIODO_HISTORICO);

        $this->assertSame(ActividadDeClientesService::FUENTE_AGREGADO, $actividad['fuente']);
        $this->assertSame([$viejo->id], array_column($actividad['articulos'], 'article_id'), 'el histórico lo contesta el agregado, que es lo único que sobrevive a la purga');
        $this->assertSame(7, $actividad['totales']['vistas']);
        $this->assertSame(42, $actividad['totales']['tiempo_total_segundos']);
        $this->assertSame([], $actividad['linea_de_tiempo'], 'el agregado no guarda la hora del evento: no hay línea de tiempo posible');
        $this->assertSame(now()->subDay()->format('Y-m-d'), $actividad['hasta'], 'el rollup corre sobre el día anterior: el histórico llega hasta AYER');
        $this->assertSame(now()->subDays(200)->format('Y-m-d'), $actividad['articulos'][0]['ultima_vez'], 'del agregado la fecha viene sin hora');
    }

    /**
     * La lista blanca de periodos. Un 365 o un 1 no son "casi" un periodo válido: son una ventana que
     * esta pantalla no sabe contestar y hay que rechazarlos antes de tocar la base.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_periodo_fuera_de_la_lista_blanca_no_es_valido()
    {
        foreach ([0, 7, 30, 90] as $valido) {
            $this->assertTrue(ActividadDeClientesService::es_periodo_valido($valido), $valido . ' está en PERIODOS_VALIDOS');
        }

        // Un '30' que llegó por la query string vale lo mismo que un 30: se controla el valor.
        $this->assertTrue(ActividadDeClientesService::es_periodo_valido('30'));

        foreach ([365, 1, -1, 'abc', '30.5', '', null, true] as $invalido) {
            $this->assertFalse(ActividadDeClientesService::es_periodo_valido($invalido), var_export($invalido, true) . ' no es un periodo de esta pantalla');
        }
    }

    /**
     * 🔴 El dato más accionable de toda la pantalla: "buscó y NO ENCONTRÓ NADA" (results_count = 0)
     * tiene que distinguirse de "no aplica" (null). Por eso la agregación es MIN y no MAX: la
     * pregunta es "¿alguna vez buscó esto y no encontró nada?", y con MAX un solo acierto tapa todos
     * los fracasos.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function una_busqueda_sin_resultados_se_distingue_de_una_que_no_aplica()
    {
        $cliente = $this->cliente('Cliente que busca');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo mirado');

        // El mismo término, una vez con resultados y otra sin: MIN dice "alguna vez no encontró nada".
        $this->evento(['buyer_id' => $buyer->id, 'event_type' => 'search', 'search_term' => 'taladro percutor', 'results_count' => 0, 'occurred_at' => now()->subDays(3)]);
        $this->evento(['buyer_id' => $buyer->id, 'event_type' => 'search', 'search_term' => 'taladro percutor', 'results_count' => 4, 'occurred_at' => now()->subDays(2)]);
        $this->evento(['buyer_id' => $buyer->id, 'event_type' => 'search', 'search_term' => 'cable hdmi', 'results_count' => 5, 'occurred_at' => now()->subDays(2)]);
        // Una búsqueda cuyo results_count no vino: es "no aplica", NO es un cero.
        $this->evento(['buyer_id' => $buyer->id, 'event_type' => 'search', 'search_term' => 'sin dato', 'results_count' => null, 'occurred_at' => now()->subDays(1)]);
        // Un product_view no es una búsqueda y no puede aparecer en la lista de términos.
        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays(1)]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);
        $por_termino = [];

        foreach ($actividad['busquedas'] as $busqueda) {
            $por_termino[$busqueda['termino']] = $busqueda;
        }

        $this->assertCount(3, $actividad['busquedas'], 'el product_view no es una búsqueda y no entra a la lista');
        $this->assertArrayNotHasKey('', $por_termino);

        $this->assertSame(2, $por_termino['taladro percutor']['veces']);
        $this->assertSame(0, $por_termino['taladro percutor']['resultados'], 'con MAX en vez de MIN acá diría 4 y el "no encontró nada" desaparecería');
        $this->assertTrue($por_termino['taladro percutor']['sin_resultado']);

        $this->assertSame(5, $por_termino['cable hdmi']['resultados']);
        $this->assertFalse($por_termino['cable hdmi']['sin_resultado']);

        $this->assertNull($por_termino['sin dato']['resultados'], 'null es "no aplica", no un cero');
        $this->assertFalse($por_termino['sin dato']['sin_resultado'], 'sin el dato NO se puede afirmar que no encontró nada');

        $this->assertSame(4, $actividad['totales']['busquedas']);
        $this->assertSame(1, $actividad['totales']['busquedas_sin_resultado'], 'sólo el evento con results_count = 0; el null no cuenta como cero');
    }

    /**
     * `dwell_ms` es nullable y SUM() de puros nulls devuelve NULL. El tiempo se informa en SEGUNDOS
     * ENTEROS: sin el cast la pantalla mostraría un float o directamente "null segundos". Las
     * aserciones van con assertSame a propósito — el tipo es parte del contrato con la SPA.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_tiempo_en_pantalla_se_informa_en_segundos_y_los_nulls_cuentan_como_cero()
    {
        $cliente  = $this->cliente('Cliente sin reloj');
        $buyer    = $this->comprador($cliente);
        $sin_reloj = $this->articulo('Articulo sin dwell');

        foreach ([1, 2, 3] as $dias) {
            $this->evento(['buyer_id' => $buyer->id, 'article_id' => $sin_reloj->id, 'dwell_ms' => null, 'occurred_at' => now()->subDays($dias)]);
        }

        $servicio  = $this->servicio();
        $buyer_ids = $servicio->buyer_ids_de_un_cliente($cliente->id);
        $actividad = $servicio->actividad($buyer_ids, 30);

        $this->assertSame(0, $actividad['totales']['tiempo_total_segundos'], 'una tienda que todavía no manda el reloj informa CERO, no null');
        $this->assertSame(0, $actividad['articulos'][0]['tiempo_segundos']);
        $this->assertSame(3, $actividad['articulos'][0]['vistas'], 'el tiempo en cero no puede llevarse puestas las vistas');

        // Y con reloj: milisegundos convertidos a segundos enteros.
        $con_reloj = $this->articulo('Articulo con dwell');
        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $con_reloj->id, 'dwell_ms' => 45000, 'occurred_at' => now()->subDays(1)]);
        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $con_reloj->id, 'dwell_ms' => 45500, 'occurred_at' => now()->subDays(1)]);

        $actividad = $servicio->actividad($buyer_ids, 30);
        $por_articulo = [];

        foreach ($actividad['articulos'] as $articulo) {
            $por_articulo[$articulo['article_id']] = $articulo;
        }

        $this->assertSame(91, $por_articulo[$con_reloj->id]['tiempo_segundos'], '90500 ms son 91 segundos, enteros');
        $this->assertSame(91, $actividad['totales']['tiempo_total_segundos']);
    }

    /**
     * La línea de tiempo es lo primero que mira el comerciante y tiene que leerse de arriba hacia
     * abajo como pasó: del más nuevo al más viejo. Y va topeada, porque un cliente activo puede tener
     * miles de eventos en la ventana y esto es un modal, no un export.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_linea_de_tiempo_viene_del_mas_nuevo_al_mas_viejo_y_esta_topeada()
    {
        $cliente = $this->cliente('Cliente muy activo');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo muy mirado');
        $cuantos = ActividadDeClientesService::MAX_EVENTOS_LINEA_DE_TIEMPO + 5;

        for ($i = 0; $i < $cuantos; $i++) {
            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $art->id,
                'dwell_ms'    => 48000,
                'occurred_at' => now()->subMinutes($i + 1),
            ]);
        }

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 7);
        $linea     = $actividad['linea_de_tiempo'];

        $this->assertCount(ActividadDeClientesService::MAX_EVENTOS_LINEA_DE_TIEMPO, $linea);
        $this->assertSame($cuantos, $actividad['totales']['vistas'], 'el tope es de la línea de tiempo, no de los totales');

        for ($i = 1; $i < count($linea); $i++) {
            $this->assertTrue(
                $linea[$i - 1]['cuando'] >= $linea[$i]['cuando'],
                'la línea de tiempo va del más nuevo al más viejo'
            );
        }

        $this->assertSame('product_view', $linea[0]['tipo']);
        $this->assertSame('Miró un artículo', $linea[0]['tipo_texto']);
        $this->assertSame($art->id, $linea[0]['article_id']);
        $this->assertSame('Articulo muy mirado', $linea[0]['articulo']);
        $this->assertSame(48, $linea[0]['segundos']);
        $this->assertNull($linea[0]['termino']);
        $this->assertNull($linea[0]['monto']);
    }

    /**
     * 🔴 "SIN COMPRARLO" SE RESUELVE CONTRA LAS DOS COMPRAS, Y ESO ES LO QUE MIDE ESTE TEST.
     *
     *   - `article_purchases`: la venta CONFIRMADA del ERP, que es la verdad de la facturación.
     *   - El `checkout_complete` del tracking: la compra tal como pasó en la tienda.
     *
     * Con sólo la primera esta lista mentía, y no en un caso de borde: la confirmación del pedido de
     * la tienda es un paso MANUAL, así que un cliente que compró online sigue sin figurar en
     * `article_purchases` hasta que el comerciante lo confirma — y mientras tanto la tool lo listaba
     * bajo el título "TODAVÍA NO LO COMPRARON". Se mide el caso "compró en la tienda y todavía no
     * está facturado", que es justo el que se colaba.
     *
     * El descarte sólo aplica si la compra es POSTERIOR a la señal: que lo haya comprado el año
     * pasado no dice nada de que lo esté mirando esta semana.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function los_interesados_en_un_articulo_no_incluyen_al_que_ya_lo_compro()
    {
        $compro         = $this->cliente('El que ya lo compro');
        $compro_online  = $this->cliente('El que lo compro en la tienda');
        $mira           = $this->cliente('El que todavia lo mira');
        $art            = $this->articulo('Taladro percutor');

        $buyer_compro        = $this->comprador($compro);
        $buyer_compro_online = $this->comprador($compro_online);
        $buyer_mira          = $this->comprador($mira);

        $this->evento(['buyer_id' => $buyer_compro->id, 'article_id' => $art->id, 'dwell_ms' => 30000, 'occurred_at' => now()->subDays(10)]);
        $this->evento(['buyer_id' => $buyer_compro_online->id, 'article_id' => $art->id, 'dwell_ms' => 30000, 'occurred_at' => now()->subDays(10)]);
        $this->evento(['buyer_id' => $buyer_mira->id, 'article_id' => $art->id, 'dwell_ms' => 60000, 'occurred_at' => now()->subDays(10)]);
        $this->evento(['buyer_id' => $buyer_mira->id, 'article_id' => $art->id, 'event_type' => 'cart_add', 'occurred_at' => now()->subDays(9)]);

        // La compra es POSTERIOR a la vista: ya no hay nada que ofrecerle.
        $this->comprar($compro, $art, 5);

        /*
         * 🔴 Y éste compró EN LA TIENDA y no tiene una sola fila en `article_purchases`: es el
         * pedido que el comerciante todavía no confirmó. Sin el descarte por `checkout_complete`,
         * aparece en la lista de "no lo compraron".
         */
        $this->evento([
            'buyer_id'    => $buyer_compro_online->id,
            'article_id'  => $art->id,
            'event_type'  => 'checkout_complete',
            'amount'      => 15400.50,
            'occurred_at' => now()->subDays(4),
        ]);

        $servicio    = $this->servicio();
        $interesados = $servicio->interesados_en_un_articulo($art->id, 30);

        $this->assertCount(
            1,
            $interesados,
            'el que lo compró después de mirarlo sale de la lista, lo haya facturado el ERP o lo haya cerrado en la tienda'
        );
        $this->assertSame($mira->id, $interesados[0]['client_id']);
        $this->assertSame('El que todavia lo mira', $interesados[0]['cliente']);
        $this->assertSame(1, $interesados[0]['vistas']);
        $this->assertSame(60, $interesados[0]['segundos']);
        $this->assertSame(1, $interesados[0]['al_carrito']);

        // Y la tenencia la da el artículo: uno de otro comercio no devuelve nada.
        $ajeno = $this->articulo('Articulo de otro', $this->otro_comercio);
        $this->assertSame([], $servicio->interesados_en_un_articulo($ajeno->id, 30));
        $this->assertNull($servicio->articulo_del_dueno($ajeno->id));
    }

    /**
     * 🔴 EL DESCARTE PASA ANTES DEL RECORTE, Y ESTE ES EL CASO QUE LO PRUEBA.
     *
     * El tope estaba en el LIMIT del SQL y el descarte de "ya lo compró" corría después, en PHP. Con
     * un artículo que miraron muchos clientes y donde los que más lo miraron YA LO COMPRARON, la
     * consulta se traía justo a ésos, el filtro los sacaba a todos y la lista volvía VACÍA: la IA
     * contestaba "no lo está mirando nadie sin comprarlo" sobre un artículo que estaban mirando diez
     * clientes. No hay error, no hay excepción: hay una lista vacía perfectamente plausible.
     *
     * El test del tope que ya existía no lo agarraba porque sembraba interesados que no habían
     * comprado NINGUNO, o sea el único caso en que filtrar antes o después da lo mismo.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_descarte_de_los_que_ya_compraron_pasa_antes_del_recorte_de_la_lista()
    {
        $art = $this->articulo('Amoladora que compraron los que mas la miraron');

        /*
         * Los que MÁS lo miraron ya lo compraron: son los primeros que trae el SQL (ordena por
         * vistas descendente), así que son los que se llevaría un recorte aplicado antes del filtro.
         */
        for ($i = 0; $i < 25; $i++) {
            $cliente = $this->cliente('Ya lo compro ' . $i);
            $buyer   = $this->comprador($cliente);

            for ($v = 0; $v < 5; $v++) {
                $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays(10)]);
            }

            $this->comprar($cliente, $art, 3);
        }

        // Y tres que lo están mirando de verdad, con menos vistas que los de arriba.
        $mirando = [];

        for ($i = 0; $i < 3; $i++) {
            $cliente   = $this->cliente('Solo lo mira ' . $i);
            $mirando[] = $cliente->id;
            $buyer     = $this->comprador($cliente);

            $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays(10)]);
        }

        $interesados = $this->servicio()->interesados_en_un_articulo($art->id, 30);

        $this->assertCount(
            3,
            $interesados,
            'La lista volvió recortada por los que ya compraron: el descarte está corriendo DESPUÉS del tope.'
        );

        foreach ($interesados as $fila) {
            $this->assertContains(
                $fila['client_id'],
                $mirando,
                'Se coló un cliente que ya había comprado el artículo.'
            );
        }
    }

    /**
     * 🔴 LA REGLA DE CORTE POR ANTIGÜEDAD LA APLICA EL PRODUCTOR, NO EL QUE LLAMA.
     *
     * El docblock de la clase dice que pedirle a `buyer_tracking_events` una ventana mayor a la
     * retención "devuelve una mentira tranquilizadora", porque la purga ya se llevó lo de más atrás.
     * Esa regla vivía en fuente_para(), y a fuente_para() la llamaba SOLO actividad(): los dos
     * métodos por artículo tomaban los días crudos. No estaba roto porque sus llamadores recortan al
     * enum antes de llamar — o sea que la protección estaba en el llamador y no en el productor, que
     * es cómo se llega a que el tercer llamador la rompa sin enterarse.
     *
     * Se mide con un evento que cae entre la retención y la ventana pedida: con el tope, no entra.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function las_consultas_por_articulo_topean_la_ventana_a_la_retencion_aunque_les_pidan_mas()
    {
        $cliente = $this->cliente('Cliente de la ventana larga');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo mirado hace mucho');

        $mas_viejo_que_la_retencion = PurgarBuyerTracking::DIAS_RETENCION + 30;

        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => $art->id,
            'occurred_at' => now()->subDays($mas_viejo_que_la_retencion - 5),
        ]);

        $this->evento([
            'buyer_id'    => null,
            'visitor_id'  => 'ffffffff-0000-4000-8000-0000000000aa',
            'article_id'  => $art->id,
            'occurred_at' => now()->subDays($mas_viejo_que_la_retencion - 5),
        ]);

        $servicio = $this->servicio();

        $this->assertSame(
            [],
            $servicio->interesados_en_un_articulo($art->id, $mas_viejo_que_la_retencion),
            'interesados_en_un_articulo() contestó una ventana más larga que la retención en vez de topearla.'
        );

        $this->assertSame(
            ['eventos' => 0, 'visitantes' => 0],
            $servicio->anonimos_de_un_articulo($art->id, $mas_viejo_que_la_retencion),
            'anonimos_de_un_articulo() contestó una ventana más larga que la retención en vez de topearla.'
        );

        // Y adentro de la retención los mismos eventos sí se ven: el tope no está apagando la consulta.
        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays(3)]);

        $this->assertCount(1, $servicio->interesados_en_un_articulo($art->id, PurgarBuyerTracking::DIAS_RETENCION));
    }

    /**
     * 🔴 Los visitantes anónimos van APARTE. Sin cuenta no hay a quién nombrar ni a quién ofrecerle
     * nada, así que meterlos en la lista de clientes inflaría un número que después alguien usa para
     * decidir una oferta. Y se cuentan con CASE WHEN, no con WHERE buyer_id IS NULL, que le daría
     * vuelta el índice a la consulta (ver el docblock del servicio).
     *
     * @group actividad-de-clientes
     * @test
     */
    public function los_visitantes_anonimos_se_cuentan_aparte_y_no_se_mezclan_con_los_clientes()
    {
        $cliente = $this->cliente('Cliente con nombre');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo que mira todo el mundo');

        foreach ([1, 2] as $dias) {
            $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays($dias)]);
        }

        // 6 eventos anónimos de 3 visitantes distintos: no son 6 personas.
        for ($i = 0; $i < 6; $i++) {
            $this->evento([
                'buyer_id'    => null,
                'article_id'  => $art->id,
                'visitor_id'  => 'ffffffff-0000-4000-8000-00000000000' . ($i % 3),
                'occurred_at' => now()->subDays(2),
            ]);
        }

        $servicio    = $this->servicio();
        $interesados = $servicio->interesados_en_un_articulo($art->id, 30);

        $this->assertCount(1, $interesados, 'los anónimos no pueden aparecer como clientes');
        $this->assertSame($cliente->id, $interesados[0]['client_id']);
        $this->assertSame(2, $interesados[0]['vistas'], 'las vistas anónimas no se le pueden sumar al cliente');

        $this->assertSame(
            ['eventos' => 6, 'visitantes' => 3],
            $servicio->anonimos_de_un_articulo($art->id, 30)
        );
    }

    /**
     * Un artículo borrado del catálogo no puede tumbar la pantalla ni dejar un renglón en blanco: se
     * informa como "Artículo #N" y el resto del modal sigue funcionando.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_articulo_que_ya_no_esta_en_el_catalogo_no_rompe_la_pantalla()
    {
        $cliente   = $this->cliente('Cliente de articulo fantasma');
        $buyer     = $this->comprador($cliente);
        $fantasma  = ((int) DB::table('articles')->max('id')) + 1000;

        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $fantasma, 'dwell_ms' => 5000, 'occurred_at' => now()->subDays(2)]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);

        $this->assertCount(1, $actividad['articulos']);
        $this->assertSame('Artículo #' . $fantasma, $actividad['articulos'][0]['nombre']);
        $this->assertSame('Artículo #' . $fantasma, $actividad['linea_de_tiempo'][0]['articulo']);
    }

    /**
     * 🔴 EL OTRO CAMINO DEL CARTEL DE VACÍO, y el que se había escapado: compradores REALES con cero
     * actividad en el periodo. No pasa por la guarda temprana —hay buyer_ids, las consultas salen y
     * vuelven sin una sola fila—, así que `hay_datos` tiene que salir de lo que devolvieron esas
     * consultas y no de un `empty()` sobre la colección, que en PHP es SIEMPRE false para un objeto.
     *
     * Lo que estaba roto no se veía: la pantalla mostraba todos los contadores en cero y las tres
     * listas vacías, y al lado un `hay_datos: true` que le decía al front "hay algo, dibujá la
     * pantalla llena y andá a pedirle el resumen a la IA" — o sea una llamada paga por un cliente
     * que no hizo nada.
     *
     * Se miden los tres casos que llegan acá: sin ningún evento, con actividad FUERA de la ventana
     * pedida, y el histórico contra un agregado vacío.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_cliente_con_compradores_pero_sin_actividad_no_tiene_datos()
    {
        $cliente = $this->cliente('Cliente que nunca entro a la tienda');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo que nadie miro');

        $servicio  = $this->servicio();
        $buyer_ids = $servicio->buyer_ids_de_un_cliente($cliente->id);

        $this->assertSame([$buyer->id], $buyer_ids, 'el cliente SÍ tiene comprador: no entra por la guarda temprana');

        // 1. Sin un solo evento en ninguna parte.
        $actividad = $servicio->actividad($buyer_ids, 30);

        $this->assertFalse($actividad['hay_datos'], 'sin una sola fila no hay datos, por más que el cliente tenga comprador');
        $this->assertSame(0, $actividad['totales']['vistas']);
        $this->assertNull($actividad['totales']['ultima_actividad']);
        $this->assertSame([], $actividad['articulos']);
        $this->assertSame([], $actividad['busquedas']);
        $this->assertSame([], $actividad['linea_de_tiempo']);

        // 2. Con actividad, pero VIEJA: fuera de la ventana pedida es exactamente lo mismo que nada.
        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => now()->subDays(45)]);

        $actividad = $servicio->actividad($buyer_ids, 30);
        $this->assertFalse($actividad['hay_datos'], 'un evento de hace 45 días no es actividad de los últimos 30');
        $this->assertSame(0, $actividad['totales']['vistas']);

        // Y con la ventana que sí lo alcanza, el mismo cliente sí tiene datos: la aserción de arriba
        // no está pasando porque hay_datos esté clavado en false.
        $actividad = $servicio->actividad($buyer_ids, 90);
        $this->assertTrue($actividad['hay_datos']);
        $this->assertSame(1, $actividad['totales']['vistas']);

        // 3. El histórico contra un agregado vacío: el evento crudo no lo alcanza, el rollup nunca corrió.
        $actividad = $servicio->actividad($buyer_ids, ActividadDeClientesService::PERIODO_HISTORICO);
        $this->assertSame(ActividadDeClientesService::FUENTE_AGREGADO, $actividad['fuente']);
        $this->assertFalse($actividad['hay_datos'], 'sin filas en buyer_tracking_daily el histórico está vacío');
        $this->assertNull($actividad['totales']['ultima_actividad']);
    }

    /**
     * 🔴 GUARDA TEMPRANA. Un cliente sin ningún comprador de la tienda asociado es el caso MÁS COMÚN
     * (el vínculo es manual), así que tiene que contestarse sin una sola consulta: con la lista
     * vacía, el `whereIn` degenera a un `0 = 1` que igual viaja seis veces a la base para no poder
     * devolver nada.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function sin_compradores_no_se_toca_la_base_y_se_devuelve_el_bloque_vacio()
    {
        $servicio  = $this->servicio();
        $consultas = 0;

        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $actividad = $servicio->actividad([], 30);

        $this->assertSame(0, $consultas, 'sin compradores no se puede tocar buyer_tracking_events ni buyer_tracking_daily');

        $this->assertFalse($actividad['hay_datos']);
        $this->assertSame(ActividadDeClientesService::FUENTE_EVENTOS, $actividad['fuente']);
        $this->assertNull($actividad['cliente']);
        $this->assertSame([], $actividad['compradores']);
        $this->assertSame([], $actividad['articulos']);
        $this->assertSame([], $actividad['busquedas']);
        $this->assertSame([], $actividad['linea_de_tiempo']);

        // Todas las claves están SIEMPRE presentes: el front las da por ciertas.
        foreach (['vistas', 'articulos_distintos', 'tiempo_total_segundos', 'busquedas', 'busquedas_sin_resultado',
                  'agregados_al_carrito', 'quitados_del_carrito', 'checkouts_empezados', 'compras',
                  'compras_sin_articulo'] as $clave) {
            $this->assertSame(0, $actividad['totales'][$clave], $clave . ' tiene que venir en cero, no faltar');
        }

        $this->assertSame(0.0, $actividad['totales']['monto_comprado']);
        $this->assertNull($actividad['totales']['ultima_actividad']);
    }

    /**
     * 🔴 QUÉ COMPRÓ, POR ARTÍCULO — Y CUÁNTO NO SE PUEDE SABER.
     *
     * De las cinco señales que se pidieron, ésta llegaba recortada: `checkout_complete` estaba
     * excluido del detalle por artículo porque el evento no garantiza traer `article_id`, así que la
     * pantalla contestaba "qué compró" con un número pelado de compras. Esconder lo que no se sabe
     * no lo vuelve verdad: se informa lo que SÍ se sabe (`articulos[].comprados`) y cuánto NO se
     * sabe (`totales.compras_sin_articulo`).
     *
     * 🔴 La segunda es la que vuelve honesta a la primera. Sin ella, un `comprados = 0` se lee como
     * "no lo compró", cuando puede ser "lo compró y la tienda no mandó el artículo" — y con ese dato
     * el comerciante sale a ofrecerle lo que la persona ya tiene. Por eso el caso del checkout SIN
     * artículo se siembra a propósito: en datos reales aparece solo, pero el sembrador manda
     * `article_id` en el 100% de los checkouts y ahí este caso no se ve nunca.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_detalle_por_articulo_dice_que_compro_y_cuantas_compras_no_se_pudieron_atribuir()
    {
        $cliente = $this->cliente('Cliente que compra');
        $buyer   = $this->comprador($cliente);
        $mirado  = $this->articulo('Cable HDMI 2m que compro');
        $solo_visto = $this->articulo('Pinza que solo miro');

        foreach ([$mirado, $solo_visto] as $art) {
            $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'dwell_ms' => 60000, 'occurred_at' => now()->subDays(3)]);
        }

        // Dos compras del mismo artículo, atribuidas.
        for ($i = 0; $i < 2; $i++) {
            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $mirado->id,
                'event_type'  => 'checkout_complete',
                'amount'      => 1000,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        // Y una compra que llegó SIN artículo: el tracking no puede decir de qué fue.
        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => null,
            'event_type'  => 'checkout_complete',
            'amount'      => 500,
            'occurred_at' => now()->subDays(1),
        ]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);

        $this->assertSame(3, $actividad['totales']['compras'], 'las tres compras se cuentan igual, se hayan podido atribuir o no');
        $this->assertSame(
            1,
            $actividad['totales']['compras_sin_articulo'],
            'la compra que vino sin article_id tiene que poder verse: es la que vuelve honesto al detalle'
        );

        $por_id = [];

        foreach ($actividad['articulos'] as $articulo) {
            $por_id[$articulo['article_id']] = $articulo;
        }

        $this->assertSame(2, $por_id[$mirado->id]['comprados'], 'los dos checkout_complete con este article_id son dos compras');
        $this->assertSame(1, $por_id[$mirado->id]['vistas']);
        $this->assertSame(0, $por_id[$solo_visto->id]['comprados'], 'un artículo que no compró viene en 0, no sin la clave');

        /*
         * 🔴 Los dos números que NO se pueden mover: la compra no es tiempo mirando el artículo, y
         * "artículos distintos que miró" no puede crecer porque además compró algo.
         */
        $this->assertSame(60, $por_id[$mirado->id]['tiempo_segundos'], 'un checkout no suma tiempo mirando el artículo');
        $this->assertSame(2, $actividad['totales']['articulos_distintos'], 'los artículos distintos son los que MIRÓ');
    }

    /**
     * 🔴 UN ARTÍCULO QUE SÓLO SE COMPRÓ TAMBIÉN ENTRA AL DETALLE, y con la "última vez" en null
     * en vez de con la fecha de la compra: `ultima_vez` contesta "cuándo anduvo mirándolo", y poner
     * ahí el día de la compra sería una fecha correcta contestando otra pregunta.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_articulo_que_solo_se_compro_entra_al_detalle_sin_inventarle_una_vista()
    {
        $cliente = $this->cliente('Cliente que compro sin mirar');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo comprado a ciegas');

        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => $art->id,
            'event_type'  => 'checkout_complete',
            'amount'      => 2500,
            'occurred_at' => now()->subDays(2),
        ]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);

        $this->assertCount(1, $actividad['articulos']);
        $this->assertSame(1, $actividad['articulos'][0]['comprados']);
        $this->assertSame(0, $actividad['articulos'][0]['vistas']);
        $this->assertSame(0, $actividad['articulos'][0]['tiempo_segundos']);
        $this->assertNull($actividad['articulos'][0]['ultima_vez'], 'la compra no es "la última vez que lo miró"');
        $this->assertSame(0, $actividad['totales']['articulos_distintos'], 'no miró ningún artículo distinto: lo compró');
        $this->assertSame(0, $actividad['totales']['compras_sin_articulo']);
    }

    /**
     * 🔴 EL `desde` QUE SE INFORMA ES EL CORTE QUE SE USÓ, Y NO UNO PARECIDO.
     *
     * Se informaba `now()->subDays($periodo)->format('Y-m-d')` mientras la consulta cortaba con
     * `now()->subDays($periodo)`, o sea CON LA HORA de la corrida: con periodo 30 a las 14:30 la
     * pantalla decía "desde el 18" y se estaban dejando afuera todos los eventos del 18 anteriores a
     * las 14:30. Lo que falta no se ve, y ese `desde` viajaba igual al prompt de la IA como si fuera
     * la ventana entera. El corte se movió al principio del día, así que ahora el `desde` es verdad.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_desde_informado_es_el_corte_real_de_la_consulta()
    {
        $cliente = $this->cliente('Cliente del borde del dia');
        $buyer   = $this->comprador($cliente);
        $art     = $this->articulo('Articulo del borde');

        /*
         * Un evento del PRIMER día de la ventana, a las 00:05. Con el corte viejo —que llevaba la
         * hora de la corrida— este evento quedaba afuera en cualquier corrida posterior a las 00:05,
         * o sea prácticamente siempre, y la pantalla igual decía que estaba informando ese día.
         */
        $primer_dia = now()->subDays(30)->startOfDay()->addMinutes(5);

        $this->evento(['buyer_id' => $buyer->id, 'article_id' => $art->id, 'occurred_at' => $primer_dia]);

        $servicio  = $this->servicio();
        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);

        $this->assertSame(
            $primer_dia->format('Y-m-d'),
            $actividad['desde'],
            'el desde informado tiene que ser el primer día que la consulta realmente incluye'
        );

        $this->assertSame(
            1,
            $actividad['totales']['vistas'],
            'un evento del día que la pantalla dice estar informando NO puede quedar afuera de la consulta'
        );

        // Y el día anterior al informado sigue afuera: el corte se corrió, no se borró.
        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => $art->id,
            'occurred_at' => now()->subDays(31)->endOfDay(),
        ]);

        $actividad = $servicio->actividad($servicio->buyer_ids_de_un_cliente($cliente->id), 30);
        $this->assertSame(1, $actividad['totales']['vistas'], 'el día anterior al desde informado no entra');
    }
}
