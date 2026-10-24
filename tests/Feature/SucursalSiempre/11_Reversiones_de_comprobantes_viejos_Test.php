<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Corrección del chequeo del motor (23/10/2026): la red de D3 NO se aplica a la reversión de un
 * comprobante cuyos movimientos originales no movieron stock (los que se perdían en silencio antes de
 * la misión: sin sucursal, sobre un artículo que ya repartía, `stock_anterior == stock_resultante`).
 * Si no, borrar la venta hacía aparecer stock de la nada y borrar la compra restaba unidades reales.
 * En un cliente MIGRADO, la venta vieja sí movió el global: su reversión va a la principal.
 *
 * Para armar "un comprobante viejo perdido" se crea el comprobante por el endpoint real y después se
 * deja su libro y sus filas como los dejaba develop (el movimiento sin sucursal y sin efecto).
 */
class Reversiones_de_comprobantes_viejos_Test extends SucursalSiempreTestCase
{
    /**
     * Deja los movimientos del comprobante como los dejaba develop: sin sucursal y sin mover nada, y
     * la fila y el global como estaban antes.
     *
     * @param  string  $clave       sale_id | provider_order_id
     * @param  int     $id
     * @param  \App\Models\Article  $articulo
     * @param  int     $address_id
     * @param  float   $fila
     * @return void
     */
    protected function como_si_se_hubiera_perdido($clave, $id, $articulo, $address_id, $fila)
    {
        DB::table('stock_movements')->where($clave, $id)->where('article_id', $articulo->id)->update([
            'from_address_id'  => null,
            'to_address_id'    => null,
            'stock_anterior'   => $fila,
            'stock_resultante' => $fila,
        ]);

        DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $address_id)->update(['amount' => $fila]);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => $fila]);
    }

    /**
     * @test
     */
    public function borrar_una_venta_vieja_perdida_no_hace_aparecer_stock()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Reversion venta perdida');
        $this->con_fila($articulo, $principal->id, 10);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], ['address_id' => null]);

        $this->como_si_se_hubiera_perdido('sale_id', $venta->id, $articulo, $principal->id, 10);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $principal->id), 'La venta nunca descontó: borrarla no puede sumar.');
        $this->assertEquals(10.0, $this->stock($articulo));
    }

    /**
     * @test
     */
    public function eliminar_una_compra_vieja_perdida_no_resta_unidades_reales()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Reversion compra perdida');
        $this->con_fila($articulo, $principal->id, 4);

        $proveedor = Provider::where('user_id', $this->usuario()->id)->where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();

        $this->postJson('api/provider-order', [
            'provider_id'                            => $proveedor->id,
            'address_id'                             => null,
            'modo_facturacion'                       => 'automatico',
            'update_prices'                          => 0,
            'update_stock'                           => 1,
            'total_with_iva'                         => 1,
            'precios_incluyen_iva'                   => 0,
            'moneda_id'                              => 2,
            'generate_current_acount'                => 0,
            'total_from_provider_order_afip_tickets' => 0,
            'provider_order_status_id'               => 2,
            'days_to_advise'                         => null,
            'numero_comprobante'                     => null,
            'articles'                               => [[
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => $articulo->provider_code,
                'pivot'         => [
                    'cost' => 100, 'amount' => 6, 'received' => 6, 'price' => null, 'discount' => null,
                    'iva_id' => $articulo->iva_id, 'cost_in_dollars' => 0, 'update_provider' => 0,
                ],
            ]],
        ])->assertStatus(201);

        $compra_id = (int) DB::table('stock_movements')->where('article_id', $articulo->id)->whereNotNull('provider_order_id')->value('provider_order_id');

        $this->assertGreaterThan(0, $compra_id);

        $this->como_si_se_hubiera_perdido('provider_order_id', $compra_id, $articulo, $principal->id, 4);

        $this->deleteJson('api/provider-order/'.$compra_id)->assertStatus(200);

        $this->assertEquals(4.0, $this->stock_en_deposito($articulo, $principal->id), 'La compra nunca sumó: eliminarla no puede restar.');
        $this->assertEquals(4.0, $this->stock($articulo));
    }

    /**
     * Cliente MIGRADO: la venta vieja descontó del global (sí movió stock); después la migración pasó el
     * global a la principal. Borrarla hoy devuelve a la principal.
     *
     * @test
     */
    public function en_un_cliente_migrado_la_venta_vieja_vuelve_a_la_principal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Reversion cliente migrado', ['stock' => 10]);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], ['address_id' => null]);

        $this->assertEquals(8.0, $this->stock($articulo), 'Sin filas, la venta descontó del global.');

        // La migración (sucursales:migrar-a-principal): el global entero a la principal.
        $this->con_fila($articulo, $principal->id, 8);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock($articulo));
    }

    /**
     * `ArticleHelper::resetStock()` (reversión de un renglón con `to = sale->address_id`, que en los
     * pedidos de Tienda Nube es 0) sigue el mismo criterio.
     *
     * @test
     */
    public function reset_stock_de_una_venta_vieja_perdida_tampoco_va_a_la_principal()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Reversion reset stock');
        $this->con_fila($articulo, $principal->id, 10);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 3, 'price' => 100]], ['address_id' => 0]);

        $this->como_si_se_hubiera_perdido('sale_id', $venta->id, $articulo, $principal->id, 10);

        $modelo = Article::find($articulo->id);
        $modelo->setRelation('pivot', (object) ['article_variant_id' => null]);

        ArticleHelper::resetStock($modelo, 3, Sale::find($venta->id));

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock($articulo));
    }

    /**
     * Artículo dado de alta antes del libro: su PRIMER movimiento es la venta S (−2) y no tiene
     * `stock_anterior` ni ningún movimiento anterior. Sin cómo saber el stock de antes, se toma como que
     * SÍ movió (tercera ronda del chequeo): el cliente se migra (principal 8) y al borrar S vuelve a 10.
     *
     * @test
     */
    public function sin_stock_anterior_ni_movimiento_previo_se_toma_como_que_movio()
    {
        $principal = $this->principal();

        $articulo = $this->crear_articulo('zz Reversion sin stock anterior', ['stock' => 10]);

        $venta = $this->crear_venta([['article' => $articulo, 'amount' => 2, 'price' => 100]], ['address_id' => null]);

        $this->assertSame(1, DB::table('stock_movements')->where('article_id', $articulo->id)->count(), 'La venta es el primer movimiento del artículo.');

        // Un movimiento de antes de que existiera stock_anterior (29/9/2026).
        DB::table('stock_movements')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->update(['stock_anterior' => null]);

        // La migración: el global (8) a la principal.
        $this->con_fila($articulo, $principal->id, 8);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        $this->assertEquals(10.0, $this->stock_en_deposito($articulo, $principal->id));
        $this->assertEquals(10.0, $this->stock($articulo));
    }
}
