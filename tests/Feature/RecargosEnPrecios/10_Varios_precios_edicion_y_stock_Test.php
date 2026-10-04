<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\ConceptoStockMovement;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 10 — `varios_precios`: editar la venta y el stock (mision varios-precios-descuento-renglon,
 * 3/10/2026).
 *
 * Hasta esta mision `SaleHelper::attachArticles()` descontaba el stock POR RENGLON, y en la edicion
 * `get_amount_for_stock_movement()` le resta a la SUMA de los renglones previos del articulo +
 * variante la cantidad del renglon que recibe. Con dos renglones del mismo articulo en el request la
 * diferencia se contaba dos veces. Es lo que hace la SPA de hoy al reabrir una venta con varios
 * precios: arma UN RENGLON POR FILA del pivot, y guardarla sin tocar nada devolvia al stock todo lo
 * vendido (medido en s10: −3 de la venta, +1 y +2 de los dos "Act Venta").
 *
 * Ahora el stock se junta por articulo + variante y se descuenta una vez por clave. Las dos SPA
 * conviven (la PWA va 1-2 releases atras): la nueva reabre esas filas como UN renglon con varios
 * precios, la vieja sigue mandando un renglon por fila, y las dos tienen que dejar el stock bien.
 *
 * Todo con un articulo PROPIO con stock 20 y `discount_stock` = 1.
 *
 * @group recargos_en_precios
 */
class Varios_precios_edicion_y_stock_Test extends RecargosEnPreciosTestCase
{
    /** Stock con el que arranca el articulo de cada test. */
    const STOCK_INICIAL = 20;

    /**
     * El articulo del test, con stock.
     *
     * @return \App\Models\Article
     */
    protected function articulo_con_stock()
    {
        return $this->articulo_propio('Varios precios con stock', ['stock' => self::STOCK_INICIAL]);
    }

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
     * Movimientos de stock del articulo en ESA venta, en orden, opcionalmente de un concepto.
     *
     * @param  \App\Models\Article  $articulo
     * @param  \App\Models\Sale     $sale
     * @param  string|null          $concepto  'Venta' | 'Act Venta' | 'Se elimino de la venta'
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos($articulo, $sale, $concepto = null)
    {
        $query = StockMovement::where('article_id', $articulo->id)
                    ->where('sale_id', $sale->id)
                    ->orderBy('id', 'ASC');

        if (!is_null($concepto)) {

            $concepto_model = ConceptoStockMovement::where('name', $concepto)->first();

            $this->assertNotNull($concepto_model, 'El fixture no tiene el concepto de stock "'.$concepto.'".');

            $query->where('concepto_stock_movement_id', $concepto_model->id);
        }

        return $query->get();
    }

    /**
     * El renglon con el que VENDER crea la venta: 100 × 2 + 50 × 3 (5 unidades) con 10 % de
     * descuento de renglon. Total (200 + 150) × 0,9 = 315.
     *
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function renglon_del_alta($articulo)
    {
        return $this->renglon_con_varios_precios($articulo, [
            ['id' => 1, 'price_vender' => 100, 'amount' => 2, 'price_vender_con_recargos' => 100, 'price_vender_sin_recargos' => null],
            ['id' => 0, 'price_vender' => 50, 'amount' => 3, 'price_vender_con_recargos' => 50, 'price_vender_sin_recargos' => null],
        ], [
            'discount' => 10,
            'cost'     => (float) $articulo->cost,
        ]);
    }

    /**
     * Crea la venta del alta (5 unidades) y afirma que descontó las 5.
     *
     * @param  \App\Models\Article  $articulo
     * @return \App\Models\Sale
     */
    protected function crear_venta_de_cinco($articulo)
    {
        $sale = $this->crear_venta($this->payload_venta([$this->renglon_del_alta($articulo)], 315.00, 0, [], ['discount_stock' => 1]));

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'El alta tenia que descontar 2 + 3.');

        return $sale;
    }

    /**
     * Los renglones con los que la SPA DE HOY reabre la venta (`previus_sale/index.js`): uno por fila
     * del pivot, cada uno con su pivot, su precio, su cantidad y su descuento, y la variante en 0
     * (`Number(null)`).
     *
     * @param  \App\Models\Sale     $sale
     * @param  \App\Models\Article  $articulo
     * @param  array                $cantidades  precio => cantidad nueva, para cambiar alguna.
     * @return array
     */
    protected function renglones_de_la_spa_vieja($sale, $articulo, $cantidades = [])
    {
        $renglones = [];

        foreach ($this->filas_de_la_venta($sale->id, $articulo->id) as $fila) {

            $precio = (float) $fila->price;

            $cantidad = isset($cantidades[(string) $precio]) ? $cantidades[(string) $precio] : (float) $fila->amount;

            $renglones[] = [
                'is_article'                  => true,
                'id'                          => $articulo->id,
                'name'                        => $articulo->name,
                'pivot'                       => (array) $fila,
                'cost'                        => (float) $fila->cost,
                'price_vender'                => $precio,
                'price_vender_sin_recargos'   => null,
                'amount'                      => $cantidad,
                'article_variant_id'          => 0,
                'discount'                    => is_null($fila->discount) ? '' : (float) $fila->discount,
                'checked_amount'              => '',
                'returned_amount'             => '',
                'delivered_amount'            => '',
                'price_type_personalizado_id' => 0,
            ];
        }

        return $renglones;
    }

    /**
     * El renglon con el que la SPA NUEVA reabre la venta (`utils/varios_precios_guardados.js`): el
     * primer renglon del grupo, con su pivot, y una fila de varios precios por fila guardada.
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
                'id'                         => $indice,
                'price_vender'               => (float) $fila->price,
                'amount'                     => (float) $fila->amount,
                'desde_comprobante_guardado' => true,
                'price_vender_con_recargos'  => (float) $fila->price,
                'price_vender_sin_recargos'  => null,
            ];
        }

        return $this->renglon_con_varios_precios($articulo, $varios_precios, [
            'pivot'                       => (array) $primera,
            'cost'                        => (float) $primera->cost,
            'price_vender'                => (float) $primera->price,
            'article_variant_id'          => 0,
            'discount'                    => is_null($primera->discount) ? '' : (float) $primera->discount,
            'checked_amount'              => '',
            'returned_amount'             => '',
            'delivered_amount'            => '',
            'price_type_personalizado_id' => 0,
        ]);
    }

    /**
     * PUT de la venta por el endpoint real, descontando stock.
     *
     * @param  \App\Models\Sale  $sale
     * @param  array             $items
     * @param  float             $total
     * @return void
     */
    protected function actualizar($sale, $items, $total)
    {
        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, $items, $total, 0, [], [
            'discount_stock' => 1,
        ]))->assertStatus(200);
    }

    /**
     * Test 8 — la SPA NUEVA reabre la venta como UN renglon con varios precios y se guarda sin
     * tocar nada: el stock no se mueve, las filas siguen con el descuento y el total es el de VENDER.
     *
     * @test
     */
    public function editar_con_un_renglon_de_varios_precios_sin_cambios_no_mueve_el_stock()
    {
        $articulo = $this->articulo_con_stock();

        $sale = $this->crear_venta_de_cinco($articulo);

        $movimientos_antes = $this->movimientos($articulo, $sale)->count();

        $this->actualizar($sale, [$this->renglon_de_la_spa_nueva($sale, $articulo)], 315.00);

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'Guardar sin cambios no puede mover el stock.');
        $this->assertEquals($movimientos_antes, $this->movimientos($articulo, $sale)->count(), 'Guardar sin cambios no deja movimientos.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas, 'La venta sigue con una fila por precio.');

        $this->assertEqualsWithDelta(3, (float) $filas[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) $filas[1]->amount, self::DELTA);

        foreach ($filas as $fila) {
            $this->assertNotNull($fila->discount, 'La fila de '.(float) $fila->price.' quedo sin el descuento del renglon.');
            $this->assertEqualsWithDelta(10, (float) $fila->discount, self::DELTA);
            $this->assertEqualsWithDelta((float) $articulo->cost, (float) $fila->cost, self::DELTA, 'La edicion congela el costo de la fila.');
        }

        $sale = Sale::find($sale->id);

        $this->assertEqualsWithDelta(315.00, (float) $sale->total, self::DELTA);
        $this->assertEqualsWithDelta(315.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'La suma de las filas tiene que dar el total de VENDER.');
    }

    /**
     * Test 9 — la SPA VIEJA reabre la venta con un renglon POR FILA y se guarda sin tocar nada: el
     * stock no se mueve. Antes cada renglon se comparaba contra la suma de los dos previos y la venta
     * devolvia las 5 unidades (+3 y +2).
     *
     * @test
     */
    public function editar_con_un_renglon_por_fila_sin_cambios_no_devuelve_el_stock()
    {
        $articulo = $this->articulo_con_stock();

        $sale = $this->crear_venta_de_cinco($articulo);

        $movimientos_antes = $this->movimientos($articulo, $sale)->count();

        $this->actualizar($sale, $this->renglones_de_la_spa_vieja($sale, $articulo), 315.00);

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'Guardar sin cambios desde la SPA vieja no puede devolver stock.');
        $this->assertEquals($movimientos_antes, $this->movimientos($articulo, $sale)->count(), 'Guardar sin cambios no deja movimientos.');

        $filas = $this->filas_de_la_venta($sale->id, $articulo->id);

        $this->assertCount(2, $filas);

        $this->assertEqualsWithDelta(3, (float) $filas[0]->amount, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) $filas[1]->amount, self::DELTA);
    }

    /**
     * Test 10 — la SPA VIEJA cambia la cantidad de una fila (100 × 2 → 100 × 4): sale UN solo
     * "Act Venta" de −2 y el stock queda en 13. Antes salian dos (+2 y +1) y el stock subia a 18.
     *
     * @test
     */
    public function editar_con_un_renglon_por_fila_cambiando_una_cantidad_mueve_solo_la_diferencia()
    {
        $articulo = $this->articulo_con_stock();

        $sale = $this->crear_venta_de_cinco($articulo);

        /* (100 × 4 + 50 × 3) × 0,9 = 495. */
        $this->actualizar($sale, $this->renglones_de_la_spa_vieja($sale, $articulo, ['100' => 4]), 495.00);

        $this->assertEqualsWithDelta(13, $this->stock($articulo), self::DELTA, 'Pasar de 5 a 7 unidades tiene que descontar 2 mas.');

        $ajustes = $this->movimientos($articulo, $sale, 'Act Venta');

        $this->assertCount(1, $ajustes, 'Tiene que haber UN solo ajuste por el articulo, no uno por renglon.');
        $this->assertEqualsWithDelta(-2, (float) $ajustes->first()->amount, self::DELTA, 'El ajuste es la diferencia: −2.');

        $this->assertCount(2, $this->movimientos($articulo, $sale), 'La venta queda con la Venta del alta y el ajuste, nada mas.');
    }

    /**
     * Test 11 — un alta con DOS renglones sueltos del mismo articulo (un camino que no es VENDER:
     * VENDER los fusiona en una linea) descuenta la suma en UN solo movimiento "Venta" de −5. Antes
     * salian dos movimientos (−2 y −3): el stock daba igual, pero el libro tenia un movimiento por
     * renglon y no por articulo.
     *
     * @test
     */
    public function alta_con_dos_renglones_sueltos_del_mismo_articulo_descuenta_la_suma_una_vez()
    {
        $articulo = $this->articulo_con_stock();

        /* 100 × 2 + 50 × 3 = 350. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 100, null, 2),
            $this->item_vender('article', $articulo->id, 50, null, 3),
        ], 350.00, 0, [], ['discount_stock' => 1]));

        $this->assertCount(2, $this->filas_de_la_venta($sale->id, $articulo->id), 'Cada renglon suelto es su propia fila.');

        $this->assertEqualsWithDelta(15, $this->stock($articulo), self::DELTA, 'El alta tiene que descontar 2 + 3.');

        $ventas = $this->movimientos($articulo, $sale, 'Venta');

        $this->assertCount(1, $ventas, 'El stock se descuenta UNA vez por articulo + variante, no una por renglon.');
        $this->assertEqualsWithDelta(-5, (float) $ventas->first()->amount, self::DELTA);
    }

    /**
     * Test 12 — un renglon con `varios_precios` VACIO (el vendedor borro todas las filas con el
     * tachito) es un renglon comun: una fila a su precio y su cantidad, y el stock descuenta esa
     * cantidad. Antes entraba a la rama de varios precios, no adjuntaba nada (la fila la ponia
     * despues `check_que_este_el_articulos()`, por casualidad) y el stock descontaba 0.
     *
     * @test
     */
    public function un_varios_precios_vacio_es_un_renglon_comun_y_descuenta_su_cantidad()
    {
        $articulo = $this->articulo_con_stock();

        $renglon = $this->renglon_con_varios_precios($articulo, [], [
            'price_vender' => 150,
            'amount'       => 4,
        ]);

        /* 150 × 4 = 600. */
        $sale = $this->crear_venta($this->payload_venta([$renglon], 600.00, 0, [], ['discount_stock' => 1]));

        $fila = $this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id);

        $this->assertEqualsWithDelta(150, (float) $fila->price, self::DELTA, 'La fila va al precio del renglon.');
        $this->assertEqualsWithDelta(4, (float) $fila->amount, self::DELTA, 'La fila va con la cantidad del renglon.');

        $this->assertEqualsWithDelta(16, $this->stock($articulo), self::DELTA, 'El stock tiene que descontar las 4 unidades del renglon.');

        $ventas = $this->movimientos($articulo, $sale, 'Venta');

        $this->assertCount(1, $ventas);
        $this->assertEqualsWithDelta(-4, (float) $ventas->first()->amount, self::DELTA);
    }
}
