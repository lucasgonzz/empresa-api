<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Budget;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;

/**
 * Archivo 13 — DESCUENTO Y RECARGO adentro del precio a la vez: la pila de capas entera.
 *
 *      original ──► [descuentos] ──► [recargos]        100 ──► 90 ──► 99   (10 % y 10 %)
 *
 *      price_sin_descuentos_de_venta = 100   (el original, debajo de las dos capas)
 *      price_sin_recargos_de_venta   =  90   (debajo de la capa de recargos, CON los descuentos)
 *      price                         =  99
 *
 * Lo que fija este archivo es que las dos columnas conviven y NO SE PISAN en ningun camino que
 * escribe o copia renglones (alta, edicion, update-prices, confirmar y duplicar), que cada una se
 * puede apagar por separado sin tocar a la otra, y que las dos guardas de las cuentas del back
 * (total de la venta, total del presupuesto) saltean sus ajustes a la vez: 99 es 99.
 *
 * @group descuentos_en_precios
 */
class Descuento_y_recargo_adentro_a_la_vez_Test extends DescuentosEnPreciosTestCase
{
    /**
     * Los dos ajustes de la suite, listos para meter en un payload.
     *
     * @return array [$descuento, $recargo, $payload_de_descuentos, $payload_de_recargos]
     */
    protected function ajustes()
    {
        $descuento = $this->descuento();
        $recargo   = $this->recargo();

        return [$descuento, $recargo, [$this->descuento_del_payload($descuento)], [$this->recargo_del_payload($recargo)]];
    }

    /**
     * Test 1 — el alta guarda las DOS bases, cada una con SU valor, en los cuatro tipos de renglon,
     * y `getTotalSale()` no suma ni resta nada al pie.
     *
     * @test
     */
    public function el_alta_guarda_las_dos_bases_sin_pisarse()
    {
        list($descuento, $recargo, $descuentos, $recargos) = $this->ajustes();

        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $combo    = $this->combo(200);
        $promo    = $this->promocion(300);

        /* 99 + 49,50 + 198 + 297 = 643,50: cada precio ya trae el 10 % menos y el 10 % mas. */
        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 99, 100, 1, 90),
            $this->item_vender('service', $servicio->id, 49.5, 50, 1, 45),
            $this->item_vender('combo', $combo->id, 198, 200, 1, 180),
            $this->item_vender('promocion_vinoteca', $promo->id, 297, 300, 1, 270),
        ], 643.50, 1, $descuentos, [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
        ]));

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 99, 100, 'articulo', 90);
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 49.5, 50, 'servicio', 45);
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 198, 200, 'combo', 180);
        $this->assert_precio_y_base($this->fila('promocion_vinoteca_sale', 'sale_id', $sale->id, 'promocion_vinoteca_id', $promo->id), 297, 300, 'promocion', 270);

        $this->assertEqualsWithDelta(643.50, SaleHelper::getTotalSale(Sale::find($sale->id), true, true, false, true), self::DELTA, 'Con las dos opciones prendidas el pie no suma el recargo ni resta el descuento.');
    }

    /**
     * Test 2 — apagar SOLO los recargos editando: el precio vuelve a 90 (con el descuento todavia
     * adentro), la base de recargos queda en NULL y la de descuentos NO SE TOCA (100). El recargo
     * pasa al pie: 90 × 1,1 = 99, el mismo total.
     *
     * @test
     */
    public function apagar_solo_los_recargos_deja_intacta_la_base_de_descuentos()
    {
        list($descuento, $recargo, $descuentos, $recargos) = $this->ajustes();

        $articulo = $this->articulo_centinela();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 99, 100, 1, 90),
        ], 99.00, 1, $descuentos, [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
        ]));

        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 90, 100, 1, null),
        ], 99.00, 1, $descuentos, [
            'aplicar_recargos_directo_a_items' => 0,
            'surchages'                        => $recargos,
            'sub_total'                        => 90,
        ]))->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEquals(0, (int) $sale->aplicar_recargos_directo_a_items);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'solo con el descuento adentro', null);

        $this->assertEqualsWithDelta(99.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'El recargo vuelve al pie sobre el precio ya descontado: 90 × 1,1.');
    }

    /**
     * Test 3 — apagar SOLO los descuentos editando: el precio vuelve a 110 (el original con el
     * recargo adentro), la base de descuentos queda en NULL y la de recargos pasa a ser el original
     * (100, ya sin descuentos debajo). El descuento pasa al pie: 110 × 0,9 = 99.
     *
     * @test
     */
    public function apagar_solo_los_descuentos_deja_el_recargo_con_su_nueva_base()
    {
        list($descuento, $recargo, $descuentos, $recargos) = $this->ajustes();

        $articulo = $this->articulo_centinela();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 99, 100, 1, 90),
        ], 99.00, 1, $descuentos, [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
        ]));

        $this->putJson('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            $this->item_vender('article', $articulo->id, 110, null, 1, 100),
        ], 99.00, 0, $descuentos, [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
            'sub_total'                        => 110,
        ]))->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(0, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 110, null, 'solo con el recargo adentro', 100);

        $this->assertEqualsWithDelta(99.00, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'El descuento vuelve al pie sobre el precio con el recargo: 110 × 0,9.');
    }

    /**
     * Test 4 — "Actualizar precios" escribe las dos bases a la vez, cada una por su clave: el modal
     * propone 108,90 (lista nueva de 110: 110 → 99 → 108,90) con sus dos bases.
     *
     * @test
     */
    public function actualizar_precios_escribe_las_dos_bases()
    {
        list($descuento, $recargo, $descuentos, $recargos) = $this->ajustes();

        $articulo = $this->articulo_centinela();

        $sale = $this->crear_venta($this->payload_venta([
            $this->item_vender('article', $articulo->id, 99, 100, 1, 90),
        ], 99.00, 1, $descuentos, [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
        ]));

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [[
                'is_article'                  => true,
                'id'                          => $articulo->id,
                'price_vender'                => 108.90,
                'price_vender_sin_recargos'   => 99,
                'price_vender_sin_descuentos' => 110,
            ]],
        ])->assertStatus(200);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 108.90, 110, 'actualizado con las dos', 99);
    }

    /**
     * Test 5 — el presupuesto con las dos capas: el alta guarda las dos bases en los cuatro tipos
     * de renglon (y `getTotal()` no resta ni suma nada), CONFIRMAR las pasa a la venta junto con los
     * dos flags, y DUPLICAR las copia.
     *
     * @test
     */
    public function el_presupuesto_confirma_y_duplica_las_dos_bases()
    {
        list($descuento, $recargo, $descuentos, $recargos) = $this->ajustes();

        $articulo = $this->articulo_centinela();
        $servicio = $this->servicio(50);
        $combo    = $this->combo(200);
        $promo    = $this->promocion(300);

        $extencion = ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'duplicar_presupuestos', 'name' => 'Duplicar presupuestos']);
        }

        $this->comercio()->extencions()->syncWithoutDetaching([$extencion->id]);

        /* 99 + 49,50 + 198 + 297 = 643,50. */
        $response = $this->postJson('api/budget', $this->payload_presupuesto(643.50, 1, $descuentos, [
            'articles'            => [$this->renglon_articulo_presupuesto($articulo->id, 99, 100, false, 1, 90)],
            'services'            => [$this->renglon_pivot_presupuesto($servicio->id, 49.5, 50, 1, 45)],
            'combos'              => [$this->renglon_pivot_presupuesto($combo->id, 198, 200, 1, 180)],
            'promocion_vinotecas' => [$this->renglon_pivot_presupuesto($promo->id, 297, 300, 1, 270)],
        ], [
            'aplicar_recargos_directo_a_items' => 1,
            'surchages'                        => $recargos,
        ]));

        $response->assertStatus(201);

        $budget_id = $response->json('model.id');

        $this->assertEqualsWithDelta(643.50, BudgetHelper::getTotal(Budget::withAll()->find($budget_id)), self::DELTA);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $budget_id, 'article_id', $articulo->id), 99, 100, 'presupuesto / articulo', 90);
        $this->assert_precio_y_base($this->fila('budget_service', 'budget_id', $budget_id, 'service_id', $servicio->id), 49.5, 50, 'presupuesto / servicio', 45);
        $this->assert_precio_y_base($this->fila('budget_combo', 'budget_id', $budget_id, 'combo_id', $combo->id), 198, 200, 'presupuesto / combo', 180);
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $budget_id, 'promocion_vinoteca_id', $promo->id), 297, 300, 'presupuesto / promocion', 270);

        /* Duplicar. */
        $duplicado = $this->postJson('api/budget/'.$budget_id.'/duplicate');

        $duplicado->assertStatus(201);

        $duplicado_id = $duplicado->json('model.id');

        $this->assertEquals(1, (int) Budget::find($duplicado_id)->aplicar_descuentos_directo_a_items);
        $this->assertEquals(1, (int) Budget::find($duplicado_id)->aplicar_recargos_directo_a_items);

        $this->assert_precio_y_base($this->fila('article_budget', 'budget_id', $duplicado_id, 'article_id', $articulo->id), 99, 100, 'duplicado / articulo', 90);
        $this->assert_precio_y_base($this->fila('budget_promocion_vinoteca', 'budget_id', $duplicado_id, 'promocion_vinoteca_id', $promo->id), 297, 300, 'duplicado / promocion', 270);

        /* Confirmar. */
        $this->postJson('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        $this->assertEquals(1, (int) $sale->aplicar_descuentos_directo_a_items);
        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items);

        $this->assert_precio_y_base($this->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 99, 100, 'venta / articulo', 90);
        $this->assert_precio_y_base($this->fila('sale_service', 'sale_id', $sale->id, 'service_id', $servicio->id), 49.5, 50, 'venta / servicio', 45);
        $this->assert_precio_y_base($this->fila('combo_sale', 'sale_id', $sale->id, 'combo_id', $combo->id), 198, 200, 'venta / combo', 180);
        $this->assert_precio_y_base($this->fila('promocion_vinoteca_sale', 'sale_id', $sale->id, 'promocion_vinoteca_id', $promo->id), 297, 300, 'venta / promocion', 270);

        $this->assertEqualsWithDelta(643.50, SaleHelper::getTotalSale($sale, true, true, false, true), self::DELTA, 'La venta que nace del presupuesto no suma el recargo ni resta el descuento de nuevo.');
    }
}
