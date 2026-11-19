<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\RendimientoDeComprasIaHelper;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119, rama A, A1 — «se invirtieron $227 millones» en un mes de $34,7 M.
 *
 * 🔴 EL CASO REAL (Ferre Total, 18/11/2026, conv. 70, msgs 642-647): Pablo pidió el análisis de compras y
 * ventas del mes y consultar_rendimiento_de_compras devolvió `invertido_en_pesos` ≈ $227 M (Σ cantidad ×
 * `apo.cost` × (1 − descuento) por renglón). Las 48 compras del mes sumaban $34.668.592. El asistente le dijo
 * que estaba comprando muy por encima de lo que vendía y que la deuda con proveedores había crecido fuerte.
 *
 * Lo que se cuida acá:
 *  - el TOTAL DE COMPRAS del período sale de `provider_orders.total` (el que calcula la compra), con su
 *    cantidad de compras;
 *  - lo invertido por artículo es la parte del total de cada compra que le toca a su renglón: la suma da el
 *    total de las compras (con bulto/presentación, descuento de cabecera, costo extra, total por factura).
 *
 * El total de la compra se calcula con el código real de compras (NewProviderOrderHelper::set_totales()),
 * no a mano: si el día de mañana cambia esa cuenta, este test sigue comparando contra ella.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class T178_Total_de_compras_del_rendimiento_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var int */
    protected $proveedor_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio T178',
            'email'    => 't178-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->proveedor_id = DB::table('providers')->insertGetId(['name' => 'Bulonera T178', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * 🔴 Una compra por BULTO (presentación 100) con descuento de renglón, descuento de cabecera y un flete:
     * lo invertido es el total que calculó la compra, y el total de compras también.
     *
     * Remache 5x16: 3 cajas de 100 a $10 la unidad, 10 % de descuento → 3 × 10 × 100 × 0,9 = 2.700.
     * Tarugo N° 8: 50 unidades a $20 → 1.000.
     * Artículos = 3.000 + 1.000 = 4.000 brutos; menos 300 de descuento de renglón = 3.700; menos 10 % de
     * cabecera (370) = 3.330; más 500 de flete = 3.830.
     *
     * @group chat-ia
     * @test
     */
    public function lo_invertido_y_el_total_de_compras_coinciden_con_el_total_de_la_compra()
    {
        $remache = Article::create(['name' => 'Remache 5x16 T178', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 10, 'stock' => 300, 'presentacion' => 100]);
        $tarugo = Article::create(['name' => 'Tarugo N 8 T178', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 20, 'stock' => 50]);

        $id = $this->compra(now()->subDays(3), [
            [$remache, 3, null, 10, 10],
            [$tarugo, 50, null, 20, null],
        ]);

        DB::table('provider_order_discounts')->insert(['provider_order_id' => $id, 'percentage' => 10, 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('provider_order_extra_costs')->insert(['provider_order_id' => $id, 'value' => 500, 'description' => 'Flete T178', 'facturado' => 0, 'en_factura_compra' => 0, 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);

        $total = $this->calcular_total_con_el_codigo_de_compras($id);

        $this->assertEquals(3830.0, $total, 'Precondición: la cuenta de la compra (bulto, descuentos, flete).');

        $respuesta = $this->consultar();

        $this->assertArrayNotHasKey('error', $respuesta, json_encode($respuesta));
        $this->assertSame(1, $respuesta['compras_del_periodo']);
        $this->assertEquals(3830.0, $respuesta['total_compras_en_pesos']);
        $this->assertEquals(3830.0, $respuesta['invertido_en_pesos'], 'Lo invertido es el total de la compra, repartido.');

        $por_nombre = [];

        foreach ($respuesta['articulos'] as $articulo) {
            $por_nombre[$articulo['nombre']] = $articulo;
        }

        // El remache pesa 2.700 de 3.700 → 3.830 × 2.700 / 3.700 = 2.794,86; el tarugo, el resto.
        $this->assertEquals(2794.86, $por_nombre['Remache 5x16 T178']['invertido']);
        $this->assertEquals(1035.14, $por_nombre['Tarugo N 8 T178']['invertido']);
        $this->assertStringContainsString('total_compras_en_pesos', $respuesta['nota']);
    }

    /**
     * 🔴 La compra cuyo total sale de sus FACTURAS (total_from_provider_order_afip_tickets): los renglones
     * pueden decir cualquier cosa (en Ferre Total, costos que no eran los de la factura); manda el total de la
     * compra. Renglones: 1.000 × $500 = $500.000; factura: $80.000. Lo invertido no puede pasar de $80.000.
     *
     * @group chat-ia
     * @test
     */
    public function la_compra_con_total_por_factura_no_infla_lo_invertido()
    {
        $cable = Article::create(['name' => 'Cable T178', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 80, 'stock' => 1000]);

        $id = $this->compra(now()->subDays(2), [[$cable, 1000, null, 500, null]]);

        DB::table('provider_orders')->where('id', $id)->update(['total_from_provider_order_afip_tickets' => 1, 'total' => 80000]);

        $respuesta = $this->consultar();

        $this->assertEquals(80000.0, $respuesta['total_compras_en_pesos']);
        $this->assertEquals(80000.0, $respuesta['invertido_en_pesos'], 'Antes daba 500.000: la suma cruda del renglón.');
        $this->assertEquals(80000.0, $respuesta['articulos'][0]['invertido']);
    }

    /**
     * El total de compras cuenta TODAS las compras del período (también la que no movió stock y la que no
     * tiene renglones con costo), separa las de otra moneda y deja afuera las de otro período y otro negocio.
     *
     * @group chat-ia
     * @test
     */
    public function el_total_de_compras_cuenta_todas_las_compras_del_periodo()
    {
        $pala = Article::create(['name' => 'Pala T178', 'user_id' => $this->comercio->id, 'status' => 'active', 'cost' => 1000, 'stock' => 5]);

        $con_stock = $this->compra(now()->subDays(4), [[$pala, 5, null, 1000, null]]);
        DB::table('provider_orders')->where('id', $con_stock)->update(['total' => 5000]);

        $sin_stock = $this->compra(now()->subDays(4), [[$pala, 2, null, 1000, null]]);
        DB::table('provider_orders')->where('id', $sin_stock)->update(['total' => 2000, 'update_stock' => 0]);

        // Sin renglones con costo (solo el total cargado de la factura).
        $sin_costos = $this->compra(now()->subDays(4), [[$pala, 1, null, null, null]]);
        DB::table('provider_orders')->where('id', $sin_costos)->update(['total' => 700]);

        $en_dolares = $this->compra(now()->subDays(4), [[$pala, 1, null, 10, null]]);
        DB::table('provider_orders')->where('id', $en_dolares)->update(['total' => 10, 'moneda_id' => 2]);

        $vieja = $this->compra(now()->subDays(60), [[$pala, 1, null, 1000, null]]);
        DB::table('provider_orders')->where('id', $vieja)->update(['total' => 99999]);

        $respuesta = $this->consultar();

        $this->assertSame(4, $respuesta['compras_del_periodo'], 'Las cuatro del período, sin la vieja.');
        $this->assertEquals(7700.0, $respuesta['total_compras_en_pesos'], '5.000 + 2.000 + 700; la de dólares aparte.');
        $this->assertSame(1, $respuesta['compras_en_otra_moneda']);
        $this->assertSame(1, $respuesta['compras_que_no_movieron_stock']);
        $this->assertEquals(5000.0, $respuesta['invertido_en_pesos'], 'Lo invertido por artículo: solo la compra que movió stock y tiene costos.');
        $this->assertLessThanOrEqual($respuesta['total_compras_en_pesos'], $respuesta['invertido_en_pesos']);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array  $extra
     * @return array
     */
    protected function consultar(array $extra = [])
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        return RendimientoDeComprasIaHelper::consultar($this->comercio->id, $conversation, array_merge([
            'desde' => now()->subDays(10)->format('Y-m-d'),
            'hasta' => now()->format('Y-m-d'),
        ], $extra));
    }

    /**
     * Corre la cuenta real de la compra (la misma de procesar_pedido()) y devuelve el total guardado.
     *
     * @param  int  $id
     * @return float
     */
    protected function calcular_total_con_el_codigo_de_compras($id)
    {
        $helper = new NewProviderOrderHelper(ProviderOrder::find($id), []);
        $helper->set_totales();

        return (float) DB::table('provider_orders')->where('id', $id)->value('total');
    }

    /**
     * Una compra con sus renglones: [artículo, amount, received, costo, descuento %].
     *
     * @param  Carbon  $momento
     * @param  array  $renglones
     * @return int
     */
    protected function compra(Carbon $momento, array $renglones)
    {
        $id = DB::table('provider_orders')->insertGetId([
            'provider_id' => $this->proveedor_id,
            'user_id'     => $this->comercio->id,
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
}
