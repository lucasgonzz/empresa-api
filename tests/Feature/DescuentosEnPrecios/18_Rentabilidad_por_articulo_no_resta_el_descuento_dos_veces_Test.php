<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\CajaChartsHelper;
use App\Models\Sale;

/**
 * Archivo 18 — la rentabilidad por articulo del reporte (`CajaChartsHelper::get_rentabilidad_articulo()`)
 * no resta el descuento de la venta dos veces cuando el precio del renglon ya lo trae adentro.
 *
 * Con `aplicar_descuentos_directo_a_items` el `pivot->price` es el precio YA descontado (90 sobre un
 * original de 100), y el reporte le volvia a restar el 10 %: subestimaba la rentabilidad. Se mide, como
 * en el resto de la suite, contra el CONTROL con la opcion apagada (precio de lista 100 y el 10 % al
 * pie): el mismo articulo, vendido por el mismo importe, tiene que dar la misma rentabilidad.
 *
 * ⚠️ Solo los descuentos. Los recargos de esa misma cuenta siguen sin guarda (hallazgo 6 abierto del
 * 28/9/2026): no se prueba, porque esta mision no lo arregla.
 *
 * @group descuentos_en_precios
 */
class Rentabilidad_por_articulo_no_resta_el_descuento_dos_veces_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Una venta de mostrador armada directo en la base con el 10 % de descuento en su lista y UN
     * renglon del articulo centinela: 2 unidades al precio dado y con costo 40.
     *
     * @param  int    $flag
     * @param  float  $precio  Lo que se guarda en el renglon (90 prendida / 100 apagada).
     * @return \App\Models\Sale
     */
    protected function venta($flag, $precio)
    {
        $sale = Sale::create([
            'user_id'                            => $this->comercio()->id,
            'omitir_en_cuenta_corriente'         => 1,
            'save_current_acount'                => 0,
            'terminada'                          => 1,
            'moneda_id'                          => 1,
            'aplicar_descuentos_directo_a_items' => $flag,
        ]);

        $sale->discounts()->attach($this->descuento()->id, ['percentage' => self::PORCENTAJE_DESCUENTO]);

        $sale->articles()->attach($this->articulo_centinela()->id, ['amount' => 2, 'price' => $precio, 'cost' => 40]);

        $sale = Sale::find($sale->id);

        $sale->load('articles', 'discounts', 'surchages');

        return $sale;
    }

    /**
     * La rentabilidad del unico renglon de la venta.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    protected function rentabilidad($sale)
    {
        return (float) CajaChartsHelper::get_rentabilidad_articulo($sale->articles->first(), $sale);
    }

    /**
     * Prendida y apagada dan la MISMA rentabilidad: el articulo se vendio por el mismo importe. Y no
     * es la que saldria restando el descuento de nuevo.
     *
     * @test
     */
    public function la_rentabilidad_es_la_misma_con_la_opcion_prendida_y_apagada()
    {
        $prendida = $this->rentabilidad($this->venta(1, 90));
        $apagada  = $this->rentabilidad($this->venta(0, 100));

        $this->assertEqualsWithDelta($apagada, $prendida, 0.0001, 'Prendida: el precio 90 ya trae el descuento, el reporte no lo vuelve a restar.');

        /* El costo con el IVA del articulo (si lo tiene) sale de la misma cuenta; lo que no puede pasar es que la prendida baje por restar 90 × 10 %. */
        $restando_de_nuevo = $apagada - (90 * 0.10 * 2);

        $this->assertGreaterThan($restando_de_nuevo + 0.01, $prendida, 'Es lo que saldria con el descuento restado dos veces.');
    }

    /**
     * No-regresion: con la opcion apagada el reporte sigue restando el descuento de la lista al precio
     * de cada renglon (precio 100, 10 % de descuento, costo 40 mas su IVA).
     *
     * @test
     */
    public function con_la_opcion_apagada_sigue_restando_el_descuento()
    {
        $sale = $this->venta(0, 100);

        $articulo = $sale->articles->first();

        $costo = CajaChartsHelper::get_article_costo_realt($articulo);

        $this->assertEqualsWithDelta((90 - $costo) * 2, $this->rentabilidad($sale), 0.0001);
    }
}
