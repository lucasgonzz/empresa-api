<?php

namespace Tests\Feature\Mostrador;

use App\Models\Caja;
use App\Models\CreditAccount;
use App\Models\ProviderOrder;
use App\Models\PurchaseSuggestion;
use App\Models\StockSuggestion;
use App\Services\Mostrador\RecolectorCompras;
use App\Services\Mostrador\RecolectorStock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión modulo-ia-mostrador — P4: los recolectores de "Compras" y "Stock".
 *
 * Compras: un artículo sin depósitos bajo su mínimo (con proveedor titular, último
 * costo con fecha y otro proveedor más barato), uno con depósito bajo el mínimo del
 * depósito, uno sin proveedor, uno que no hace falta reponer; la deuda con el
 * proveedor, su última compra, el disponible en cajas y la demanda sin stock de la
 * tienda. Todo sin dejar ninguna purchase_suggestion en la base.
 *
 * Stock: con una sola sucursal no aplica; con dos, un artículo desbalanceado con
 * ventas en el destino (cobertura de 4 días, prioridad 1) y otro sin ventas
 * (cobertura infinita, prioridad 2), más el yunque que no rota. Sin dejar ninguna
 * stock_suggestion en la base.
 */
class Recolector_compras_y_stock_Test extends MostradorTestCase
{
    /** @var Carbon Hoy a las 00:00 */
    protected $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::now()->startOfDay();
    }

    /**
     * @group mostrador
     * @test
     */
    public function compras_agrupa_por_proveedor_titular_con_el_mejor_precio_de_otro()
    {
        $acme = $this->proveedor_nuevo('Acme');
        $beta = $this->proveedor_nuevo('Beta');

        $norte = $this->sucursal('Norte');

        // Lija: sin depósitos, stock 2 bajo el mínimo 10 → faltan 8. Titular Acme a $50,
        // Beta la ofrece a $40.
        $lija = $this->articulo('Lija', ['stock' => 2, 'stock_min' => 10, 'provider_id' => $acme->id, 'cost' => 50]);
        $lija->providers()->attach($acme->id, ['cost' => 50]);

        DB::table('provider_price_offers')->insert([
            ['user_id' => $this->comercio->id, 'article_id' => $lija->id, 'provider_id' => $acme->id, 'cost' => 50, 'moneda_id' => 1, 'origen' => 'compra', 'fecha' => $this->hoy->copy()->subDays(20)->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $this->comercio->id, 'article_id' => $lija->id, 'provider_id' => $beta->id, 'cost' => 40, 'moneda_id' => 1, 'origen' => 'importacion', 'fecha' => $this->hoy->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Cinta: con depósito, 1 unidad bajo el mínimo 5 del depósito → faltan 4. Solo Acme, a $20.
        $cinta = $this->articulo('Cinta', ['stock' => 1, 'provider_id' => $acme->id, 'cost' => 20]);
        $cinta->addresses()->attach($norte->id, ['amount' => 1, 'stock_min' => 5]);
        $cinta->providers()->attach($acme->id, ['cost' => 20]);

        // Rulo: sin proveedor, en cero bajo el mínimo 3.
        $rulo = $this->articulo('Rulo', ['stock' => 0, 'stock_min' => 3, 'provider_id' => null, 'cost' => null]);

        // Pinza: sobrada, no entra.
        $this->articulo('Pinza', ['stock' => 100, 'stock_min' => 5, 'provider_id' => $acme->id]);

        // Deuda con Acme, y con un cliente; disponible en caja.
        CreditAccount::create(['model_name' => 'provider', 'model_id' => $acme->id, 'saldo' => 1500, 'moneda_id' => 1, 'user_id' => $this->comercio->id]);
        CreditAccount::create(['model_name' => 'client', 'model_id' => $this->cliente('Pérez')->id, 'saldo' => 200, 'moneda_id' => 1, 'user_id' => $this->comercio->id]);

        $caja = Caja::create(['num' => 1, 'name' => 'Principal', 'user_id' => $this->comercio->id, 'moneda_id' => 1]);
        $apertura_id = DB::table('apertura_cajas')->insertGetId(['caja_id' => $caja->id, 'saldo_apertura' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('movimiento_cajas')->insert(['caja_id' => $caja->id, 'apertura_caja_id' => $apertura_id, 'ingreso' => 1000, 'created_at' => now(), 'updated_at' => now()]);

        // Última compra a Acme hace 20 días.
        ProviderOrder::create([
            'user_id'     => $this->comercio->id,
            'provider_id' => $acme->id,
            'total'       => 3000,
            'created_at'  => $this->hoy->copy()->subDays(20)->setTime(10, 0),
        ]);

        // El rulo, en cero, lo abrieron dos veces en la tienda esta semana.
        foreach ([1, 2] as $dias) {
            $this->evento('product_view', $this->hoy->copy()->subDays($dias)->setTime(12, 0), ['article_id' => $rulo->id]);
        }

        $antes = PurchaseSuggestion::count();

        $h = (new RecolectorCompras())->recolectar($this->comercio, $this->hoy);

        $this->assertSame($antes, PurchaseSuggestion::count(), 'El recolector no puede dejar una purchase_suggestion en la base.');

        $this->assertTrue($h['aplica']);
        $this->assertSame($this->hoy->format('Y-m-d'), $h['fecha']);

        $this->assertCount(1, $h['por_proveedor']);

        $proveedor = $h['por_proveedor'][0];
        $this->assertSame($acme->id, $proveedor['provider_id']);
        $this->assertSame('Acme', $proveedor['nombre']);
        $this->assertEquals(1500.00, $proveedor['deuda_con_proveedor']);
        $this->assertSame(['fecha' => $this->hoy->copy()->subDays(20)->format('Y-m-d'), 'total' => 3000.0], $proveedor['ultima_compra']);
        // 8 lijas a $50 + 4 cintas a $20.
        $this->assertEquals(480.00, $proveedor['total_estimado']);

        // Sin cobertura (no hay ventas) el orden es por cantidad: lija 8, cinta 4.
        $this->assertSame([$lija->id, $cinta->id], array_column($proveedor['articulos'], 'article_id'));

        $this->assertSame([
            'article_id'         => $lija->id,
            'nombre'             => 'Lija',
            'stock'              => 2.0,
            'velocidad_diaria'   => 0.0,
            'cobertura_dias'     => null,
            'cantidad_sugerida'  => 8.0,
            'ultimo_costo'       => 50.0,
            'ultimo_costo_fecha' => $this->hoy->copy()->subDays(20)->format('Y-m-d'),
            'mejor_precio_otro_proveedor' => ['provider_id' => $beta->id, 'nombre' => 'Beta', 'costo' => 40.0],
        ], $proveedor['articulos'][0]);

        $this->assertSame($cinta->id, $proveedor['articulos'][1]['article_id']);
        $this->assertEquals(1.0, $proveedor['articulos'][1]['stock']);
        $this->assertEquals(4.0, $proveedor['articulos'][1]['cantidad_sugerida']);
        $this->assertEquals(20.0, $proveedor['articulos'][1]['ultimo_costo']);
        $this->assertNull($proveedor['articulos'][1]['ultimo_costo_fecha']);
        $this->assertNull($proveedor['articulos'][1]['mejor_precio_otro_proveedor']);

        $this->assertSame([
            ['article_id' => $rulo->id, 'nombre' => 'Rulo', 'stock' => 0.0, 'cobertura_dias' => null],
        ], $h['sin_proveedor']);

        $this->assertSame([
            'saldo_cajas'             => 1000.0,
            'deuda_total_proveedores' => 1500.0,
            'deuda_clientes'          => 200.0,
        ], $h['contexto_financiero']);

        $this->assertSame([
            ['article_id' => $rulo->id, 'nombre' => 'Rulo', 'busquedas_tienda_7d' => 2, 'consultas_whatsapp_7d' => null],
        ], $h['demanda_sin_stock']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function compras_de_un_comercio_sin_nada_que_reponer()
    {
        $this->articulo('Pinza', ['stock' => 100, 'stock_min' => 5]);

        $h = (new RecolectorCompras())->recolectar($this->comercio, $this->hoy);

        $this->assertTrue($h['aplica']);
        $this->assertSame([], $h['por_proveedor']);
        $this->assertSame([], $h['sin_proveedor']);
        $this->assertSame([], $h['demanda_sin_stock']);
        $this->assertEquals(0.0, $h['contexto_financiero']['saldo_cajas']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function stock_con_una_sola_sucursal_no_aplica()
    {
        $this->sucursal('Única');

        $h = (new RecolectorStock())->recolectar($this->comercio, $this->hoy);

        $this->assertFalse($h['aplica']);
        $this->assertNotEmpty($h['motivo']);
        $this->assertArrayNotHasKey('movimientos_sugeridos', $h);
    }

    /**
     * @group mostrador
     * @test
     */
    public function stock_sugiere_los_traslados_por_urgencia_y_lista_lo_que_no_rota()
    {
        $deposito = $this->sucursal('Depósito', true);
        $norte    = $this->sucursal('Norte');

        // Tornillo: 100 en el depósito, 2 en Norte con mínimo 10 → mover 8. En Norte se
        // vendieron 45 en los últimos 90 días: 0,5 por día, 4 días de cobertura.
        $tornillo = $this->articulo('Tornillo', ['stock' => 102, 'cost' => 3]);
        $tornillo->addresses()->attach($deposito->id, ['amount' => 100, 'stock_min' => 10, 'stock_max' => 20]);
        $tornillo->addresses()->attach($norte->id, ['amount' => 2, 'stock_min' => 10, 'stock_max' => 20]);

        $this->venta($this->hoy->copy()->subDays(30)->setTime(12, 0), [[$tornillo, 45, 10]], ['address_id' => $norte->id]);

        // Clavo: 50 en el depósito, 0 en Norte con mínimo 5 → mover 5. Sin ventas: cobertura infinita.
        $clavo = $this->articulo('Clavo', ['stock' => 50, 'cost' => 2]);
        $clavo->addresses()->attach($deposito->id, ['amount' => 50, 'stock_min' => 5]);
        $clavo->addresses()->attach($norte->id, ['amount' => 0, 'stock_min' => 5]);

        // Yunque: 3 en stock a $5.000, cargado hace 400 días y nunca vendido.
        $this->articulo('Yunque', ['stock' => 3, 'cost' => 5000, 'created_at' => $this->hoy->copy()->subDays(400)]);

        $antes = StockSuggestion::count();

        $h = (new RecolectorStock())->recolectar($this->comercio, $this->hoy);

        $this->assertSame($antes, StockSuggestion::count(), 'El recolector no puede dejar una stock_suggestion en la base.');

        $this->assertTrue($h['aplica']);
        $this->assertSame([
            ['address_id' => $deposito->id, 'nombre' => 'Depósito', 'es_deposito_origen' => true],
            ['address_id' => $norte->id, 'nombre' => 'Norte', 'es_deposito_origen' => false],
        ], $h['sucursales']);

        $this->assertCount(2, $h['movimientos_sugeridos']);

        $this->assertSame([
            'article_id' => $tornillo->id,
            'nombre'     => 'Tornillo',
            'desde'      => ['address_id' => $deposito->id, 'nombre' => 'Depósito', 'stock' => 100.0],
            'hacia'      => ['address_id' => $norte->id, 'nombre' => 'Norte', 'stock' => 2.0, 'velocidad_diaria' => 0.5, 'cobertura_dias' => 4.0],
            'cantidad'   => 8.0,
            'prioridad'  => 1,
        ], $h['movimientos_sugeridos'][0]);

        $this->assertSame([
            'article_id' => $clavo->id,
            'nombre'     => 'Clavo',
            'desde'      => ['address_id' => $deposito->id, 'nombre' => 'Depósito', 'stock' => 50.0],
            'hacia'      => ['address_id' => $norte->id, 'nombre' => 'Norte', 'stock' => 0.0, 'velocidad_diaria' => 0.0, 'cobertura_dias' => null],
            'cantidad'   => 5.0,
            'prioridad'  => 2,
        ], $h['movimientos_sugeridos'][1]);

        // Sin rotación: el yunque (400 días desde que se cargó) y el clavo (nunca vendido),
        // por valor a costo; el tornillo se vendió hace 30 días y no entra.
        $this->assertSame([$this->articulo_por_nombre('Yunque')->id, $clavo->id], array_column($h['sin_rotacion'], 'article_id'));
        $this->assertSame(400, $h['sin_rotacion'][0]['dias_sin_venta']);
        $this->assertEquals(3.0, $h['sin_rotacion'][0]['stock_total']);
        $this->assertEquals(15000.00, $h['sin_rotacion'][0]['valor_a_costo']);
        $this->assertEquals(100.00, $h['sin_rotacion'][1]['valor_a_costo']);

        // Solo el tornillo tiene cobertura por debajo del punto de pedido (15 días).
        $this->assertSame(['articulos_en_riesgo' => 1, 'valor_inmovilizado' => 15100.0], $h['resumen']);
    }

    /**
     * Un artículo del comercio del test por nombre.
     *
     * @param string $nombre
     * @return \App\Models\Article
     */
    protected function articulo_por_nombre($nombre)
    {
        return \App\Models\Article::where('user_id', $this->comercio->id)->where('name', $nombre)->firstOrFail();
    }
}
