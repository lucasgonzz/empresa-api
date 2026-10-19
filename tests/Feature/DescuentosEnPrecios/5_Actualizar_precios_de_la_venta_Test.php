<?php

namespace Tests\Feature\DescuentosEnPrecios;

/**
 * Archivo 5 — el modal "Actualizar precios" (`PUT api/sale/update-prices/{id}`) escribe la base de
 * descuentos junto con el precio.
 *
 * Es un camino que cambia el `price` de un renglon YA GUARDADO sin pasar por VENDER. La regla:
 *
 *  - con la clave: se guarda la base que calculo la SPA (el precio original del precio mostrado);
 *  - SIN la clave (SPA vieja): NULL. No se deja la base vieja: describia al precio anterior, y un
 *    precio nuevo con esa base haria que VENDER, al reabrir, parta de un precio original que ya no
 *    es el de este renglon.
 *
 * Y las DOS bases se escriben a la vez, cada una por su clave: mandar solo la de recargos no
 * conserva la de descuentos (queda NULL), y viceversa.
 *
 * @group descuentos_en_precios
 */
class Actualizar_precios_de_la_venta_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Venta con la opcion prendida: articulo 90 (original 100) y servicio 45 (original 50).
     *
     * @return array [\App\Models\Sale, \App\Models\Article, \App\Models\Service]
     */
    protected function venta_prendida()
    {
        $articulo  = $this->articulo_centinela();
        $servicio  = $this->servicio(50);
        $descuento = $this->descuento();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 90, 100),
            $this->item_vender('service', $servicio->id, 45, 50),
        ], 135.00, 1, [$this->descuento_del_payload($descuento)]));

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

        /* El articulo sube a 120 de lista: con el 10 % de descuento adentro, 108. */
        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'                  => true,
                    'id'                          => $articulo->id,
                    'price_vender'                => 108,
                    'price_vender_sin_descuentos' => 120,
                ],
                [
                    'is_service'                  => true,
                    'id'                          => $servicio->id,
                    'price_vender'                => 54,
                    'price_vender_sin_descuentos' => 60,
                ],
            ],
        ])->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 108, 120, 'articulo actualizado con la clave');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 54, 60, 'servicio actualizado con la clave');
    }

    /**
     * Test 2 — sin la clave, sobre una venta prendida: se RECHAZA con 409 y no se toca nada, ni el
     * precio ni la base vieja. Antes de `requiere_spa_nueva_en_update_prices()` la API escribia el
     * precio nuevo y dejaba la base en NULL con el flag todavia en 1 (el descuento se perdia para
     * siempre). Todos los casos del candado estan en el archivo 16.
     *
     * @test
     */
    public function sin_la_clave_se_rechaza_y_no_se_toca_la_base_vieja()
    {
        list($sale, $articulo, $servicio) = $this->venta_prendida();

        /* Control: antes del PUT las dos filas tienen base. */
        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo antes');

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => 108,
                ],
                [
                    'is_service'   => true,
                    'id'           => $servicio->id,
                    'price_vender' => 54,
                ],
            ],
        ])->assertStatus(409);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'articulo: el rechazo no lo toca');
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 45, 50, 'servicio: el rechazo no lo toca');
    }

    /**
     * Test 3 bis — el camino POR VARIANTE de `SaleHelper::updateItemsPrices()` (mision
     * variantes-mismo-articulo-en-vender, 8/10/2026): con la clave `article_variant_id` en el item
     * (la SPA de variantes la manda SIEMPRE, con 0 para el renglon sin variante) escribe las filas
     * de esa variante con `DB::table('article_sale')->update()` y NO con `updateExistingPivot()`.
     * Las dos bases tienen que viajar igual: el `$cambios` de ese camino sale de las mismas dos
     * guardas de esquema.
     *
     * @test
     */
    public function el_camino_por_variante_tambien_escribe_las_dos_bases()
    {
        list($sale, $articulo, $servicio) = $this->venta_prendida();

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'                  => true,
                    'id'                          => $articulo->id,
                    'article_variant_id'          => 0,
                    'price_vender'                => 121,
                    'price_vender_sin_recargos'   => 110,
                    'price_vender_sin_descuentos' => 130,
                ],
                [
                    'is_service'                  => true,
                    'id'                          => $servicio->id,
                    'price_vender'                => 45,
                    'price_vender_sin_descuentos' => 50,
                ],
            ],
        ])->assertStatus(200);

        /* Valores DISTINTOS de los sembrados (90 / base 100): si el camino por variante dejara de escribir una base, quedaria la vieja y esto lo delata. */
        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 121, 130, 'articulo por variante con las dos capas', 110);
    }

    /**
     * Test 3 — la pila entera: el modal manda precio final, base de recargos Y base de descuentos
     * (descuento 10 % y recargo 10 % adentro: original 100, sin recargos 90, final 99), y las dos
     * columnas guardan SU valor, no el de la otra.
     *
     * @test
     */
    public function con_las_dos_claves_cada_columna_guarda_su_valor()
    {
        list($sale, $articulo, $servicio) = $this->venta_prendida();

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [
                [
                    'is_article'                  => true,
                    'id'                          => $articulo->id,
                    'price_vender'                => 99,
                    'price_vender_sin_recargos'   => 90,
                    'price_vender_sin_descuentos' => 100,
                ],
            ],
        ])->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 99, 100, 'articulo con descuento y recargo adentro', 90);
    }
}
