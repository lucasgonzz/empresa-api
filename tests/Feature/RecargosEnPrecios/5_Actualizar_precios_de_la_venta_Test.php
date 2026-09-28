<?php

namespace Tests\Feature\RecargosEnPrecios;

/**
 * Archivo 5 — el modal "Actualizar precios" (`PUT api/sale/update-prices/{id}`) escribe la base
 * junto con el precio.
 *
 * Es un camino que cambia el `price` de un renglon YA GUARDADO sin pasar por VENDER. La regla:
 *
 *  - con la clave: se guarda la base que calculo la SPA (precio mostrado / factor de los recargos
 *    guardados de la venta);
 *  - SIN la clave (SPA vieja): NULL. No se deja la base vieja: describia al precio anterior, y un
 *    precio nuevo con esa base haria que VENDER, al reabrir, recalcule desde un numero que ya no
 *    existe — o sea, que le cambie el precio que el vendedor acaba de poner.
 *
 * @group recargos_en_precios
 */
class Actualizar_precios_de_la_venta_Test extends RecargosEnPreciosTestCase
{
    /**
     * Venta con la opcion prendida: articulo 110 (base 100) y servicio 55 (base 50).
     *
     * @return array [\App\Models\Sale, \App\Models\Article, \App\Models\Service]
     */
    protected function venta_prendida()
    {
        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $recargo  = $this->recargo();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 110, 100),
            $this->item_vender('service', $servicio->id, 55, 50),
        ], 165.00, 1, [$this->recargo_del_payload($recargo)]));

        return [$sale, $articulo, $servicio];
    }

    /**
     * Test 1 — con la clave, el precio nuevo y su base se guardan juntos.
     *
     * @test
     */
    public function con_la_clave_guarda_el_precio_nuevo_y_su_base()
    {
        list($sale, $articulo, $servicio) = $this->venta_prendida();

        /* El articulo sube a 120 de lista: con el 10 % adentro, 132. */
        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'                => true,
                    'id'                        => $articulo->id,
                    'price_vender'              => 132,
                    'price_vender_sin_recargos' => 120,
                ],
                [
                    'is_service'                => true,
                    'id'                        => $servicio->id,
                    'price_vender'              => 66,
                    'price_vender_sin_recargos' => 60,
                ],
            ],
        ])->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 132, 120, 'articulo actualizado con la clave');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 66, 60, 'servicio actualizado con la clave');
    }

    /**
     * Test 2 — sin la clave, la base queda en NULL: nunca la base del precio anterior.
     *
     * @test
     */
    public function sin_la_clave_la_base_queda_en_null_y_no_la_vieja()
    {
        list($sale, $articulo, $servicio) = $this->venta_prendida();

        /* Control: antes del PUT las dos filas tienen base. */
        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, 100, 'articulo antes');

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => 132,
                ],
                [
                    'is_service'   => true,
                    'id'           => $servicio->id,
                    'price_vender' => 66,
                ],
            ],
        ])->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 132, null, 'articulo actualizado por la SPA vieja');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 66, null, 'servicio actualizado por la SPA vieja');
    }
}
