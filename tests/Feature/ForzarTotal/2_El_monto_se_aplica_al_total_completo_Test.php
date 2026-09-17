<?php

namespace Tests\Feature\ForzarTotal;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Discount;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 2 — EL ARREGLO DE FONDO: el monto se aplica sobre el total COMPLETO y ULTIMO DE TODO.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTE ARCHIVO BLINDA (hallazgo 9 de `extensiones_hallazgos.md`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La extension `forzar_total` vieja calculaba un PORCENTAJE
 *  (`(total - final_price) / total * 100`), lo guardaba en `sales.descuento` y ese porcentaje se
 *  restaba SOLO A `total_articles`. Despues el total se rearmaba asi:
 *
 *      total = total_articles + total_services + total_combos + total_promocion_vinotecas
 *
 *  O sea que en una venta con un servicio o un combo adentro, el descuento caia sobre una parte
 *  del total y el numero que quedaba NO ERA el que el vendedor habia forzado. No fallaba con un
 *  error: daba otro numero, en silencio. Y el mostrador —que es donde se usa esto— es justamente
 *  donde se mezclan articulos con servicios.
 *
 *  Por eso los dos primeros tests usan una venta MEZCLADA. Un escenario de puros articulos daria
 *  verde con el codigo viejo tambien: no probaria nada.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 Y POR QUE HAY UN TEST CON UN DESCUENTO DE VENTA ENCIMA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Porque es lo unico que distingue "el monto se aplica al final" de "el monto se aplica en algun
 *  lugar del medio". Con un 15 % de descuento sobre un bruto de 4.012 y un forzado a 3.400:
 *
 *      aplicado al FINAL (correcto):  4.012 x 0,85 = 3.410,20 ; 3.410,20 - 10,20 = 3.400,00
 *      aplicado ANTES del descuento:  (4.012 - 10,20) x 0,85   =                   3.401,33
 *
 *  Son numeros distintos, asi que la asercion discrimina de verdad. Si el monto se aplicara antes,
 *  el total forzado dejaria de ser exacto y esa es toda la promesa de la funcionalidad.
 *
 * Los esperados estan CALCULADOS A MANO en cada asercion, nunca contra otro metodo del mismo
 * helper — que es justamente lo que esta bajo prueba.
 *
 * @group sales
 * @group forzar_total
 */
class El_monto_se_aplica_al_total_completo_Test extends ForzarTotalTestCase
{
    /**
     * Venta mezclada: articulos + servicio + combo, sumando exactamente el bruto de 4.012.
     *
     *     Martillo acero   1.000,00 x 2 = 2.000,00
     *     Servicio                        1.500,00
     *     Combo                             512,00
     *                                   ───────────
     *                                     4.012,00
     *
     * Los articulos son MENOS DE LA MITAD del total a proposito: si el monto se aplicara solo
     * sobre ellos —como hacia la extension vieja— el error saltaria a la vista.
     *
     * @param  array  $overrides  Campos de la venta a pisar.
     * @return \App\Models\Sale
     */
    protected function venta_mezclada($overrides = [])
    {
        $sale = $this->crear_venta_en_base($overrides);

        $this->enganchar_articulo($sale, TestingFerreteriaSeeder::ARTICULO_CENTINELA, 1000.00, 2);
        $this->enganchar_servicio($sale, 1500.00, 1);
        $this->enganchar_combo($sale, 512.00, 1);

        return $sale->fresh();
    }

    /**
     * El total que calcula el backend, con las relaciones recargadas.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    protected function total_del_backend($sale)
    {
        return (float) SaleHelper::getTotalSale($sale, true, true, false, true);
    }

    /**
     * Test 1 — LINEA DE BASE. Sin forzar, la venta mezclada da su bruto. Si este test se pone
     * rojo, los numeros de todos los demas dejan de significar algo.
     *
     * @group forzar_total
     * @test
     */
    public function sin_forzar_la_venta_mezclada_da_su_bruto()
    {
        $sale = $this->venta_mezclada([
            'total'              => self::BRUTO,
            'forzar_total_monto' => null,
        ]);

        // 2.000 + 1.500 + 512 = 4.012. Calculado a mano.
        $this->assertEqualsWithDelta(
            4012.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'sin forzado, el total tiene que ser la suma de articulos + servicios + combos'
        );
    }

    /**
     * Test 2 — EL CASO QUE ESTABA ROTO. Con servicios y combos en la venta, el total forzado es
     * exactamente el que escribio el vendedor.
     *
     * @group forzar_total
     * @test
     */
    public function con_servicios_y_combos_el_total_es_el_forzado()
    {
        $sale = $this->venta_mezclada();

        // 4.012 + (-12) = 4.000. El monto cae sobre el total ENTERO, no sobre los 2.000 de articulos.
        $this->assertEqualsWithDelta(
            4000.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'el total forzado tiene que ser 4.000 exacto, tambien con servicios y combos en la venta'
        );
    }

    /**
     * Test 3 — EL ORDEN. Con un descuento de venta del 15 % encima, el monto se aplica DESPUES.
     *
     *     4.012 x 0,85 = 3.410,20  ->  3.410,20 - 10,20 = 3.400,00   (correcto, al final)
     *     (4.012 - 10,20) x 0,85   =                      3.401,33   (incorrecto, en el medio)
     *
     * @group forzar_total
     * @test
     */
    public function el_monto_se_aplica_despues_de_los_descuentos_de_venta()
    {
        $sale = $this->venta_mezclada([
            'total'                 => 3400.00,
            'forzar_total_monto'    => -10.20,
            'discounts_in_services' => 1,
        ]);

        $discount = Discount::where('name', TestingFerreteriaSeeder::DESCUENTO_VENTA)->first();

        $this->assertNotNull($discount, 'Falta el descuento "'.TestingFerreteriaSeeder::DESCUENTO_VENTA.'" del fixture.');

        $sale->discounts()->attach($discount->id, ['percentage' => 15]);

        $sale = $sale->fresh();

        $this->assertEqualsWithDelta(
            3400.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'con un 15% de descuento encima, el total forzado tiene que dar 3.400 (si diera 3.401,33 el monto se estaria aplicando antes del descuento)'
        );
    }

    /**
     * Test 4 — un forzado HACIA ARRIBA sobre la misma venta mezclada.
     *
     * @group forzar_total
     * @test
     */
    public function el_forzado_hacia_arriba_tambien_cae_sobre_el_total_completo()
    {
        $sale = $this->venta_mezclada([
            'total'              => 4020.00,
            'forzar_total_monto' => 8.00,
        ]);

        // 4.012 + 8 = 4.020.
        $this->assertEqualsWithDelta(
            4020.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'un monto positivo es un recargo y tiene que subir el total completo'
        );
    }

    /**
     * Test 5 — NO REGRESION del helper: con la columna en null el metodo devuelve lo mismo que
     * devolvia antes de esta mision, sin sumar ni restar nada.
     *
     * Es el mismo escenario del test 1 pero desde el otro lado: alla se afirma cuanto da, aca que
     * el forzado no tocó el camino comun.
     *
     * @group forzar_total
     * @test
     */
    public function con_la_columna_en_null_el_helper_no_toca_el_total()
    {
        $sale = $this->crear_venta_en_base([
            'sub_total'          => 1000.00,
            'total'              => 1000.00,
            'forzar_total_monto' => null,
        ]);

        $this->enganchar_articulo($sale, TestingFerreteriaSeeder::ARTICULO_CENTINELA, 100.00, 10);

        $this->assertEqualsWithDelta(
            1000.00,
            $this->total_del_backend($sale->fresh()),
            self::DELTA,
            'sin forzado el total es la suma pelada de los renglones'
        );
    }
}
