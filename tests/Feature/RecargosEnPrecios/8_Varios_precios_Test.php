<?php

namespace Tests\Feature\RecargosEnPrecios;

use Illuminate\Support\Facades\DB;

/**
 * Archivo 8 — `varios_precios`: cada renglon de precio se guarda con el precio RECARGADO que manda
 * la SPA (`price_vender_con_recargos`) y su base, no con lo que tipeo el vendedor.
 *
 * En `varios_precios`, `price_vender` es el input del vendedor y la SPA no lo pisa (si lo pisara,
 * prender y apagar la opcion le iria cambiando el numero que escribio). Por eso el precio final
 * viaja aparte. Sin `price_vender_con_recargos` —SPA vieja u opcion apagada— se guarda
 * `price_vender` como siempre y la base va en NULL.
 *
 * Hasta esta mision, con la opcion prendida, `varios_precios` directamente no cobraba el recargo
 * (decision 2 de Lucas): el renglon se guardaba con el precio tipeado y el pie tampoco lo sumaba.
 *
 * @group recargos_en_precios
 */
class Varios_precios_Test extends RecargosEnPreciosTestCase
{
    /**
     * Las filas de `article_sale` del articulo, ordenadas por precio.
     *
     * @param  int  $sale_id
     * @param  int  $article_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_del_articulo($sale_id, $article_id)
    {
        return DB::table('article_sale')
                    ->where('sale_id', $sale_id)
                    ->where('article_id', $article_id)
                    ->orderBy('price')
                    ->get();
    }

    /**
     * Test 1 — con `price_vender_con_recargos`: se guarda ESE precio y la base.
     *
     * @test
     */
    public function con_el_precio_recargado_guarda_ese_precio_y_la_base()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

        /* 99 × 2 + 110 × 1 = 308. */
        $item = [
            'is_article'     => true,
            'id'             => $articulo->id,
            'name'           => $articulo->name,
            'price_vender'   => 100,
            'amount'         => 3,
            'varios_precios' => [
                [
                    'price_vender'              => 90,
                    'amount'                    => 2,
                    'price_vender_con_recargos' => 99,
                    'price_vender_sin_recargos' => 90,
                ],
                [
                    'price_vender'              => 100,
                    'amount'                    => 1,
                    'price_vender_con_recargos' => 110,
                    'price_vender_sin_recargos' => 100,
                ],
            ],
        ];

        $sale = $this->crear_venta($this->payload_venta([$item], 308.00, 1, [$this->recargo_del_payload($recargo)]));

        $filas = $this->filas_del_articulo($sale->id, $articulo->id);

        $this->assertCount(2, $filas, 'Cada precio de varios_precios es su propio renglon.');

        $this->assert_precio_y_base($filas[0], 99, 90, 'primer precio de varios_precios');
        $this->assert_precio_y_base($filas[1], 110, 100, 'segundo precio de varios_precios');

        $this->assertEqualsWithDelta(2, (float) $filas[0]->amount, self::DELTA, 'La cantidad de cada precio no cambia.');
    }

    /**
     * Test 2 — sin `price_vender_con_recargos` se guarda `price_vender` como siempre, y la base en
     * NULL AUNQUE venga `price_vender_sin_recargos`: el precio guardado es el tipeado, que no tiene
     * el recargo adentro, y la invariante de la columna manda sobre el payload.
     *
     * @test
     */
    public function sin_el_precio_recargado_guarda_el_tipeado_y_la_base_en_null()
    {
        $articulo = $this->articulo_centinela();
        $recargo  = $this->recargo();

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
                    'price_vender'              => 100,
                    'amount'                    => 1,
                    'price_vender_sin_recargos' => 100,
                ],
            ],
        ];

        /* Opcion apagada: 190 de renglones mas el 10 % al pie = 209. */
        $sale = $this->crear_venta($this->payload_venta([$item], 209.00, 0, [$this->recargo_del_payload($recargo)], ['sub_total' => 190]));

        $filas = $this->filas_del_articulo($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 90, null, 'precio tipeado sin clave');
        $this->assert_precio_y_base($filas[1], 100, null, 'precio tipeado con base pero sin precio recargado');
    }
}
