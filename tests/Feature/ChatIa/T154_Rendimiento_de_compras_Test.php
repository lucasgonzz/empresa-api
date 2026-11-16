<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\RendimientoDeComprasIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261116, rama A, 30/10 #11 — «de lo que compré este mes, ¿cuánto quedó en stock?».
 *
 * 🔴 EL CASO REAL (Ferre Total, 30/10/2026): el agente dijo que el sistema no guarda qué se compró y cuándo.
 * consultar_rendimiento_de_compras cruza, por artículo comprado en el período, lo comprado, lo vendido desde
 * la compra, el stock de hoy y lo que no rotó (con su valor a costo), en SQL.
 *
 * Escenario (compras de hace 5 días; período: los últimos 10 días):
 *  - Tornillo: 100 comprados (received sin completar → amount), costo 10, stock 70, 30 vendidos después de
 *    la compra y 50 ANTES (no cuentan) → sin rotar 70, valor 700.
 *  - Cable: 20 pedidos y 15 recibidos (manda received), costo 100 con 10 % de descuento en la compra, stock 5,
 *    12 vendidos → sin rotar min(5, 3) = 3, valor 300; invertido 15 × 100 × 0,9 = 1.350.
 *  - Pintura: 10 comprados, sin costo, stock 10, nada vendido → sin rotar 10, valor null.
 *  - Una compra de antes del período y una de otro comercio: afuera.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class T154_Rendimiento_de_compras_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var array<string, Article> */
    protected $articulos = [];

    /** @var array<string, int> */
    protected $proveedores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio T154',
            'email'    => 't154-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->proveedores['ferretera'] = DB::table('providers')->insertGetId(['name' => 'Ferretera T154', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->proveedores['cables'] = DB::table('providers')->insertGetId(['name' => 'Cables del Sur T154', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->articulos['tornillo'] = Article::create(['name' => 'Tornillo T154', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 10, 'stock' => 70, 'provider_id' => $this->proveedores['ferretera']]);
        $this->articulos['cable'] = Article::create(['name' => 'Cable T154', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 100, 'stock' => 5, 'provider_id' => $this->proveedores['cables']]);
        $this->articulos['pintura'] = Article::create(['name' => 'Pintura T154', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => null, 'stock' => 10, 'provider_id' => $this->proveedores['ferretera']]);
        $this->articulos['vieja'] = Article::create(['name' => 'Compra vieja T154', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 50, 'stock' => 40]);

        $hace_cinco = now()->subDays(5);

        $this->compra($this->proveedores['ferretera'], $hace_cinco, [
            [$this->articulos['tornillo'], 100, null, 10, null],
            [$this->articulos['pintura'], 10, null, null, null],
        ]);
        $this->compra($this->proveedores['cables'], $hace_cinco->copy()->addHour(), [
            [$this->articulos['cable'], 20, 15, 100, 10],
        ]);
        // Antes del período: no entra.
        $this->compra($this->proveedores['ferretera'], now()->subDays(40), [[$this->articulos['vieja'], 40, null, 50, null]]);

        // Ventas: 50 tornillos ANTES de la compra (no cuentan), 30 después; 12 cables después.
        $this->venta(now()->subDays(8), [$this->articulos['tornillo']->id => 50]);
        $this->venta(now()->subDays(3), [$this->articulos['tornillo']->id => 30, $this->articulos['cable']->id => 12]);
        // No terminada y borrada: no cuentan.
        $this->venta(now()->subDays(2), [$this->articulos['tornillo']->id => 9], ['terminada' => 0]);
        $this->venta(now()->subDays(2), [$this->articulos['tornillo']->id => 9])->delete();

        // Otro comercio que compró lo mismo: no se mezcla.
        $otro = User::create(['name' => 'Otro T154', 'email' => 't154-otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $prov_otro = DB::table('providers')->insertGetId(['name' => 'Ajeno T154', 'user_id' => $otro->id, 'created_at' => now(), 'updated_at' => now()]);
        $ajeno = Article::create(['name' => 'Ajeno T154', 'user_id' => $otro->id, 'status' => 'active', 'cost' => 1, 'stock' => 999]);
        $orden_ajena = DB::table('provider_orders')->insertGetId(['provider_id' => $prov_otro, 'user_id' => $otro->id, 'created_at' => $hace_cinco, 'updated_at' => $hace_cinco]);
        DB::table('article_provider_order')->insert(['article_id' => $ajeno->id, 'provider_order_id' => $orden_ajena, 'amount' => 999, 'received' => null, 'cost' => 1]);
    }

    /**
     * 🔴 Las cuentas, los totales y el orden por la plata parada.
     *
     * @group chat-ia
     * @test
     */
    public function cruza_lo_comprado_con_lo_vendido_y_el_stock()
    {
        $respuesta = $this->consultar($this->comercio);

        $this->assertArrayNotHasKey('error', $respuesta, json_encode($respuesta));
        $this->assertSame(3, $respuesta['articulos_comprados']);
        $this->assertEquals(125.0, $respuesta['unidades_compradas'], '100 + 15 recibidos + 10.');
        $this->assertEquals(42.0, $respuesta['unidades_vendidas_desde_la_compra'], '30 + 12: las de antes de la compra, la no terminada y la borrada no.');
        $this->assertEquals(83.0, $respuesta['unidades_sin_rotar'], '70 + 3 + 10.');
        $this->assertEquals(1000.0, $respuesta['valor_sin_rotar_a_costo'], '70 × 10 + 3 × 100; la pintura no tiene costo.');
        $this->assertEquals(2350.0, $respuesta['invertido_en_pesos'], '100 × 10 + 15 × 100 × 0,9.');
        $this->assertSame(1, $respuesta['articulos_sin_rotar_sin_costo']);

        $this->assertSame(
            ['Tornillo T154', 'Cable T154', 'Pintura T154'],
            array_column($respuesta['articulos'], 'nombre'),
            'Ordenado por valor sin rotar; el que no tiene costo, al final.'
        );

        $tornillo = $respuesta['articulos'][0];
        $this->assertEquals(100.0, $tornillo['comprado']);
        $this->assertEquals(30.0, $tornillo['vendido_desde_la_compra']);
        $this->assertEquals(70.0, $tornillo['stock_actual']);
        $this->assertEquals(70.0, $tornillo['sin_rotar']);
        $this->assertEquals(700.0, $tornillo['valor_sin_rotar']);
        $this->assertEquals(30.0, $tornillo['porcentaje_vendido']);
        $this->assertSame((int) $this->articulos['tornillo']->id, $tornillo['article_id']);

        $cable = $respuesta['articulos'][1];
        $this->assertEquals(15.0, $cable['comprado'], 'Manda lo recibido.');
        $this->assertEquals(3.0, $cable['sin_rotar']);

        $this->assertNull($respuesta['articulos'][2]['valor_sin_rotar']);
        $this->assertStringContainsString('no lo cuentes como cero', $respuesta['nota']);
    }

    /**
     * Por proveedor, y un proveedor que no existe es error.
     *
     * @group chat-ia
     * @test
     */
    public function acota_por_proveedor()
    {
        $respuesta = $this->consultar($this->comercio, ['proveedor' => 'cables del sur']);

        $this->assertSame('Cables del Sur T154', $respuesta['proveedor']);
        $this->assertSame(['Cable T154'], array_column($respuesta['articulos'], 'nombre'));

        $no = $this->consultar($this->comercio, ['proveedor' => 'Inexistente T154']);
        $this->assertFalse($no['ok']);
    }

    /**
     * 🔴 Sin permiso de costo no viaja la plata y se ordena por unidades.
     *
     * @group chat-ia
     * @test
     */
    public function sin_permiso_de_costo_van_las_unidades_sin_plata()
    {
        $empleado = User::create([
            'name'       => 'Empleado T154',
            'email'      => 't154-emp-' . uniqid() . '@test.local',
            'doc_number' => 'DOC-T154-' . uniqid(),
            'password'   => Hash::make('secret'),
            'owner_id'   => $this->comercio->id,
        ]);

        $permiso = PermissionEmpresa::where('slug', 'sale.index')->first();

        if (is_null($permiso)) {
            $permiso = PermissionEmpresa::forceCreate(['name' => 'T154 ventas', 'slug' => 'sale.index', 'model_name' => 'sale']);
        }

        $empleado->permissions()->sync([$permiso->id]);

        $respuesta = $this->consultar($empleado);

        $this->assertArrayNotHasKey('valor_sin_rotar_a_costo', $respuesta);
        $this->assertArrayNotHasKey('invertido_en_pesos', $respuesta);
        $this->assertArrayHasKey('sin_costos', $respuesta);
        $this->assertSame('sin_rotar', $respuesta['ordenado_por']);
        $this->assertSame(['Tornillo T154', 'Pintura T154', 'Cable T154'], array_column($respuesta['articulos'], 'nombre'));

        foreach ($respuesta['articulos'] as $articulo) {
            $this->assertArrayNotHasKey('costo_unitario', $articulo);
            $this->assertArrayNotHasKey('valor_sin_rotar', $articulo);
        }
    }

    /**
     * Con depósito: las compras, las ventas y el stock de esa sucursal.
     *
     * @group chat-ia
     * @test
     */
    public function con_deposito_son_las_compras_ventas_y_stock_de_esa_sucursal()
    {
        $centro = DB::table('addresses')->insertGetId(['street' => 'Centro T154', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);
        $tuerca = Article::create(['name' => 'Tuerca T154', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 2, 'stock' => 500]);

        $this->compra($this->proveedores['ferretera'], now()->subDays(4), [[$tuerca, 100, null, 2, null]], $centro);
        $this->compra($this->proveedores['ferretera'], now()->subDays(4), [[$tuerca, 300, null, 2, null]]);

        DB::table('address_article')->insert(['article_id' => $tuerca->id, 'address_id' => $centro, 'amount' => 80, 'created_at' => now(), 'updated_at' => now()]);

        $this->venta(now()->subDays(2), [$tuerca->id => 25], ['address_id' => $centro]);
        $this->venta(now()->subDays(2), [$tuerca->id => 100]);

        $respuesta = $this->consultar($this->comercio, ['deposito' => 'centro']);

        $this->assertSame('Centro T154', $respuesta['deposito']);
        $this->assertSame(['Tuerca T154'], array_column($respuesta['articulos'], 'nombre'));

        $fila = $respuesta['articulos'][0];
        $this->assertEquals(100.0, $fila['comprado']);
        $this->assertEquals(25.0, $fila['vendido_desde_la_compra']);
        $this->assertEquals(80.0, $fila['stock_actual']);
        $this->assertEquals(75.0, $fila['sin_rotar'], 'min(80, 100 − 25).');
    }

    /**
     * La tool corre por el registro de lectura con la conversación del dueño.
     *
     * @group chat-ia
     * @test
     */
    public function corre_por_el_registro_de_lectura()
    {
        $service = new AsistenteIaService();
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_t154',
            'name'  => 'consultar_rendimiento_de_compras',
            'input' => ['desde' => now()->subDays(10)->format('Y-m-d'), 'hasta' => now()->format('Y-m-d'), 'limite' => 1],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame(3, $contenido['articulos_comprados']);
        $this->assertSame(1, $contenido['articulos_en_esta_lista']);
        $this->assertSame('Tornillo T154', $contenido['articulos'][0]['nombre']);

        $nombres = $service->nombres_de_lectura();
        $this->assertSame('consultar_rendimiento_de_compras', end($nombres), 'Al final del registro (prefijo del caché).');
    }

    // ---------------------------------------------------------------------

    /**
     * @param  User  $persona
     * @param  array  $extra
     * @return array
     */
    protected function consultar(User $persona, array $extra = [])
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $persona->id]);

        return RendimientoDeComprasIaHelper::consultar($this->comercio->id, $conversation, array_merge([
            'desde' => now()->subDays(10)->format('Y-m-d'),
            'hasta' => now()->format('Y-m-d'),
        ], $extra));
    }

    /**
     * Una compra con sus renglones: [artículo, amount, received, costo, descuento %].
     *
     * @param  int  $provider_id
     * @param  Carbon  $momento
     * @param  array  $renglones
     * @param  int|null  $address_id
     * @return int
     */
    protected function compra($provider_id, Carbon $momento, array $renglones, $address_id = null)
    {
        $id = DB::table('provider_orders')->insertGetId([
            'provider_id' => $provider_id,
            'user_id'     => $this->comercio->id,
            'address_id'  => $address_id,
            'moneda_id'   => 1,
            'created_at'  => $momento,
            'updated_at'  => $momento,
        ]);

        foreach ($renglones as $r) {
            DB::table('article_provider_order')->insert([
                'article_id'        => $r[0]->id,
                'provider_order_id' => $id,
                'amount'            => $r[1],
                'received'          => $r[2],
                'cost'              => $r[3],
                'discount'          => $r[4],
            ]);
        }

        return $id;
    }

    /**
     * Una venta terminada con sus renglones (article_id => cantidad).
     *
     * @param  Carbon  $momento
     * @param  array  $renglones
     * @param  array  $extra
     * @return Sale
     */
    protected function venta(Carbon $momento, array $renglones, array $extra = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $this->comercio->id,
            'total'      => 1,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));

        foreach ($renglones as $article_id => $cantidad) {
            $venta->articles()->attach($article_id, ['amount' => $cantidad, 'price' => 1, 'cost' => 0]);
        }

        return $venta;
    }
}
