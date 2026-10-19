<?php

namespace Tests\Feature\DescuentosEnPrecios;

use Illuminate\Support\Facades\DB;

/**
 * Archivo 8 — `varios_precios`: cada renglon de precio se guarda con el precio FINAL que manda la
 * SPA (`price_vender_con_recargos`, que desde la mision de descuentos es el precio con el descuento
 * y/o el recargo adentro; el nombre queda por compatibilidad) y SU precio original
 * (`price_vender_sin_descuentos`), no con lo que tipeo el vendedor.
 *
 * En `varios_precios`, `price_vender` es el input del vendedor y la SPA no lo pisa (si lo pisara,
 * prender y apagar la opcion le iria cambiando el numero que escribio). Por eso el precio final
 * viaja aparte. Sin `price_vender_con_recargos` —SPA vieja u opcion apagada— se guarda
 * `price_vender` como siempre y las DOS bases van en NULL.
 *
 * @group descuentos_en_precios
 */
class Varios_precios_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Test 1 — con `price_vender_con_recargos` (el precio final): se guarda ESE precio y la base de
     * descuentos de la fila.
     *
     * @test
     */
    public function con_el_precio_final_guarda_ese_precio_y_la_base_de_descuentos()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        /* 81 × 2 + 90 × 1 = 252. */
        $item = [
            'is_article'     => true,
            'id'             => $articulo->id,
            'name'           => $articulo->name,
            'price_vender'   => 100,
            'amount'         => 3,
            'varios_precios' => [
                [
                    'price_vender'                => 90,
                    'amount'                      => 2,
                    'price_vender_con_recargos'   => 81,
                    'price_vender_sin_recargos'   => null,
                    'price_vender_sin_descuentos' => 90,
                ],
                [
                    'price_vender'                => 100,
                    'amount'                      => 1,
                    'price_vender_con_recargos'   => 90,
                    'price_vender_sin_recargos'   => null,
                    'price_vender_sin_descuentos' => 100,
                ],
            ],
        ];

        $sale = $this->crear_venta($this->payload_venta([$item], 252.00, 1, [$this->descuento_del_payload($descuento)]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas, 'Cada precio de varios_precios es su propio renglon.');

        $this->assert_precio_y_base($filas[0], 81, 90, 'primer precio de varios_precios', null);
        $this->assert_precio_y_base($filas[1], 90, 100, 'segundo precio de varios_precios', null);

        $this->assertEqualsWithDelta(2, (float) $filas[0]->amount, self::DELTA, 'La cantidad de cada precio no cambia.');
    }

    /**
     * Test 2 — sin `price_vender_con_recargos` se guarda `price_vender` como siempre, y las bases
     * en NULL AUNQUE VENGAN: el precio guardado es el tipeado, que no tiene ni el descuento ni el
     * recargo adentro, y la invariante de las columnas manda sobre el payload.
     *
     * @test
     */
    public function sin_el_precio_final_guarda_el_tipeado_y_las_bases_en_null()
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $item = [
            'is_article'     => true,
            'id'             => $articulo->id,
            'name'           => $articulo->name,
            'price_vender'   => 100,
            'amount'         => 2,
            'varios_precios' => [
                [
                    'price_vender' => 90,
                    'amount'       => 1,
                ],
                [
                    'price_vender'                => 100,
                    'amount'                      => 1,
                    'price_vender_sin_recargos'   => 100,
                    'price_vender_sin_descuentos' => 100,
                ],
            ],
        ];

        /* Opcion apagada: 190 de renglones menos el 10 % al pie = 171. */
        $sale = $this->crear_venta($this->payload_venta([$item], 171.00, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 190]));

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 90, null, 'precio tipeado sin clave', null);
        $this->assert_precio_y_base($filas[1], 100, null, 'precio tipeado con bases pero sin precio final', null);
    }

    /**
     * Test 3 — una fila SIN la clave de descuentos (una SPA de la mision de recargos) guarda NULL en
     * esa columna: nunca la base de la fila de al lado ni la del padre.
     *
     * @test
     */
    public function una_fila_sin_la_clave_de_descuentos_la_deja_en_null()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        $item = [
            'is_article'                  => true,
            'id'                          => $articulo->id,
            'name'                        => $articulo->name,
            'price_vender'                => 100,
            'amount'                      => 2,
            'price_vender_sin_descuentos' => 77,
            'varios_precios'              => [
                [
                    'price_vender'              => 90,
                    'amount'                    => 1,
                    'price_vender_con_recargos' => 99,
                    'price_vender_sin_recargos' => 90,
                ],
                [
                    'price_vender'                => 100,
                    'amount'                      => 1,
                    'price_vender_con_recargos'   => 110,
                    'price_vender_sin_recargos'   => 100,
                    'price_vender_sin_descuentos' => 100,
                ],
            ],
        ];

        $sale = $this->crear_venta($this->payload_venta([$item], 209.00, 0, [], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => [$this->recargo_del_payload($recargo)],
        ]));

        $filas = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $articulo->id)->orderBy('price')->get();

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 99, null, 'fila sin la clave de descuentos', 90);
        $this->assert_precio_y_base($filas[1], 110, 100, 'fila con la clave de descuentos', 100);
    }
}
