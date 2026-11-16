<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ValorizacionDeStockIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\Category;
use App\Models\PermissionEmpresa;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P5 — valorizar_stock.
 *
 * EL CASO REAL (V9, Pesca, conv 20): «cuánta plata hay de venta con todo el stock del depósito». El
 * agente no tenía con qué sumar stock × precio y lo hacía de cabeza sobre páginas de 100; en V16 cambió
 * las cifras entre una respuesta y la otra. Lo que protege este archivo:
 *
 *  - la herramienta existe en los tres canales, al final del registro de lectura;
 *  - los totales salen del servidor sobre TODOS los artículos activos con stock > 0 (los inactivos y el
 *    stock negativo no suman), con el costo en dólares pasado a pesos y los sin costo afuera del valor a
 *    costo (contados aparte);
 *  - por rubro, por proveedor y por depósito; con depósito, el stock es el de ese depósito;
 *  - la auditoría de precios (V16): con stock sin precio, sin costo y con precio menor o igual al costo;
 *  - 🔴 un empleado sin el permiso de costos no ve NADA del costo, y el que ve solo su sucursal ve solo
 *    el stock de su sucursal;
 *  - los filtros son los de contar_articulos_por_filtro (proveedor por nombre).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class Valorizar_stock_desde_el_servidor_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** @var Provider */
    protected $albatros;

    /** @var Provider */
    protected $importador;

    /** @var Category */
    protected $canas;

    /** @var Category */
    protected $reeles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['dollar' => 1000]);

        $this->albatros = Provider::create(['name' => 'Albatros ZZV', 'user_id' => $this->dueno->id]);
        $this->importador = Provider::create(['name' => 'Importador ZZV', 'user_id' => $this->dueno->id]);
        DB::table('providers')->where('id', $this->importador->id)->update(['dolar' => 1200]);

        $this->canas = Category::create(['name' => 'Cañas ZZV', 'user_id' => $this->dueno->id]);
        $this->reeles = Category::create(['name' => 'Reeles ZZV', 'user_id' => $this->dueno->id]);

        // Albatros, cañas: 10 × (costo 100, precio 150) y 5 × (costo 200, precio 300).
        $this->articulo_valorizado('Caña Okinawa 210 ZZV', 10, 100, 150, $this->albatros, $this->canas);
        $this->articulo_valorizado('Caña Bronco 180 ZZV', 5, 200, 300, $this->albatros, $this->canas);
        // Albatros, reel SIN costo: suma al valor a precio, no al de costo.
        $this->articulo_valorizado('Reel Bronze ZZV', 2, null, 1000, $this->albatros, $this->reeles);
        // Albatros, reel con precio menor que el costo (V16).
        $this->articulo_valorizado('Reel Barato ZZV', 1, 500, 400, $this->albatros, $this->reeles);
        // Importador: costo en DÓLARES (2 USD × 1200 del proveedor = 2400 por unidad), 3 unidades.
        $this->articulo_valorizado('Reel Importado ZZV', 3, 2, 5000, $this->importador, $this->reeles, ['cost_in_dollars' => 1]);
        // Con stock y SIN precio.
        $this->articulo_valorizado('Señuelo sin precio ZZV', 4, 50, null, $this->importador, $this->reeles);
        // No suman: stock negativo, stock cero e inactivo.
        $this->articulo_valorizado('Anzuelo negativo ZZV', -3, 10, 20, $this->albatros, $this->canas);
        $this->articulo_valorizado('Plomada sin stock ZZV', 0, 10, 20, $this->albatros, $this->canas);
        $this->articulo_valorizado('Caña vieja inactiva ZZV', 100, 100, 100, $this->albatros, $this->canas, ['status' => 'inactive']);
    }

    /**
     * Un artículo con sus valores EXACTOS (el precio se escribe después del alta para que ningún cálculo lo mueva).
     */
    protected function articulo_valorizado($nombre, $stock, $costo, $precio, Provider $proveedor, Category $rubro, array $extras = [])
    {
        $articulo = Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'stock'       => $stock,
            'cost'        => $costo,
            'provider_id' => $proveedor->id,
            'category_id' => $rubro->id,
        ]);

        DB::table('articles')->where('id', $articulo->id)->update(array_merge([
            'final_price' => $precio,
            'cost'        => $costo,
            'stock'       => $stock,
        ], $extras));

        return $articulo->fresh();
    }

    /**
     * Corre valorizar_stock por el despacho real del loop.
     */
    protected function valorizar(array $input, $conversation = null)
    {
        list($conversation, $assistant) = $this->vuelta('cuánta plata hay de venta con todo el stock', $conversation);

        $resultados = (new AsistenteIaService())->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_valorizar_' . uniqid(),
            'name'  => 'valorizar_stock',
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertCount(1, $resultados);
        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Un empleado del negocio, con los permisos que se le pasen.
     */
    protected function empleado(array $slugs, $address_id = null)
    {
        $empleado = User::create([
            'name'       => 'Empleado ZZV',
            'email'      => 'zzv-empleado-' . uniqid() . '@test.local',
            'password'   => Hash::make('secret'),
            'owner_id'   => $this->dueno->id,
            'address_id' => $address_id,
        ]);

        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (!$permiso) {
                $permiso = PermissionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
            }

            $empleado->permissions()->attach($permiso->id);
        }

        return AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $empleado->id]);
    }

    /**
     * @test
     */
    public function valorizar_stock_esta_en_los_tres_canales_al_final_del_registro_de_lectura()
    {
        $servicio = new AsistenteIaService();

        $lectura = $servicio->nombres_de_lectura();

        $this->assertContains('valorizar_stock', $lectura);
        $this->assertSame('buscar_imagen_de_bebida', $lectura[array_search('valorizar_stock', $lectura, true) - 1], 'Al final de lo que había: el prefijo del caché no cambia.');

        $this->assertContains('valorizar_stock', array_column($servicio->build_tools(false, false), 'name'), 'Sistema, sin acciones.');
        $this->assertContains('valorizar_stock', array_column($servicio->build_tools(true, true, $this->dueno), 'name'), 'WhatsApp.');
        $this->assertContains('valorizar_stock', array_column($servicio->build_tools(true, false, $this->dueno, true), 'name'), 'La app.');
    }

    /**
     * 🔴 V9: el total sale del servidor sobre todos los artículos activos con stock positivo.
     *
     * @test
     */
    public function el_total_del_stock_lo_calcula_el_servidor()
    {
        $r = $this->valorizar([]);

        $this->assertTrue($r['ok'], json_encode($r));

        // Con stock > 0 y activos: Okinawa, Bronco, Bronze, Barato, Importado, Señuelo.
        $this->assertSame(6, $r['articulos_con_stock']);
        $this->assertEquals(25, $r['unidades_en_stock']);

        // Precio: 10×150 + 5×300 + 2×1000 + 1×400 + 3×5000 + 4×0 = 20400.
        $this->assertEquals(20400, $r['valor_a_precio']);
        // Costo: 10×100 + 5×200 + 1×500 + 3×(2×1200) + 4×50 = 9900 (el Bronze sin costo queda afuera).
        $this->assertEquals(9900, $r['valor_a_costo']);
        // Ganancia potencial: precio de los que tienen costo (18400) − costo (9900).
        $this->assertEquals(8500, $r['ganancia_potencial']);

        $this->assertSame(1, $r['con_stock_sin_costo']);
        $this->assertSame(1, $r['con_stock_sin_precio']);
        // V16: el Barato (400 ≤ 500) y el Señuelo sin precio (0 ≤ 50).
        $this->assertSame(2, $r['con_stock_y_precio_menor_o_igual_al_costo']);
        $this->assertSame(1, $r['con_stock_negativo']);
        $this->assertStringContainsString('sin recalcular', $r['nota']);
    }

    /**
     * Por rubro y por proveedor, de mayor a menor valor; y con un filtro de proveedor por nombre.
     *
     * @test
     */
    public function agrupa_por_rubro_y_por_proveedor_y_filtra_por_proveedor()
    {
        $r = $this->valorizar(['agrupar_por' => 'rubro']);

        $this->assertSame('rubro', $r['agrupado_por']);
        $this->assertSame(['Reeles ZZV', 'Cañas ZZV'], array_column($r['grupos'], 'nombre'));
        $this->assertEquals(17400, $r['grupos'][0]['valor_a_precio']);
        $this->assertEquals(3000, $r['grupos'][1]['valor_a_precio']);
        $this->assertEquals(2000, $r['grupos'][1]['valor_a_costo']);

        $r = $this->valorizar(['agrupar_por' => 'proveedor']);

        $this->assertSame(['Importador ZZV', 'Albatros ZZV'], array_column($r['grupos'], 'nombre'));
        $this->assertEquals(7400, $r['grupos'][0]['valor_a_costo']);

        // V16: «los precios de Albatros», con el filtro de siempre (proveedor por NOMBRE).
        $r = $this->valorizar(['filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Albatros ZZV']]]);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(4, $r['articulos_con_stock']);
        $this->assertEquals(5400, $r['valor_a_precio']);
        $this->assertSame(1, $r['con_stock_y_precio_menor_o_igual_al_costo']);
        $this->assertArrayNotHasKey('promociones_de_vinoteca', $r);
    }

    /**
     * Con depósito, el stock es el de ese depósito; y por depósito, uno por sucursal.
     *
     * @test
     */
    public function por_deposito_usa_el_stock_de_cada_deposito()
    {
        $central = Address::create(['street' => 'Central ZZV', 'user_id' => $this->dueno->id]);
        $costa = Address::create(['street' => 'Depósito Costa ZZV', 'user_id' => $this->dueno->id]);

        $okinawa = Article::where('name', 'Caña Okinawa 210 ZZV')->first();
        $okinawa->addresses()->attach($central->id, ['amount' => 6]);
        $okinawa->addresses()->attach($costa->id, ['amount' => 4]);

        $r = $this->valorizar(['deposito' => 'deposito costa']);

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('Depósito Costa ZZV', $r['deposito']);
        $this->assertSame(1, $r['articulos_con_stock']);
        $this->assertEquals(600, $r['valor_a_precio']);

        $r = $this->valorizar(['agrupar_por' => 'deposito']);

        $this->assertSame(['Central ZZV', 'Depósito Costa ZZV'], array_column($r['grupos'], 'nombre'));
        $this->assertEquals(900, $r['grupos'][0]['valor_a_precio']);

        $r = $this->valorizar(['deposito' => 'Galpón que no existe']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Central ZZV', $r['error']);
    }

    /**
     * 🔴 Sin el permiso de costos, nada del costo; con «solo su sucursal», solo su sucursal.
     *
     * @test
     */
    public function el_empleado_ve_solo_lo_que_la_pantalla_le_deja_ver()
    {
        $sin_costo = $this->empleado([]);

        $r = $this->valorizar([], $sin_costo);

        $this->assertTrue($r['ok']);
        $this->assertEquals(20400, $r['valor_a_precio']);
        $this->assertArrayNotHasKey('valor_a_costo', $r);
        $this->assertArrayNotHasKey('ganancia_potencial', $r);
        $this->assertArrayNotHasKey('con_stock_sin_costo', $r);
        $this->assertStringContainsString('NO tiene permiso para ver costos', $r['nota']);

        $con_costo = $this->empleado([ValorizacionDeStockIaHelper::PERMISO_COSTO]);

        $this->assertEquals(9900, $this->valorizar([], $con_costo)['valor_a_costo']);

        $central = Address::create(['street' => 'Central ZZV', 'user_id' => $this->dueno->id]);
        $costa = Address::create(['street' => 'Costa ZZV', 'user_id' => $this->dueno->id]);
        Article::where('name', 'Caña Okinawa 210 ZZV')->first()->addresses()->attach($costa->id, ['amount' => 4]);
        Article::where('name', 'Caña Bronco 180 ZZV')->first()->addresses()->attach($central->id, ['amount' => 5]);

        $de_la_costa = $this->empleado([FichaArticuloIaHelper::SOLO_MI_SUCURSAL], $costa->id);

        $r = $this->valorizar([], $de_la_costa);

        $this->assertSame('Costa ZZV', $r['deposito']);
        $this->assertEquals(600, $r['valor_a_precio'], 'Solo los 4 de la Costa, nunca el total del negocio.');

        $r = $this->valorizar(['deposito' => 'Central ZZV'], $de_la_costa);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('solo puede ver el stock de su sucursal', $r['error']);
    }
}
