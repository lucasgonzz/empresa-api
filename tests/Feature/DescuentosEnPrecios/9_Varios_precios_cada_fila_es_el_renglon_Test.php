<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;

/**
 * Archivo 9 — `varios_precios`: cada fila ES el renglon, con otro precio y otra cantidad, tambien
 * con el descuento de venta adentro (mision descuentos-aplicar-al-precio-del-articulo, 8/10/2026).
 * Hermano del archivo 9 de `RecargosEnPrecios`.
 *
 * `SaleHelper::fila_de_varios_precios()` parte del renglon entero y le saca SOLO lo que es de la
 * cantidad o del precio del padre. Entre lo que se saca esta `price_vender_sin_descuentos`: la
 * fila trae SU base o NULL, jamas la del padre (que `DescuentosEnPreciosEsquemaHelper::base_del_item()`
 * leeria con `array_key_exists` y guardaria como si fuera el precio original de la fila).
 *
 * Los numeros estan escritos A MANO: el total es lo que calcularia VENDER (`getTotalItem()` aplica
 * `item.discount` —la bonificacion del renglon, que no tiene nada que ver con el descuento de
 * venta— sobre el precio de cada fila), no un helper del sistema.
 *
 * @group descuentos_en_precios
 */
class Varios_precios_cada_fila_es_el_renglon_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Afirma el total guardado y el que recalcula la API sumando las filas.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float  $total_de_vender
     * @return void
     */
    protected function assert_total($sale, $total_de_vender)
    {
        $sale = Sale::find($sale->id);

        $this->assertEqualsWithDelta($total_de_vender, (float) $sale->total, self::DELTA, 'sales.total no es el que mando VENDER.');

        $this->assertEqualsWithDelta(
            $total_de_vender,
            SaleHelper::getTotalSale($sale, true, true, false, true),
            self::DELTA,
            'La suma de las filas (getTotalSale) no da el total de VENDER.'
        );
    }

    /**
     * Test 1 — opcion prendida (descuento de 10 % adentro): filas tipeadas 100 × 2 y 50 × 1, que la
     * SPA manda con el descuento (90 y 45) y su precio original. Cada fila guarda su precio, SU base
     * y la bonificacion del renglon (la de renglon, 10 %, es otra cosa que el descuento de venta):
     * (90 × 2 + 45) × 0,9 = 202,50. El padre trae su propio precio de lista y su propia base
     * (120 / 132), que no son los de ninguna fila.
     *
     * @test
     */
    public function opcion_prendida_cada_fila_guarda_el_precio_descontado_su_base_y_la_bonificacion()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 90, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 100],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 45, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 50],
        ], [
            'discount'                    => 10,
            'price_vender'                => 108,
            'price_vender_sin_descuentos' => 120,
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 202.50, 1, [$this->descuento_del_payload($descuento)]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 45, 50, 'fila de 50 descontada', null);
        $this->assert_precio_y_base($filas[1], 90, 100, 'fila de 100 descontada', null);

        foreach ($filas as $fila) {
            $this->assertEqualsWithDelta(10, (float) $fila->discount, self::DELTA, 'La fila perdio la bonificacion del renglon.');
        }

        /* Con la opcion prendida getTotalSale() no resta el descuento al pie: ya esta en los precios. */
        $this->assert_total($sale, 202.50);
    }

    /**
     * Test 2 — descuento Y recargo adentro a la vez (10 % y 10 %): cada fila guarda sus DOS bases,
     * distintas entre si. Fila de 100: original 100, sin recargos 90, final 99.
     * Fila de 50: original 50, sin recargos 45, final 49,50. (99 × 2 + 49,50) = 247,50.
     *
     * @test
     */
    public function descuento_y_recargo_adentro_cada_fila_guarda_sus_dos_bases()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();
        $recargo   = $this->recargo();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 99, 'price_vender_sin_recargos' => 90, 'price_vender_sin_descuentos' => 100],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 49.5, 'price_vender_sin_recargos' => 45, 'price_vender_sin_descuentos' => 50],
        ]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 247.50, 1, [$this->descuento_del_payload($descuento)], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($recargo)],
        ]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 49.5, 50, 'fila de 50 con las dos capas', 45);
        $this->assert_precio_y_base($filas[1], 99, 100, 'fila de 100 con las dos capas', 90);

        /* Las dos opciones prendidas: getTotalSale() ni resta el descuento ni suma el recargo. */
        $this->assert_total($sale, 247.50);
    }

    /**
     * Test 3 — como interactua el descuento adentro con el descuento GENERAL de la venta
     * (`sales.descuento`, el "descuento %" que cae solo sobre los articulos) y con el recargo al
     * pie. La opcion es solo de los descuentos de la lista de Descuentos de la venta: el descuento
     * general NO se toca y se sigue aplicando al pie.
     *
     *   varios precios, ya con el 10 % adentro:  90 × 2 + 45 × 1 = 225
     *   renglon comun de otro articulo: con la opcion prendida TODOS los articulos llevan el
     *   descuento adentro, asi que va a 72 (80 con el 10 %)
     *   articulos                                  = 225 + 72 = 297
     *   descuento general 20 %:             297 × 0,8 = 237,60
     *   recargo al pie 10 %:             237,60 × 1,1 = 261,36
     *
     * @test
     */
    public function el_descuento_general_y_el_recargo_al_pie_siguen_aplicandose_con_la_opcion_prendida()
    {
        $articulo  = $this->articulo_centinela();
        $otro      = $this->articulo_propio('Otro articulo de la venta');
        $descuento = $this->descuento();
        $recargo   = $this->recargo();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 90, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 100],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1, 'price_vender_con_recargos' => 45, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 50],
        ]);

        $renglon_comun = $this->item_vender('article', $otro->id, 72, 80, 1);

        $sale = $this->crear_venta($this->payload_venta(
            [$renglon, $renglon_comun],
            261.36,
            1,
            [$this->descuento_del_payload($descuento)],
            [
                'sub_total' => 297,
                'descuento' => 20,
                'surchages' => [$this->recargo_del_payload($recargo)],
            ]
        ));

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $otro->id), 72, 80, 'renglon comun', null);

        $this->assert_total($sale, 261.36);
    }

    /**
     * Test 4 — la SPA VIEJA (anterior a esta mision) manda las filas sin precio final ni bases:
     * se guarda el tipeado y todo en NULL, y las filas igual heredan la bonificacion del renglon.
     * (100 × 2 + 50 × 1) × 0,85 = 212,50.
     *
     * @test
     */
    public function spa_vieja_sin_precio_final_ni_bases_las_filas_igual_guardan_la_bonificacion()
    {
        $articulo = $this->articulo_centinela();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1],
        ], ['discount' => 15]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 212.50, 0, []));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 50, null, 'fila de 50 de la SPA vieja', null);
        $this->assert_precio_y_base($filas[1], 100, null, 'fila de 100 de la SPA vieja', null);

        $this->assert_total($sale, 212.50);
    }

    /**
     * Test 5 — el PRECIO y la BASE del padre no bajan a las filas. El padre trae su
     * `price_vender_con_recargos` (999), su base de recargos (120) y su base de descuentos (130). La
     * fila que trae su precio final (90) y NO trae base de descuentos queda con la columna en NULL
     * —nunca la del padre—, y la fila sin precio final guarda el tipeado (50), nunca el 999.
     *
     * @test
     */
    public function el_precio_y_las_bases_del_padre_no_bajan_a_las_filas()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 90],
            ['id' => 0, 'price_vender' => 50, 'amount' => 1],
        ], [
            'price_vender'                => 132,
            'price_vender_con_recargos'   => 999,
            'price_vender_sin_recargos'   => 120,
            'price_vender_sin_descuentos' => 130,
            'calculated_price_vender'     => 270,
            'price_vender_personalizado'  => 77,
        ]);

        /* 90 × 2 + 50 × 1 = 230. */
        $sale = $this->crear_venta($this->payload_venta([$renglon], 230.00, 1, [$this->descuento_del_payload($descuento)]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 50, null, 'fila sin precio final', null);
        $this->assert_precio_y_base($filas[1], 90, null, 'fila con precio final y sin base de descuentos', null);
    }
}
