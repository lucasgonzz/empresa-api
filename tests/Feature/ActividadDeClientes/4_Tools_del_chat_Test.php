<?php

namespace Tests\Feature\ActividadDeClientes;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión actividad-de-clientes-y-oferta-por-whatsapp — unidad 4: las dos tools del chat.
 *
 * Una tool de lectura que se equivoca no tira ningún error: le contesta a Claude un JSON bien
 * formado con datos de otro, y Claude se lo dice al comerciante con total seguridad. Los cuatro
 * modos de falla que se miden acá:
 *
 *   1. Que la tool esté declarada en build_tools() y no despachada en execute_tool_calls() (o al
 *      revés). La IA la ve en el prompt, la llama, y recibe "Tool desconocida". El test que recorre
 *      TODAS las tools ya vive en MotorDeOfertas/9_Chat_y_tool_de_ofertas_Test y cubre a estas dos
 *      sin tocarlo, así que acá NO se duplica: lo que se mide es que el tool_result vuelva sin
 *      is_error, que es la misma punta vista desde el uso real.
 *   2. Que el filtro por dueño no sea el de la conversación. Estas tools corren adentro de un job
 *      SIN sesión: no hay Auth que las tape si el owner_id se pierde.
 *   3. Que aparezca en la lista de interesados alguien que ya compró el artículo, o un visitante
 *      anónimo. Los dos son filas plausibles: una manda al comerciante a ofrecerle algo que la
 *      persona ya tiene, la otra a llamar a alguien que no tiene nombre ni teléfono.
 *   4. Que un `dias` fuera del enum reviente el loop en vez de caer al default.
 *
 * Cada test arma SU PROPIO comercio con User::create() (más un segundo comercio para el
 * aislamiento): no depende del usuario 500 sembrado en la base de testing. Molde:
 * MotorDeOfertas/9_Chat_y_tool_de_ofertas_Test:47-58.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 *
 * 🔴 NINGÚN test de este archivo puede salir a la red. El .env.testing tiene una clave REAL de
 * Anthropic y cada llamada se paga. Acá no se ejercita el loop de la IA —execute_tool_calls()
 * resuelve las tools contra la base y no manda un solo request—, así que no hay nada que fakear;
 * la clave se anula igual en el setUp() porque es una clase de error ya cometida en este repo y no
 * se deja librada a que el código de al lado no cambie.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Tools_del_chat_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    /** @var AsistenteIaService */
    protected $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // 🔴 Sin esto un test que llegue a la API real de Anthropic se paga con la clave del .env.testing.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio tools actividad',
            'company_name' => 'Ferreteria tools actividad',
            'email'        => 'tools-actividad-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio tools actividad',
            'email'    => 'tools-actividad-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->service = new AsistenteIaService();
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
            'phone'   => '3444622139',
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
     * Un comprador de la tienda, atado (o no) a un cliente del ERP. El vínculo es opcional y manual.
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
     * Una compra REAL: la venta y su línea de article_purchases, con la misma fecha. Es lo que el
     * filtro de ventas reales acepta (venta no borrada y que no sea consolidación de facturación).
     * Molde de 1_Lector_de_actividad_Test::comprar().
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
     * La conversación desde la que corre la tool: de acá sale el owner_id, y de ningún otro lado.
     *
     * @param  User|null $owner
     * @return AiConversation
     */
    protected function conversacion($owner = null)
    {
        $owner = is_null($owner) ? $this->comercio : $owner;

        return AiConversation::create([
            'user_id'      => $owner->id,
            'auth_user_id' => $owner->id,
        ]);
    }

    /**
     * La llamada que haría Claude, resuelta contra la base.
     *
     * @param  string         $tool
     * @param  array          $input
     * @param  AiConversation $conversation
     * @return array
     */
    protected function llamar($tool, array $input, $conversation)
    {
        return $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . $tool,
            'name'  => $tool,
            'input' => $input,
        ]], $conversation);
    }

    /**
     * La primera fila de un tipo, o null. Las filas llevan todas las mismas claves, así que buscar
     * por `tipo` es la única forma de leerlas sin depender del orden.
     *
     * @param  array  $filas
     * @param  string $tipo
     * @return array|null
     */
    protected function fila_de_tipo(array $filas, $tipo)
    {
        foreach ($filas as $fila) {
            if ($fila['tipo'] === $tipo) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * La punta declarativa: sin esto la IA ni sabe que las tools existen.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function build_tools_incluye_las_dos_tools_nuevas()
    {
        $nombres = array_column($this->service->build_tools(), 'name');

        $this->assertContains('consultar_actividad_de_un_cliente', $nombres);
        $this->assertContains('consultar_interesados_en_un_articulo', $nombres);
    }

    /**
     * La punta ejecutable de la primera tool. Sin el elseif en execute_tool_calls() el content
     * vendría con "Tool desconocida" y is_error, y la IA le contestaría al comerciante que no puede
     * ver la actividad de sus clientes.
     *
     * Fija además el ORDEN: lo que buscó y no encontró va antes que el detalle de lo que miró,
     * porque el tope de MAX_RESULTS corta por la cola y ese es el dato más accionable que hay.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function execute_tool_calls_resuelve_consultar_actividad_de_un_cliente()
    {
        $cliente  = $this->cliente('Ferreteria Lopez');
        $buyer    = $this->comprador($cliente);
        $articulo = $this->articulo('Cable HDMI 2m tools');

        for ($i = 0; $i < 3; $i++) {
            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $articulo->id,
                'dwell_ms'    => 60000,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        $this->evento([
            'buyer_id'      => $buyer->id,
            'event_type'    => 'search',
            'search_term'   => 'taladro percutor',
            'results_count' => 0,
            'occurred_at'   => now()->subDays(3),
        ]);

        $resultados = $this->llamar(
            'consultar_actividad_de_un_cliente',
            ['client_id' => $cliente->id, 'dias' => 30],
            $this->conversacion()
        );

        $this->assertCount(1, $resultados);
        $this->assertEquals('toolu_consultar_actividad_de_un_cliente', $resultados[0]['tool_use_id']);
        $this->assertArrayNotHasKey(
            'is_error',
            $resultados[0],
            'Con el elseif faltante el tool_result viaja con is_error y content "Tool desconocida".'
        );

        $filas = json_decode($resultados[0]['content'], true);
        $this->assertCount(2, $filas);

        $this->assertEquals('busco', $filas[0]['tipo'], 'Lo que busco y no encontro va primero: el tope corta por la cola.');
        $this->assertEquals('taladro percutor', $filas[0]['detalle']);
        $this->assertSame(0, $filas[0]['resultados'], 'Un 0 es "busco y no encontro nada"; un null seria "no aplica".');

        $vio = $this->fila_de_tipo($filas, 'vio');
        $this->assertNotNull($vio);
        $this->assertEquals('Cable HDMI 2m tools', $vio['detalle']);
        $this->assertSame(3, $vio['veces']);
        $this->assertSame(3, $vio['minutos'], '180000 ms de dwell son 180 segundos, o sea 3 minutos.');
        $this->assertNull($vio['resultados'], 'Una vista no tiene resultados de busqueda: null, no 0.');
    }

    /**
     * La punta ejecutable de la segunda tool.
     *
     * 🔴 Cada fila dice de qué artículo habla. La búsqueda es difusa (LIKE sobre nombre y códigos):
     * sin el artículo resuelto adentro de la respuesta, la IA contestaría con total seguridad sobre
     * uno que no es el que le preguntaron.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function execute_tool_calls_resuelve_consultar_interesados_en_un_articulo()
    {
        $cliente  = $this->cliente('Ferreteria Lopez');
        $buyer    = $this->comprador($cliente);
        $articulo = $this->articulo('Taladro percutor tools');

        for ($i = 0; $i < 2; $i++) {
            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $articulo->id,
                'dwell_ms'    => 120000,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => $articulo->id,
            'event_type'  => 'cart_add',
            'occurred_at' => now()->subDays(2),
        ]);

        $resultados = $this->llamar(
            'consultar_interesados_en_un_articulo',
            ['busqueda' => 'Taladro percutor tools', 'dias' => 30],
            $this->conversacion()
        );

        $this->assertCount(1, $resultados);
        $this->assertEquals('toolu_consultar_interesados_en_un_articulo', $resultados[0]['tool_use_id']);
        $this->assertArrayNotHasKey(
            'is_error',
            $resultados[0],
            'Con el elseif faltante el tool_result viaja con is_error y content "Tool desconocida".'
        );

        $filas = json_decode($resultados[0]['content'], true);
        $this->assertCount(1, $filas);
        $this->assertEquals('Taladro percutor tools', $filas[0]['articulo']);
        $this->assertEquals('Ferreteria Lopez', $filas[0]['cliente']);
        $this->assertEquals('3444622139', $filas[0]['telefono']);
        $this->assertSame(2, $filas[0]['vistas']);
        $this->assertSame(1, $filas[0]['al_carrito']);
        $this->assertSame(4, $filas[0]['minutos'], '240000 ms de dwell son 240 segundos, o sea 4 minutos.');
    }

    /**
     * 🔴 El scope por comercio. Estas tools corren desde un job SIN sesión: el único filtro es el
     * owner_id de la conversación, y si se pierde, un comercio ve lo que hicieron los clientes de
     * otro. Se mide contra una escena REAL del otro comercio, no contra la base vacía: un test que
     * pasa porque no hay datos no mide nada.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function las_tools_filtran_por_el_dueno_de_la_conversacion()
    {
        $ajeno    = $this->cliente('Cliente del otro comercio', $this->otro_comercio);
        $buyer    = $this->comprador($ajeno, $this->otro_comercio);
        $articulo = $this->articulo('Amoladora ajena tools', $this->otro_comercio);

        $this->evento([
            'user_id'     => $this->otro_comercio->id,
            'buyer_id'    => $buyer->id,
            'article_id'  => $articulo->id,
            'dwell_ms'    => 60000,
            'occurred_at' => now()->subDays(2),
        ]);

        // La escena existe y se ve desde su propio dueño.
        $this->assertNotEmpty(ConsultasSistemaIaHelper::actividad_de_un_cliente($this->otro_comercio->id, $ajeno->id, 30));
        $this->assertNotEmpty(ConsultasSistemaIaHelper::interesados_en_un_articulo($this->otro_comercio->id, 'Amoladora ajena tools', 30));

        // Y no se ve desde el comercio de al lado.
        $this->assertCount(0, ConsultasSistemaIaHelper::actividad_de_un_cliente($this->comercio->id, $ajeno->id, 30));
        $this->assertCount(0, ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Amoladora ajena tools', 30));
    }

    /**
     * El tope acota el JSON que viaja al prompt de Claude. Se siembra más de lo que entra para que
     * el test se rompa si alguien saca el recorte.
     *
     * En la tool de actividad el recorte es de esta clase (el lector devuelve hasta 20 artículos
     * MÁS hasta 20 términos buscados, y las filas se arman de las dos listas). En la de interesados
     * el tope lo pone ActividadDeClientesService::MAX_INTERESADOS; la aserción lo fija igual, de
     * punta a punta, que es lo que le importa al que lee el JSON.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function las_dos_tools_respetan_el_tope_de_max_results()
    {
        $cliente = $this->cliente('Cliente con mucha actividad');
        $buyer   = $this->comprador($cliente);

        for ($i = 0; $i < ConsultasSistemaIaHelper::MAX_RESULTS; $i++) {
            $articulo = $this->articulo('Articulo tope ' . $i);

            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $articulo->id,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->evento([
                'buyer_id'      => $buyer->id,
                'event_type'    => 'search',
                'search_term'   => 'termino tope ' . $i,
                'results_count' => 0,
                'occurred_at'   => now()->subDays(2),
            ]);
        }

        $filas = ConsultasSistemaIaHelper::actividad_de_un_cliente($this->comercio->id, $cliente->id, 30);
        $this->assertCount(ConsultasSistemaIaHelper::MAX_RESULTS, $filas);
        $this->assertEquals('busco', $filas[0]['tipo'], 'Con el tope lleno, lo accionable tiene que seguir entrando.');

        // La otra tool: un interesado más de los que entran.
        $articulo = $this->articulo('Articulo con muchos interesados');

        for ($i = 0; $i < ConsultasSistemaIaHelper::MAX_RESULTS + 1; $i++) {
            $otro_cliente = $this->cliente('Interesado ' . $i);
            $otro_buyer   = $this->comprador($otro_cliente);

            $this->evento([
                'buyer_id'    => $otro_buyer->id,
                'article_id'  => $articulo->id,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        $this->assertCount(
            ConsultasSistemaIaHelper::MAX_RESULTS,
            ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Articulo con muchos interesados', 30)
        );
    }

    /**
     * 🔴 El que ya lo compró NO es un interesado. La tool contesta "a quién le puedo ofrecer esto":
     * una fila de alguien que ya lo tiene manda al comerciante a ofrecerle lo que acaba de vender.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_tool_de_interesados_no_lista_al_que_ya_lo_compro()
    {
        $articulo = $this->articulo('Sierra circular tools');

        $comprador_arrepentido = $this->cliente('El que ya lo compro');
        $mirando               = $this->cliente('El que lo esta mirando');

        foreach ([$comprador_arrepentido, $mirando] as $cliente) {
            $buyer = $this->comprador($cliente);

            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $articulo->id,
                'occurred_at' => now()->subDays(10),
            ]);
        }

        // La compra es POSTERIOR a la vista: eso es lo que lo saca de la lista.
        $this->comprar($comprador_arrepentido, $articulo, 2);

        $filas = ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Sierra circular tools', 30);

        $this->assertCount(1, $filas);
        $this->assertEquals('El que lo esta mirando', $filas[0]['cliente']);
    }

    /**
     * Un `dias` que no está en el enum no puede reventar el loop de tool use ni, peor, cambiar de
     * tabla por lo bajo: con 0 el lector contesta desde el agregado, que no tiene hora ni línea de
     * tiempo. Se miden las DOS puertas: la del despacho (que es la que ve Claude) y la del helper
     * (que es pública y estática, y la puede llamar cualquiera).
     *
     * @group actividad-de-clientes
     * @test
     */
    public function un_dias_fuera_del_enum_cae_al_default_y_no_explota()
    {
        $cliente  = $this->cliente('Cliente del enum');
        $buyer    = $this->comprador($cliente);
        $articulo = $this->articulo('Pinza tools');

        $this->evento([
            'buyer_id'    => $buyer->id,
            'article_id'  => $articulo->id,
            'occurred_at' => now()->subDays(2),
        ]);

        $resultados = $this->llamar(
            'consultar_actividad_de_un_cliente',
            ['client_id' => $cliente->id, 'dias' => 999],
            $this->conversacion()
        );

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'Un dias fuera del enum no puede volver como error.');
        $this->assertCount(1, json_decode($resultados[0]['content'], true));

        /*
         * Y por la otra puerta: con 0 el lector leería del agregado —que en este comercio está
         * vacío— y la lista volvería sin una sola fila. Que traiga la vista es la prueba de que
         * cayó a 30 en vez de cambiar de fuente en silencio.
         */
        $this->assertCount(1, ConsultasSistemaIaHelper::actividad_de_un_cliente($this->comercio->id, $cliente->id, 0));
        $this->assertCount(1, ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Pinza tools', 0));
    }

    /**
     * 🔴 Los visitantes anónimos no se pueden nombrar ni llamar, así que no son una fila de esta
     * lista — y tampoco pueden inflar los números del que sí tiene nombre. Se mide lo segundo
     * además de lo primero: un anónimo mezclado adentro de la fila del cliente es un número que
     * "anda" y está mal.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_tool_de_interesados_no_lista_visitantes_anonimos()
    {
        $articulo = $this->articulo('Llave francesa tools');

        for ($i = 0; $i < 10; $i++) {
            $this->evento([
                'buyer_id'    => null,
                'visitor_id'  => '33333333-3333-4333-8333-00000000000' . $i,
                'article_id'  => $articulo->id,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        // Sin un solo comprador con nombre, la lista es vacía: diez anónimos no son un interesado.
        $this->assertCount(0, ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Llave francesa tools', 30));

        $cliente = $this->cliente('El unico con nombre');
        $buyer   = $this->comprador($cliente);

        for ($i = 0; $i < 2; $i++) {
            $this->evento([
                'buyer_id'    => $buyer->id,
                'article_id'  => $articulo->id,
                'occurred_at' => now()->subDays(2),
            ]);
        }

        $filas = ConsultasSistemaIaHelper::interesados_en_un_articulo($this->comercio->id, 'Llave francesa tools', 30);

        $this->assertCount(1, $filas);
        $this->assertEquals('El unico con nombre', $filas[0]['cliente']);
        $this->assertSame(2, $filas[0]['vistas'], 'Los 10 eventos anonimos no pueden sumarse a las vistas del cliente.');
    }
}
