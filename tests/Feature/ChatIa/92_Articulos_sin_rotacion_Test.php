<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArticulosSinRotacionIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E2 punto 5, 29/10/2026) — la herramienta
 * consultar_articulos_sin_rotacion.
 *
 * El caso real: Pablo (Ferre Total, CONV 11) pidió «el top ten con menos rotación» y el agente lo armó
 * a mano sobre los renglones de venta: nombró seis diciendo cuatro, reescribió los nombres y después
 * no supo decir si tenían stock ni si alguna vez se habían comprado. Lo que protege:
 *  - devuelve los activos con stock y sin ventas en la ventana (90 días por defecto), con su
 *    article_id, la última venta (o nunca_se_vendio), la última compra y el valor inmovilizado;
 *  - `dias`, `solo_comprados`, `orden` (valor, días, stock) e `incluir_poco_vendidos` (los que menos
 *    vendieron, aparte);
 *  - la herramienta va AL FINAL del registro de lectura (caché del prompt) y entra al MCP como lectura.
 *
 * El Mostrador sigue con su default (90 días, 10, por valor): lo cubren los tests de Mostrador.
 */
class Articulos_sin_rotacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var array<string, Article> */
    protected $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio rotacion E2',
            'email'    => 'rotacion-e2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $proveedor = Provider::create(['name' => 'Bulonera rotacion E2', 'user_id' => $this->comercio->id]);

        // Nunca se vendió, se cargó hace 200 días y tiene UNA compra: valor 10 × 100 = 1.000.
        $this->articulos['tuerca'] = $this->articulo('TUERCA CUPLA ZINCADA 5/16 E2', 10, 100, 200);
        $this->compra($this->articulos['tuerca'], $proveedor, 150);

        // Se vendió hace 120 días, sin compras: valor 5 × 50 = 250.
        $this->articulos['sopapa'] = $this->articulo('SOPAPA QUICK CLACK 11/2 E2', 5, 50, 300);
        $this->venta($this->articulos['sopapa'], 1, 120);

        // Se vendió hace 10 días (3 unidades): rota poco.
        $this->articulos['tornillo'] = $this->articulo('TORNILLO DRYWALL 6 X 2 E2', 100, 1, 300);
        $this->venta($this->articulos['tornillo'], 3, 10);

        // Se vendió hace 5 días (50 unidades).
        $this->articulos['martillo'] = $this->articulo('MARTILLO CARPINTERO E2', 2, 900, 300);
        $this->venta($this->articulos['martillo'], 50, 5);

        // Sin stock y nunca vendido: no está "inmovilizado".
        $this->articulos['clavo'] = $this->articulo('CLAVO PUNTA PARIS E2', 0, 10, 300);

        // Inactivo con stock: no cuenta.
        $this->articulos['viejo'] = $this->articulo('ARTICULO VIEJO E2', 5, 10, 300, 'inactive');
    }

    /**
     * @param  string  $nombre
     * @param  float  $stock
     * @param  float  $costo
     * @param  int  $dias_desde_el_alta
     * @param  string  $status
     * @return Article
     */
    protected function articulo($nombre, $stock, $costo, $dias_desde_el_alta, $status = 'active')
    {
        $articulo = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
            'status'  => $status,
            'stock'   => $stock,
            'cost'    => $costo,
        ]);

        DB::table('articles')->where('id', $articulo->id)->update(['created_at' => now()->subDays($dias_desde_el_alta)]);

        return $articulo->fresh();
    }

    /**
     * Una venta real con su fila de article_purchases (lo que mira el cálculo de rotación).
     *
     * @param  Article  $articulo
     * @param  float  $unidades
     * @param  int  $dias
     * @return void
     */
    protected function venta(Article $articulo, $unidades, $dias)
    {
        $venta = Sale::create(['user_id' => $this->comercio->id, 'total' => 100, 'terminada' => 1, 'moneda_id' => 1, 'created_at' => now()->subDays($dias)]);

        DB::table('article_purchases')->insert([
            'article_id' => $articulo->id,
            'sale_id'    => $venta->id,
            'amount'     => $unidades,
            'created_at' => now()->subDays($dias),
        ]);
    }

    /**
     * Una compra a un proveedor con un renglón de ese artículo.
     *
     * @param  Article  $articulo
     * @param  Provider  $proveedor
     * @param  int  $dias
     * @return void
     */
    protected function compra(Article $articulo, Provider $proveedor, $dias)
    {
        $compra_id = DB::table('provider_orders')->insertGetId([
            'num'         => 1,
            'provider_id' => $proveedor->id,
            'user_id'     => $this->comercio->id,
            'created_at'  => now()->subDays($dias),
        ]);

        DB::table('article_provider_order')->insert([
            'article_id'        => $articulo->id,
            'provider_order_id' => $compra_id,
            'amount'            => 20,
        ]);
    }

    /**
     * @param  array  $input
     * @return array
     */
    protected function consultar(array $input = [])
    {
        return ArticulosSinRotacionIaHelper::consultar($this->comercio->id, $input);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function devuelve_los_que_tienen_stock_y_no_se_vendieron_con_su_id_su_ultima_venta_y_su_ultima_compra()
    {
        $respuesta = $this->consultar();

        $this->assertSame(90, $respuesta['dias']);
        $this->assertSame(2, $respuesta['sin_rotacion_encontrados']);
        $this->assertSame(2, $respuesta['sin_rotacion_en_esta_lista']);
        $this->assertEquals(1250.0, $respuesta['valor_inmovilizado']);

        $tuerca = $respuesta['articulos'][0];
        $sopapa = $respuesta['articulos'][1];

        // Por valor inmovilizado: la tuerca (1.000) antes que la sopapa (250).
        $this->assertSame((int) $this->articulos['tuerca']->id, $tuerca['article_id']);
        $this->assertSame($this->articulos['tuerca']->name, $tuerca['nombre']);
        $this->assertEquals(10, $tuerca['stock']);
        $this->assertTrue($tuerca['nunca_se_vendio']);
        $this->assertNull($tuerca['ultima_venta']);
        $this->assertSame(1, $tuerca['compras']);
        $this->assertSame(now()->subDays(150)->format('Y-m-d'), $tuerca['ultima_compra']);
        $this->assertSame(200, $tuerca['dias_sin_venta'], 'Nunca vendido: desde el alta.');

        $this->assertSame((int) $this->articulos['sopapa']->id, $sopapa['article_id']);
        $this->assertFalse($sopapa['nunca_se_vendio']);
        $this->assertSame(now()->subDays(120)->format('Y-m-d'), $sopapa['ultima_venta']);
        $this->assertSame(0, $sopapa['compras']);
        $this->assertNull($sopapa['ultima_compra']);
        $this->assertSame(120, $sopapa['dias_sin_venta']);

        $this->assertArrayNotHasKey('poco_vendidos', $respuesta);
        $this->assertStringContainsString('article_id', $respuesta['nota']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function dias_solo_comprados_y_orden_cambian_el_conjunto_y_el_orden()
    {
        // «¿Pero son productos que alguna vez compré?»: solo la tuerca.
        $comprados = $this->consultar(['solo_comprados' => true]);
        $this->assertSame([(int) $this->articulos['tuerca']->id], array_column($comprados['articulos'], 'article_id'));
        $this->assertSame(1, $comprados['sin_rotacion_encontrados']);

        // Con 180 días la sopapa (vendida hace 120) ya rotó.
        $en_180 = $this->consultar(['dias' => 180]);
        $this->assertSame([(int) $this->articulos['tuerca']->id], array_column($en_180['articulos'], 'article_id'));

        // Con 30 días el tornillo y el martillo (vendidos hace 10 y 5) siguen rotando: los mismos dos.
        $en_30 = $this->consultar(['dias' => 30]);
        $this->assertSame(2, $en_30['sin_rotacion_encontrados']);

        // Una ventana fuera de la lista cae al default.
        $this->assertSame(90, $this->consultar(['dias' => 45])['dias']);

        // Por stock: la tuerca (10) y la sopapa (5).
        $por_stock = $this->consultar(['orden' => 'stock']);
        $this->assertSame([(int) $this->articulos['tuerca']->id, (int) $this->articulos['sopapa']->id], array_column($por_stock['articulos'], 'article_id'));

        // Por días sin venta: la tuerca (200, nunca vendida) antes que la sopapa (120).
        $por_dias = $this->consultar(['orden' => 'dias']);
        $this->assertSame([(int) $this->articulos['tuerca']->id, (int) $this->articulos['sopapa']->id], array_column($por_dias['articulos'], 'article_id'));

        // El límite.
        $uno = $this->consultar(['limite' => 1]);
        $this->assertCount(1, $uno['articulos']);
        $this->assertSame(2, $uno['sin_rotacion_encontrados'], 'El total no depende del límite.');
    }

    /**
     * «El top ten con menos rotación»: si los que no se vendieron nada no llegan a diez, se completa
     * con los que menos vendieron, aparte.
     *
     * @group chat-ia
     * @test
     */
    public function incluir_poco_vendidos_completa_el_limite_con_los_que_menos_vendieron()
    {
        $respuesta = $this->consultar(['incluir_poco_vendidos' => true, 'limite' => 10]);

        $this->assertCount(2, $respuesta['articulos']);

        $poco = $respuesta['poco_vendidos'];

        $this->assertSame([(int) $this->articulos['tornillo']->id, (int) $this->articulos['martillo']->id], array_column($poco, 'article_id'), 'De menos a más unidades: 3 y 50.');
        $this->assertEquals(3, $poco[0]['unidades_vendidas']);
        $this->assertSame(now()->subDays(10)->format('Y-m-d'), $poco[0]['ultima_venta']);
        $this->assertArrayHasKey('nota_poco_vendidos', $respuesta);

        // Con el límite cubierto por los sin venta, no se agrega nada.
        $cubierto = $this->consultar(['incluir_poco_vendidos' => true, 'limite' => 2]);
        $this->assertArrayNotHasKey('poco_vendidos', $cubierto);
    }

    /**
     * La herramienta va al final del registro de lectura, se ejecuta por el registro y entra al MCP.
     *
     * @group chat-ia
     * @test
     */
    public function la_herramienta_va_al_final_del_registro_de_lectura_y_entra_al_mcp()
    {
        $servicio = new AsistenteIaService();

        $nombres = $servicio->nombres_de_lectura();

        $this->assertSame('consultar_articulos_sin_rotacion', end($nombres), 'Al final: el orden del registro es el prefijo del caché.');
        $this->assertTrue($servicio->maneja_lectura('consultar_articulos_sin_rotacion'));
        $this->assertTrue(McpHerramientasHelper::existe('consultar_articulos_sin_rotacion'));

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $servicio->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'consultar_articulos_sin_rotacion',
            'input' => ['limite' => 10, 'solo_comprados' => true],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame([(int) $this->articulos['tuerca']->id], array_column($contenido['articulos'], 'article_id'));
    }
}
