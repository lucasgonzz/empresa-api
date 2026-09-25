<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — bloque B, etapa 2: las consultas declaradas como tools.
 *
 * 🔴 EL MODO DE FALLA QUE ESTE ARCHIVO TAPA ES MUDO. Una tool declarada cuyo handler apunta a un
 * método que no existe (o con un argumento de más) no rompe nada visible: `execute_tool_calls()`
 * atrapa el Throwable y le devuelve a Claude un tool_result con is_error, Claude se disculpa en
 * prosa y la persona lee "no pude consultar eso". Sin error en pantalla, sin rojo en la suite y con
 * la herramienta construida y probada del lado del helper.
 *
 * Por eso acá no se mira la definición: se EJECUTAN las quince, por el mismo camino que usa el
 * loop, y ninguna puede volver con is_error.
 */
class Tools_de_lectura_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var AsistenteIaService */
    protected $service;

    /** @var AiConversation */
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Comercio tools B',
            'company_name' => 'Ferreteria tools B',
            'email'        => 'tools-b-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->service = new AsistenteIaService();

        $this->conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);
    }

    /**
     * 🔴 LA ASERCIÓN DEL ARCHIVO: las diecinueve tools declaradas se ejecutan de verdad.
     *
     * @group chat-ia
     * @test
     */
    public function todas_las_tools_de_lectura_declaradas_se_ejecutan_sin_error()
    {
        $cliente = Client::create(['name' => 'Cliente tools B', 'user_id' => $this->comercio->id]);
        $articulo = Article::create(['name' => 'Articulo tools B', 'user_id' => $this->comercio->id]);
        Provider::create(['name' => 'Proveedor tools B', 'user_id' => $this->comercio->id]);

        // Un input mínimo pero VÁLIDO para cada una: con un input vacío varias contestarían la
        // rama de "no resolví nada", que no ejercita la query de verdad.
        $inputs = [
            'consultar_stock_de_articulos'             => ['busqueda' => 'Articulo tools B'],
            'consultar_clientes'                       => ['busqueda' => 'Cliente tools B'],
            'consultar_movimientos_de_cuenta_corriente' => ['client_id' => $cliente->id, 'tipo' => 'debe', 'orden' => 'mas_viejos', 'pagina' => 1],
            'consultar_articulos_mas_vendidos'         => ['dias' => 30],
            'consultar_precios_de_proveedores'         => ['busqueda' => 'Articulo tools B'],
            'consultar_ofertas_activas'                => ['busqueda' => 'Cliente tools B'],
            'consultar_actividad_de_un_cliente'        => ['client_id' => $cliente->id, 'dias' => 30],
            'consultar_interesados_en_un_articulo'     => ['busqueda' => 'Articulo tools B', 'dias' => 30],
            'consultar_ventas_impagas_de_un_cliente'   => ['client_id' => $cliente->id, 'orden' => 'mas_viejas'],
            'consultar_quien_compro_un_articulo'       => ['busqueda' => 'Articulo tools B', 'dias' => 0],
            'consultar_compras_de_un_articulo'         => ['busqueda' => 'Articulo tools B'],
            'consultar_compras_a_un_proveedor'         => ['busqueda' => 'Proveedor tools B', 'dias' => 365],
            'consultar_stock_por_deposito'             => ['busqueda' => 'Articulo tools B'],
            'que_puedo_consultar'                      => ['entidad' => 'article'],
            'consultar_datos'                          => [
                'entidad' => 'article',
                'filtros' => [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'tools B']],
                'orden'   => ['campo' => 'final_price', 'direccion' => 'DESC'],
                'pagina'  => 1,
            ],
            // Misión asistente-omnisciente (21/9/2026): las cuatro de lectura sin límites, al final.
            'resumir_datos'                            => [
                'entidad'     => 'renglon_de_compra',
                'filtros'     => [['campo' => 'provider_id', 'operador' => 'contiene', 'valor' => 'tools B']],
                'agrupar_por' => [['campo' => 'article_id']],
                'metricas'    => [['funcion' => 'suma', 'campo' => 'amount'], ['funcion' => 'conteo']],
            ],
            'consultar_resumen_de_ventas'              => ['desde' => now()->subDays(7)->format('Y-m-d'), 'hasta' => now()->format('Y-m-d'), 'agrupar_por' => 'dia'],
            'consultar_reporte_contable'               => ['reporte' => 'estado_resultados', 'desde' => now()->subDays(30)->format('Y-m-d'), 'hasta' => now()->format('Y-m-d')],
            'mostrar_imagenes_de_articulos'            => ['articulo_ids' => [$articulo->id]],
            // Misión asistente-ventas-y-fotos (21/9/2026): la de ventas sin cobrar a nivel negocio.
            'consultar_ventas_sin_cobrar'              => ['dias' => 0],
            // Misión asistente-capacidades-y-hilos (22/9/2026): el link del PDF de un comprobante.
            // El número no existe a propósito: acá se prueba que la tool corre y contesta, no que
            // encuentre una venta (eso lo cubre 53_Presupuesto_y_link_de_pdf_Test).
            'consultar_link_de_pdf'                    => ['tipo' => 'venta', 'numero' => 999999],
            // Misión asistente-fotos-barras-y-compras (24/9/2026): la búsqueda por código de barras.
            // Un código con el verificador roto a propósito: corta en la validación GS1 y contesta
            // sin salir a la red (el camino entero lo cubre Busqueda_por_codigo_de_barras_Test).
            'buscar_producto_por_codigo_de_barras'     => ['codigo' => '7798111212033'],
        ];

        $nombres = $this->service->nombres_de_lectura();

        // Si alguien suma una tool y no le pone su caso acá, el test lo dice; no la saltea.
        $this->assertEquals(
            $nombres,
            array_keys($inputs),
            'Toda tool de lectura declarada tiene que tener su caso de ejecución en este test, en el mismo orden.'
        );

        $bloques = [];
        $i = 0;

        foreach ($nombres as $nombre) {
            $i++;
            $bloques[] = [
                'type'  => 'tool_use',
                'id'    => 'toolu_lectura_' . $i,
                'name'  => $nombre,
                'input' => $inputs[$nombre],
            ];
        }

        $resultados = $this->service->execute_tool_calls($bloques, $this->conversation);

        $this->assertCount(count($nombres), $resultados);

        foreach ($resultados as $indice => $resultado) {
            $nombre = $nombres[$indice];

            $this->assertArrayNotHasKey(
                'is_error',
                $resultado,
                'La tool ' . $nombre . ' volvió con error: ' . $resultado['content']
            );

            // El content siempre es JSON: si json_encode falló, el fallback es '[]' y el request
            // siguiente del loop se rompería con un 400 críptico.
            $this->assertNotNull(
                json_decode($resultado['content'], true),
                'La tool ' . $nombre . ' no devolvió JSON decodificable.'
            );
        }
    }

    /**
     * 🔴 La tool de cuenta corriente apunta a la consulta NUEVA, no a la vieja. Si volviera a
     * apuntar al método de antes, el síntoma sería exactamente el caso que originó la misión: la
     * venta vieja fuera de la ventana de 20 y el asistente contestando que no hay ninguna.
     *
     * @group chat-ia
     * @test
     */
    public function la_tool_de_cuenta_corriente_apunta_a_la_consulta_con_ventana_y_filtro_de_cuenta()
    {
        $cliente = Client::create(['name' => 'Cliente tools B cuenta', 'user_id' => $this->comercio->id]);

        $venta = Sale::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'total'      => 1000,
            'created_at' => now()->subDays(300),
        ]);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'sale_id'    => $venta->id,
            'detalle'    => 'Venta tools B vieja',
            'debe'       => 1000,
            'saldo'      => 1000,
            'status'     => 'sin_pagar',
            'created_at' => now()->subDays(300),
        ]);

        for ($i = 1; $i <= 22; $i++) {
            CurrentAcount::create([
                'user_id'    => $this->comercio->id,
                'client_id'  => $cliente->id,
                'detalle'    => 'Pago tools B ' . $i,
                'haber'      => 10,
                'saldo'      => 1000 - ($i * 10),
                'status'     => 'pago_from_client',
                'created_at' => now()->subDays(22 - $i),
            ]);
        }

        $resultados = $this->service->execute_tool_calls([
            [
                'type'  => 'tool_use',
                'id'    => 'toolu_cc_01',
                'name'  => 'consultar_movimientos_de_cuenta_corriente',
                'input' => ['client_id' => $cliente->id, 'orden' => 'mas_viejos'],
            ],
        ], $this->conversation);

        $datos = json_decode($resultados[0]['content'], true);

        // El sobre con los totales es lo que distingue a la consulta nueva de la vieja, que
        // devolvía un array plano de filas.
        $this->assertArrayHasKey('movimientos_encontrados', $datos);
        $this->assertArrayHasKey('cuentas', $datos);
        $this->assertArrayHasKey('paginas', $datos);
        $this->assertEquals(23, $datos['movimientos_encontrados'], 'La venta vieja más los 22 pagos.');

        // Y con el orden ascendente, la venta vieja es la PRIMERA: es la respuesta que el asistente
        // no podía dar.
        $this->assertEquals('Venta tools B vieja', $datos['movimientos'][0]['detalle']);
        $this->assertEquals((int) $venta->id, $datos['movimientos'][0]['venta_id']);
    }

    /**
     * Las tools genéricas NO llevan enum de entidades, y la validación vive en el handler.
     *
     * Hasta la misión asistente-omnisciente (21/9/2026) este test afirmaba lo contrario: que el
     * enum de `consultar_datos` y de `que_puedo_consultar` era exactamente la whitelist. Con el
     * catálogo derivado del esquema son ciento y pico de nombres, y el enum pesaba más que el resto
     * del bloque de definiciones —que viaja entero en cada vuelta del loop—. El enum se sacó a
     * propósito; lo que se fija ahora es que el handler rechace una entidad inexistente con la
     * lista, que es la validación que el enum hacía del lado de la API.
     *
     * @group chat-ia
     * @test
     */
    public function el_esquema_de_las_tools_genericas_no_lleva_enum_y_el_handler_valida_la_entidad()
    {
        $definiciones = [];

        foreach ($this->service->herramientas_de_lectura() as $herramienta) {
            $definiciones[$herramienta['name']] = $herramienta;
        }

        foreach (['consultar_datos', 'que_puedo_consultar', 'resumir_datos'] as $generica) {
            $this->assertArrayNotHasKey(
                'enum',
                $definiciones[$generica]['input_schema']['properties']['entidad'],
                $generica . ': el enum de entidades se sacó a propósito, no vuelve.'
            );
        }

        $this->assertArrayHasKey('buscar', $definiciones['que_puedo_consultar']['input_schema']['properties']);
        $this->assertArrayHasKey('campos', $definiciones['consultar_datos']['input_schema']['properties']);

        $resultados = $this->service->execute_tool_calls([
            ['type' => 'tool_use', 'id' => 'toolu_enum_01', 'name' => 'consultar_datos', 'input' => ['entidad' => 'facturas_de_marte']],
        ], $this->conversation);

        $datos = json_decode($resultados[0]['content'], true);

        $this->assertArrayHasKey('error', $datos, 'Una entidad inexistente corta en el handler.');
        $this->assertEquals(CatalogoDeDatosIaHelper::entidades(), $datos['entidades_validas']);

        // Y la definición que viaja a la API nunca lleva el handler, que es un Closure y no se
        // serializa a JSON.
        foreach ($this->service->herramientas_de_lectura() as $herramienta) {
            $this->assertArrayNotHasKey('handler', $herramienta, $herramienta['name']);
        }
    }

    /**
     * 🔴 LAS DESCRIPCIONES TIENEN QUE DECIR DE QUÉ HABLAN. Es la causa raíz del caso 2 de las
     * capturas: el modelo eligió una herramienta de la TIENDA ONLINE para una pregunta sobre las
     * VENTAS DEL ERP, porque era la única con esa forma. Las dos de la tienda tienen que decirlo y
     * derivar a la del ERP; y las que se pisan entre sí (precios ofertados contra compras reales)
     * tienen que nombrarse mutuamente.
     *
     * @group chat-ia
     * @test
     */
    public function las_descripciones_distinguen_la_tienda_del_erp_y_derivan_a_la_correcta()
    {
        $descripciones = [];

        foreach ($this->service->herramientas_de_lectura() as $herramienta) {
            $descripciones[$herramienta['name']] = $herramienta['description'];
        }

        foreach (['consultar_actividad_de_un_cliente', 'consultar_interesados_en_un_articulo'] as $de_la_tienda) {
            $this->assertStringContainsString(
                'TIENDA ONLINE, NO EL ERP',
                $descripciones[$de_la_tienda],
                $de_la_tienda . ' tiene que declarar que mira la tienda.'
            );

            $this->assertStringContainsString(
                'consultar_quien_compro_un_articulo',
                $descripciones[$de_la_tienda],
                $de_la_tienda . ' tiene que derivar a la del ERP.'
            );
        }

        // Precios OFERTADOS contra compras REALES: el caso 3 de las capturas.
        $this->assertStringContainsString(
            'consultar_compras_de_un_articulo',
            $descripciones['consultar_precios_de_proveedores'],
            'La de precios ofertados tiene que derivar a la de compras reales.'
        );

        $this->assertStringContainsString(
            'consultar_precios_de_proveedores',
            $descripciones['consultar_compras_de_un_articulo'],
            'La de compras reales tiene que aclarar que la otra son precios ofertados.'
        );

        // Y la de más vendidos, que agrupa por artículo y descarta el cliente.
        $this->assertStringContainsString(
            'consultar_quien_compro_un_articulo',
            $descripciones['consultar_articulos_mas_vendidos'],
            'La de más vendidos tiene que derivar cuando la pregunta es POR QUIÉN.'
        );
    }

    /**
     * El prompt no puede seguir enseñándole al modelo que 20 es un techo que no se puede correr:
     * con paginación y totales, ese renglón lo hacía disculparse por un límite que ya no tiene.
     *
     * @group chat-ia
     * @test
     */
    public function el_prompt_le_dice_al_modelo_que_puede_pedir_mas_paginas()
    {
        $prompt = $this->service->build_system_prompt($this->conversation, $this->comercio, false);

        $this->assertStringContainsString('la página siguiente', $prompt);
        $this->assertStringContainsString('en_esta_lista', $prompt);

        $this->assertStringNotContainsString(
            'Las herramientas devuelven como máximo 20 registros.',
            $prompt,
            'Ese renglón quedó viejo: varias consultas paginan y dicen el total.'
        );
    }
}
