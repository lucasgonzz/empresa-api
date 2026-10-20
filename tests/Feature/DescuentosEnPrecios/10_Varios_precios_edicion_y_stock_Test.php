<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 10 — `varios_precios` con el descuento adentro: editar la venta y el stock. Hermano del
 * archivo 10 de `RecargosEnPrecios` (que cubre el stock en detalle: variantes, cantidades en cero,
 * movimientos); aca se mide lo que agrega esta mision, que es que la base de descuentos de cada
 * fila sobreviva a la edicion segun quien la reabre, sin mover el stock.
 *
 * Las dos SPA conviven (la PWA va 1-2 releases atras):
 *
 *  - la NUEVA reabre la venta como UN renglon con varios precios, una fila por fila guardada, y
 *    manda en cada fila `price_vender_con_recargos` y las bases;
 *  - la de antes reabre UN RENGLON POR FILA, sin saber nada de las bases ni del flag: sobre una
 *    venta con los descuentos adentro la API la RECHAZA con 409 (ver el archivo 15), porque su
 *    `total` vendria con el descuento restado dos veces.
 *
 * Todo con un articulo PROPIO con stock 20 y `discount_stock` = 1.
 *
 * @group descuentos_en_precios
 */
class Varios_precios_edicion_y_stock_Test extends DescuentosEnPreciosTestCase
{
    /** Stock con el que arranca el articulo de cada test. */
    const STOCK_INICIAL = 20;

    /**
     * Stock del articulo leido de la base, nunca del modelo en memoria.
     *
     * @param  \App\Models\Article  $articulo
     * @return float
     */
    protected function stock($articulo)
    {
        return (float) DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * Crea la venta del alta: 90 × 2 + 45 × 3 = 315 (5 unidades), con el 10 % de descuento de venta
     * ADENTRO de los precios (originales 100 y 50) y la opcion prendida. Afirma que descontó las 5.
     *
     * @param  \App\Models\Article   $articulo
     * @param  \App\Models\Discount  $descuento
     * @return \App\Models\Sale
     */
    protected function crear_venta_de_cinco($articulo, $descuento)
    {
        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 90, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 100],
            ['id' => 0, 'price_vender' => 50, 'amount' => 3, 'price_vender_con_recargos' => 45, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => 50],
        ], ['cost' => (float) $articulo->cost]);

        $sale = $this->crear_venta($this->payload_venta([$renglon], 315.00, 1, [$this->descuento_del_payload($descuento)], ['discount_stock' => 1]));

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'El alta tenia que descontar 2 + 3.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assert_precio_y_base($filas[0], 45, 50, 'fila de 45 del alta', null);
        $this->assert_precio_y_base($filas[1], 90, 100, 'fila de 90 del alta', null);

        return $sale;
    }

    /**
     * PUT de la venta por el endpoint real, descontando stock.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array             $items
     * @param  float             $total
     * @param  int               $flag
     * @param  array             $descuentos
     * @return void
     */
    protected function actualizar($sale, $items, $total, $flag, $descuentos)
    {
        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, $items, $total, $flag, $descuentos, [
            'discount_stock' => 1,
        ]))->assertStatus(200);
    }

    /**
     * El renglon con el que la SPA NUEVA reabre la venta: el primer renglon del grupo, con su pivot,
     * y una fila de varios precios por fila guardada, cada una con su precio final y las dos bases
     * que leyo del pivot.
     *
     * @param  \App\Models\Sale     $sale
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function renglon_de_la_spa_nueva($sale, $articulo)
    {
        $filas_guardadas = DB::table('article_sale')
                                ->where('sale_id', $sale->id)
                                ->where('article_id', $articulo->id)
                                ->orderBy('id')
                                ->get();

        $primera = $filas_guardadas->first();

        $varios_precios = [];

        foreach ($filas_guardadas as $indice => $fila) {
            $varios_precios[] = [
                'id'                          => $indice,
                'price_vender'                => (float) $fila->price_sin_descuentos_de_venta,
                'amount'                      => (float) $fila->amount,
                'desde_comprobante_guardado'  => true,
                'price_vender_con_recargos'   => (float) $fila->price,
                'price_vender_sin_recargos'   => null,
                'price_vender_sin_descuentos' => (float) $fila->price_sin_descuentos_de_venta,
            ];
        }

        return $this->renglon_con_varios_precios($articulo, $varios_precios, [
            'pivot'                       => (array) $primera,
            'cost'                        => (float) $primera->cost,
            'price_vender'                => (float) $primera->price,
            'article_variant_id'          => (int) $primera->article_variant_id,
            'discount'                    => '',
            'checked_amount'              => '',
            'returned_amount'             => '',
            'delivered_amount'            => '',
            'price_type_personalizado_id' => 0,
        ]);
    }

    /**
     * Los renglones con los que una SPA ANTERIOR reabre la venta: uno por fila del pivot, sin
     * ninguna de las claves nuevas.
     *
     * @param  \App\Models\Sale     $sale
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function renglones_de_la_spa_vieja($sale, $articulo)
    {
        $renglones = [];

        foreach ($this->filas_de_la_venta($sale->id, $articulo->id) as $fila) {

            $renglones[] = [
                'is_article'                  => true,
                'id'                          => $articulo->id,
                'name'                        => $articulo->name,
                'pivot'                       => (array) $fila,
                'cost'                        => (float) $fila->cost,
                'price_vender'                => (float) $fila->price,
                'amount'                      => (float) $fila->amount,
                'article_variant_id'          => (int) $fila->article_variant_id,
                'discount'                    => '',
                'checked_amount'              => '',
                'returned_amount'             => '',
                'delivered_amount'            => '',
                'price_type_personalizado_id' => 0,
            ];
        }

        return $renglones;
    }

    /**
     * Test 1 — la SPA NUEVA reabre y guarda sin tocar nada: el stock no se mueve, cada fila conserva
     * SU precio y SU base de descuentos, y el total es el de VENDER (sin restar el descuento al pie).
     *
     * @test
     */
    public function editar_con_un_renglon_de_varios_precios_conserva_las_bases_y_no_mueve_el_stock()
    {
        $articulo  = $this->articulo_propio('Varios precios con descuento y stock', ['stock' => self::STOCK_INICIAL]);
        $descuento = $this->descuento();

        $sale = $this->crear_venta_de_cinco($articulo, $descuento);

        $this->actualizar($sale, [$this->renglon_de_la_spa_nueva($sale, $articulo)], 315.00, 1, [$this->descuento_del_payload($descuento)]);

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'Guardar sin cambios no puede mover el stock.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas, 'La venta sigue con una fila por precio.');

        $this->assert_precio_y_base($filas[0], 45, 50, 'fila de 45 despues de editar', null);
        $this->assert_precio_y_base($filas[1], 90, 100, 'fila de 90 despues de editar', null);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEqualsWithDelta(315.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'La suma de las filas tiene que dar el total de VENDER, sin restar el descuento de nuevo.');
    }

    /**
     * Test 2 — una SPA ANTERIOR reabre con un renglon POR FILA y guarda sin tocar nada: la API la
     * rechaza con 409 (la clave del flag no viaja en su PUT) y NO se mueve nada: ni el stock, ni las
     * filas con sus bases, ni el flag, ni el total.
     *
     * @test
     */
    public function editar_con_un_renglon_por_fila_desde_una_spa_anterior_se_rechaza_y_no_mueve_nada()
    {
        $articulo  = $this->articulo_propio('Varios precios con descuento y stock', ['stock' => self::STOCK_INICIAL]);
        $descuento = $this->descuento();

        $sale = $this->crear_venta_de_cinco($articulo, $descuento);

        $payload = $this->payload_actualizar($sale, $this->renglones_de_la_spa_vieja($sale, $articulo), 315.00, 1, [$this->descuento_del_payload($descuento)], [
            'discount_stock' => 1,
        ]);

        unset($payload['aplicar_descuentos_directo_a_items']);

        $this->putJson('api/sale/'.$sale->id, $payload)->assertStatus(409);

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'El rechazo no puede mover stock.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 45, 50, 'fila de 45 despues del rechazo', null);
        $this->assert_precio_y_base($filas[1], 90, 100, 'fila de 90 despues del rechazo', null);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEqualsWithDelta(315.00, (float) $sale->total, self::DELTA);
        $this->assertEqualsWithDelta(315.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA);
    }

    /**
     * Test 3 — apagar la opcion editando, con varios precios: la SPA nueva manda las filas a su
     * precio original, sin bases. Las dos columnas quedan en NULL, el flag en 0, el stock intacto y
     * el total, el mismo (el 10 % pasa al pie): (100 × 2 + 50 × 3) = 350 menos 10 % = 315.
     *
     * @test
     */
    public function apagar_la_opcion_con_varios_precios_vuelve_al_precio_original()
    {
        $articulo  = $this->articulo_propio('Varios precios con descuento y stock', ['stock' => self::STOCK_INICIAL]);
        $descuento = $this->descuento();

        $sale = $this->crear_venta_de_cinco($articulo, $descuento);

        $renglon = $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 3, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null, 'price_vender_sin_descuentos' => null],
        ], ['cost' => (float) $articulo->cost]);

        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [$renglon], 315.00, 0, [$this->descuento_del_payload($descuento)], [
            'discount_stock' => 1,
            'sub_total'      => 350,
        ]))->assertStatus(200);

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'Apagar la opcion no mueve el stock.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assert_precio_y_base($filas[0], 50, null, 'fila de 50 apagada', null);
        $this->assert_precio_y_base($filas[1], 100, null, 'fila de 100 apagada', null);

        $sale = Sale::find($sale->id);

        $this->assertEquals(0, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEqualsWithDelta(315.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'Apagada, getTotalSale() resta el descuento al pie y da el mismo 315.');
    }
}
