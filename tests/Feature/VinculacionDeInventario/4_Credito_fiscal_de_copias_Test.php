<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\sale\CostoDeVentaHelper;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CA7 (sin doble neteo) y CA8 (crédito histórico intacto) del PLAN D2-M R3.1.
 *
 * @group vinculacion-iva-copia
 */
class Credito_fiscal_de_copias_Test extends VinculacionDeInventarioTestCase
{
    /**
     * CA7 — en la venta de la copia de Galván (legacy RI con la tilde) el costo neto que usa la
     * ganancia es 4828,00 − 837,92 = 3990,08 por unidad, una sola vez: `article_sale.cost` es 4828,00
     * (no 3990,08, que neteado de nuevo daría 3297,59) y `aplicar_iva` sigue en 1.
     *
     * @test
     */
    public function ca7_no_hay_doble_neteo_en_la_venta_de_la_copia()
    {
        $this->escenario();
        $copia = $this->crear_copia($this->crear_fuente());

        $venta = $this->vender($copia);

        $costo = (float) DB::table('article_sale')->where('sale_id', $venta->id)->value('cost');

        $this->actingAs(User::find($this->galvan->id), 'web');
        $credito = CostoDeVentaHelper::medir_venta($venta);
        $this->actingAs(User::find($this->fenix->id), 'web');

        $this->assertEqualsWithDelta(4828.00, $costo, 0.005, 'article_sale.cost es el bruto');
        $this->assertEqualsWithDelta(837.92, $credito, 0.005);
        $this->assertEqualsWithDelta(3990.08, $costo - $credito, 0.005, 'el costo neto se netea una sola vez');
        $this->assertNotEqualsWithDelta(3297.59, $costo - $credito, 0.01);
        $this->assertEquals(1, (int) Article::find($copia->id)->aplicar_iva, 'aplicar_iva sigue en 1');
    }

    /**
     * CA8 — una venta anterior de una copia con marca 0 da el mismo `medir_venta` y el mismo
     * `sales.ganancia` antes y después de que E1 convierta esa copia.
     *
     * @test
     */
    public function ca8_el_credito_historico_no_cambia_cuando_e1_convierte_la_copia()
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->copia_expuesta($fuente);

        $venta = $this->vender($copia);

        $this->actingAs(User::find($this->galvan->id), 'web');
        $credito_antes = CostoDeVentaHelper::medir_venta(Sale::find($venta->id));
        $this->actingAs(User::find($this->fenix->id), 'web');
        $ganancia_antes = DB::table('sales')->where('id', $venta->id)->value('ganancia');
        $costo_antes = DB::table('article_sale')->where('sale_id', $venta->id)->value('cost');

        list($codigo, $salida) = $this->convertir([$copia->id]);
        $this->assertEquals(0, $codigo, $salida);
        $this->assertEquals(1, $this->marca_de($copia->id), 'E1 convirtió la copia');

        $this->actingAs(User::find($this->galvan->id), 'web');
        $credito_despues = CostoDeVentaHelper::medir_venta(Sale::find($venta->id));
        $this->actingAs(User::find($this->fenix->id), 'web');

        $this->assertEqualsWithDelta($credito_antes, $credito_despues, 0.005, 'medir_venta');
        $this->assertEquals($ganancia_antes, DB::table('sales')->where('id', $venta->id)->value('ganancia'), 'sales.ganancia');
        $this->assertEquals($costo_antes, DB::table('article_sale')->where('sale_id', $venta->id)->value('cost'), 'article_sale.cost congelado');
        $this->assertEquals(1, (int) Article::find($copia->id)->aplicar_iva);
    }
}
