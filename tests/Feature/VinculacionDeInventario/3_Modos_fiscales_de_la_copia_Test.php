<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\sale\CostoDeVentaHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CA6 del PLAN D2-M R3.1 — modos fiscales de la copia. Fuente 4828, margen 10 %: se crea la copia, se
 * relee, se recalcula, se vende una unidad y se mide. Los valores son la especificación (salen de la
 * cadena medida con `ca_modos.php`): si uno no da, se frena y se avisa, no se ajusta.
 *
 * @group vinculacion-iva-copia
 */
class Modos_fiscales_de_la_copia_Test extends VinculacionDeInventarioTestCase
{
    /**
     * @return array [nombre, flags de Galván, iva_id de la fuente, cost, costo_real, final, article_sale.cost, crédito]
     */
    public function modos()
    {
        $legacy = ['aplicar_iva_al_costo' => 1, 'usar_condicion_fiscal_en_costeo' => 0, 'condicion_iva_precios' => User::CONDICION_RRII];

        return [
            'legacy RI tilde 1, 21 %' => [$legacy, self::IVA_21, 3990.082645, 4828.0, 5310.80, 4828.00, 837.92],
            'legacy RI tilde 1, 10,5 %' => [$legacy, self::IVA_10_5, 4369.230769, 4828.0, 5310.80, 4828.00, 458.77],
            'legacy RI tilde 0, 21 %' => [array_merge($legacy, ['aplicar_iva_al_costo' => 0]), self::IVA_21, 3990.082645, 3990.082645, 5310.80, 3990.08, 0],
            'migrada RI, 21 %' => [['aplicar_iva_al_costo' => 0, 'usar_condicion_fiscal_en_costeo' => 1, 'condicion_iva_precios' => User::CONDICION_RRII], self::IVA_21, 3990.082645, 3990.082645, 5310.80, 3990.08, 0],
            'migrada MT, 21 %' => [['aplicar_iva_al_costo' => 0, 'usar_condicion_fiscal_en_costeo' => 1, 'condicion_iva_precios' => User::CONDICION_MT], self::IVA_21, 4828.0, 4828.0, 5310.80, 4828.00, 0],
            'legacy MT, 21 %' => [['aplicar_iva_al_costo' => 1, 'usar_condicion_fiscal_en_costeo' => 0, 'condicion_iva_precios' => User::CONDICION_MT], self::IVA_21, 3990.082645, 4828.0, 5310.80, 4828.00, 0],
            'legacy RI tilde 1, Exento' => [$legacy, 7, 4828.0, 4828.0, 5310.80, 4828.00, 0],
        ];
    }

    /**
     * @dataProvider modos
     * @test
     */
    public function ca6_cada_modo_fiscal_guarda_el_costo_y_mide_el_credito_que_corresponde($flags, $iva_id, $cost, $costo_real, $final, $costo_de_venta, $credito)
    {
        $this->escenario($flags);
        $fuente = $this->crear_fuente();
        DB::table('articles')->where('id', $fuente->id)->update(['iva_id' => $iva_id]);
        $fuente = Article::find($fuente->id);

        $copia = $this->crear_copia($fuente);

        $v = $this->valores($copia->id);
        $this->assertEquals(1, $v['marca'], 'marca 1');
        $this->assertEqualsWithDelta($cost, $v['cost'], 0.0000005, 'cost');
        $this->assertEqualsWithDelta($costo_real, $v['costo_real'], 0.0000005, 'costo_real');
        $this->assertEqualsWithDelta($final, $v['final'], 0.0001, 'final');

        // Releída y recalculada: no se mueve.
        $this->actingAs(User::find($this->galvan->id), 'web');
        ArticleHelper::setFinalPrice(Article::find($copia->id), $this->galvan->id);
        $this->actingAs(User::find($this->fenix->id), 'web');
        $this->assertEqualsWithDelta($final, $this->valores($copia->id)['final'], 0.0001, 'recalculada');
        $this->assertEqualsWithDelta($costo_real, $this->valores($copia->id)['costo_real'], 0.0000005, 'costo_real recalculado');

        $venta = $this->vender($copia, $final);

        $this->assertEqualsWithDelta($costo_de_venta, (float) DB::table('article_sale')->where('sale_id', $venta->id)->value('cost'), 0.005, 'article_sale.cost');

        $this->actingAs(User::find($this->galvan->id), 'web');
        $this->assertEqualsWithDelta($credito, CostoDeVentaHelper::medir_venta($venta), 0.005, 'crédito de medir_venta');
        $this->actingAs(User::find($this->fenix->id), 'web');
    }
}
