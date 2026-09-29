<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Edición, baja y stock de una venta en dólares (cuenta como la de 2R).
 *
 * LO QUE LA SPA MANDA AL EDITAR. Al reabrir una venta guardada, la SPA arma cada renglón con el
 * precio del PIVOT (`price_desde_pivot` en `check_moneda()`: convertirlo otra vez sería cotizar dos
 * veces) y con `pivot.cost`, que `SaleHelper::getCost()` devuelve tal cual (`isset($item['pivot']
 * ['cost'])`). El `sub_total`/`total` los recalcula la SPA sobre esos precios. Estas pruebas emulan
 * eso con `EscenarioDeMonedas::item_de_edicion()`.
 *
 * REGLAS QUE SE VERIFICAN:
 *  - `SaleController::update()` NO toca `moneda_id` ni `valor_dolar` (la línea está comentada:
 *    `// $model->valor_dolar = $request->valor_dolar;`): editar no re-cotiza.
 *  - Precio, costo y ganancia de los renglones que no cambian siguen en dólares y no se recotizan.
 *  - Un renglón NUEVO agregado en la edición no trae `pivot` y se costea con el `valor_dolar` guardado
 *    en la venta.
 *  - `PUT api/sale/update-prices/{id}` cambia solo el precio y recalcula la ganancia de la línea con
 *    el costo GUARDADO (`SaleHelper::ganancia_de_linea()`), sin recotizar.
 *  - Stock: vender en dólares descuenta la misma cantidad que vender en pesos; editar la cantidad
 *    mueve el stock por la diferencia ("Act Venta"); eliminar la venta devuelve lo que sacó.
 *
 * @group ventas-en-dolares
 */
class Edicion_Baja_Y_Stock_De_Venta_En_Dolares_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    const DELTA = 0.005;

    /** Stock inicial de cada artículo. */
    const STOCK = 1000;

    /** @var \App\Models\Article Artículo en pesos con precio manual 1000 (costo 1000). */
    protected $en_pesos;

    /** @var \App\Models\Article Artículo en dólares (costo 10, precio 15). */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-10-01 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos edicion', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => null, 'price' => 1000], self::STOCK);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares edicion', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], self::STOCK);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Venta en dólares del artículo en pesos (3 u.) y del artículo en dólares (2 u.).
     *
     * @return \App\Models\Sale
     */
    protected function venta_en_dolares($cant_pesos = 3, $cant_dolares = 2)
    {
        return $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, $cant_pesos, $this->price_vender_para($this->en_pesos, 2, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, $cant_dolares, $this->price_vender_para($this->en_dolares, 2, $this->VALOR_DOLAR)),
        ]));
    }

    /**
     * Manda el PUT y exige 200.
     *
     * @param  \App\Models\Sale  $venta
     * @param  array  $payload
     * @return void
     */
    protected function editar($venta, $payload)
    {
        $response = $this->putJson('api/sale/'.$venta->id, $payload);

        $this->assertEquals(200, $response->getStatusCode(), 'PUT api/sale/'.$venta->id.' no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 500));
    }

    /**
     * Editar la cantidad del renglón en dólares (2 -> 4): precio, costo y ganancia de ese renglón
     * siguen en dólares (15 / 10 / 5 por unidad), la venta sigue en moneda 2 y con `valor_dolar`
     * 1200 AUNQUE el PUT mande otro dólar (1500): editar no recotiza.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function editar_la_cantidad_mantiene_precio_costo_y_ganancia_en_dolares_sin_recotizar()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $items = [
            $this->item_de_edicion($this->en_pesos, $venta, 3),
            $this->item_de_edicion($this->en_dolares, $venta, 4),
        ];

        $this->editar($venta, $this->payload_edicion_venta($venta, $items, ['valor_dolar' => 1500]));

        $venta = Sale::find($venta->id);

        $this->assertEquals(2, (int) $venta->moneda_id, 'La edicion no puede cambiar la moneda de la venta.');
        $this->assertEquals(1200, (int) $venta->valor_dolar, 'La edicion no re-cotiza: valor_dolar queda en el de la venta.');

        $b = $this->pivot_de($venta, $this->en_dolares);
        $this->assertEqualsWithDelta(4, (float) $b->amount, self::DELTA);
        $this->assertEqualsWithDelta(15, (float) $b->price, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $b->cost, self::DELTA);
        $this->assertEqualsWithDelta((15 - 10) * 4, (float) $b->ganancia, self::DELTA);

        $a = $this->pivot_de($venta, $this->en_pesos);
        $this->assertEqualsWithDelta(1000 / 1200, (float) $a->cost, self::DELTA, 'El costo del renglon que no cambio se recotizo.');

        $this->assertEqualsWithDelta(
            (float) $a->cost * 3 + 10 * 4,
            (float) $venta->total_cost,
            self::DELTA,
            'total_cost tiene que ser la suma de costo * cantidad de los renglones en dolares.'
        );
        $this->assertEqualsWithDelta(
            (float) $venta->total - (float) $venta->total_cost,
            (float) $venta->ganancia,
            self::DELTA
        );
    }

    /**
     * Un renglón NUEVO agregado al editar (artículo en pesos, sin `pivot`) se costea con el
     * `valor_dolar` que la venta tiene guardado: 1000 / 1200 = 0,83 por unidad, en dólares. El
     * precio que manda la SPA para un renglón nuevo sí se convierte (1,25).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function un_renglon_agregado_en_la_edicion_se_costea_con_el_valor_dolar_de_la_venta()
    {
        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($this->en_dolares, 2, 15),
        ]));

        $items = [
            $this->item_de_edicion($this->en_dolares, $venta, 2),
            $this->item($this->en_pesos, 2, $this->price_vender_para($this->en_pesos, 2, $this->VALOR_DOLAR)),
        ];

        $this->editar($venta, $this->payload_edicion_venta($venta, $items));

        $nuevo = $this->pivot_de(Sale::find($venta->id), $this->en_pesos);

        $this->assertNotNull($nuevo, 'El renglon agregado en la edicion no quedo en la venta.');
        $this->assertEqualsWithDelta(1000 / 1200, (float) $nuevo->cost, self::DELTA);
        $this->assertEqualsWithDelta(1000 / 1200, (float) $nuevo->price, self::DELTA);
    }

    /**
     * 🔴 HALLAZGO. Guardar una venta en dólares SIN cambiarle nada cambia su total. El artículo en
     * pesos se vendió a 0,8333 y `article_sale.price` lo guardó como 0,83; al editar, la SPA manda
     * ese precio ya redondeado (el del pivot) y el `total` que recalcula es 0,83 * cantidad: la
     * venta original decía 32,50 (3 * 0,8333 + 2 * 15) y después de un "guardar" sin cambios dice
     * 32,49. Con cantidades grandes la deriva crece (medio centavo por unidad) y con precios muy
     * chicos el renglón baja a 0. Código: `SaleHelper::attachArticle()` guarda el precio en
     * DECIMAL(25,2) y `SaleController::update()` persiste el `total` que llega.
     *
     * @group ventas-en-dolares
     * @group hallazgo-moneda
     * @test
     */
    public function guardar_una_venta_en_dolares_sin_cambios_no_cambia_el_total()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $total_original = (float) $venta->total;

        $items = [
            $this->item_de_edicion($this->en_pesos, $venta, 3),
            $this->item_de_edicion($this->en_dolares, $venta, 2),
        ];

        $this->editar($venta, $this->payload_edicion_venta($venta, $items));

        $this->assertEqualsWithDelta(
            $total_original,
            (float) Sale::find($venta->id)->total,
            0.001,
            'HALLAZGO: el total cambio de '.$total_original.' a '.Sale::find($venta->id)->total.' al guardar sin tocar nada (el pivot guarda el precio con 2 decimales y la edicion lo reenvia).'
        );
    }

    /**
     * CONTROL en pesos: la misma edición sin cambios de una venta en pesos deja el total idéntico
     * (los precios en pesos no pierden nada al guardarse con 2 decimales).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function control_guardar_una_venta_en_pesos_sin_cambios_no_cambia_el_total()
    {
        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, 3, $this->price_vender_para($this->en_pesos, 1, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, 2, $this->price_vender_para($this->en_dolares, 1, $this->VALOR_DOLAR)),
        ]));

        $total_original = (float) $venta->total;

        $items = [
            $this->item_de_edicion($this->en_pesos, $venta, 3),
            $this->item_de_edicion($this->en_dolares, $venta, 2),
        ];

        $this->editar($venta, $this->payload_edicion_venta($venta, $items));

        $this->assertEqualsWithDelta($total_original, (float) Sale::find($venta->id)->total, 0.001);
    }

    /**
     * `PUT api/sale/update-prices/{id}` en una venta en dólares: cambia el precio de cada renglón
     * (1,50 y 20 USD), deja el costo guardado como estaba (0,83 y 10: no recotiza) y recalcula la
     * ganancia de la línea como (precio nuevo - costo guardado) * cantidad, en dólares.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function update_prices_cambia_el_precio_y_recalcula_la_ganancia_de_linea_sin_recotizar()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $costo_a = (float) $this->pivot_de($venta, $this->en_pesos)->cost;
        $costo_b = (float) $this->pivot_de($venta, $this->en_dolares)->cost;

        $response = $this->putJson('api/sale/update-prices/'.$venta->id, [
            'items' => [
                $this->item($this->en_pesos, 3, 1.5),
                $this->item($this->en_dolares, 2, 20),
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode(), 'update-prices no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 400));

        $a = $this->pivot_de($venta, $this->en_pesos);
        $b = $this->pivot_de($venta, $this->en_dolares);

        $this->assertEqualsWithDelta(1.5, (float) $a->price, self::DELTA);
        $this->assertEqualsWithDelta(20, (float) $b->price, self::DELTA);
        $this->assertEqualsWithDelta($costo_a, (float) $a->cost, 0.0001, 'update-prices no puede tocar el costo guardado.');
        $this->assertEqualsWithDelta($costo_b, (float) $b->cost, 0.0001, 'update-prices no puede tocar el costo guardado.');
        $this->assertEqualsWithDelta((1.5 - $costo_a) * 3, (float) $a->ganancia, self::DELTA);
        $this->assertEqualsWithDelta((20 - $costo_b) * 2, (float) $b->ganancia, self::DELTA);

        $this->assertEquals(2, (int) Sale::find($venta->id)->moneda_id);
    }

    /**
     * Vender en dólares descuenta el stock igual que vender en pesos: la misma cantidad (con
     * decimales, 2,5 y 1,5) de los mismos artículos resta lo mismo, sin importar la moneda.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function vender_en_dolares_descuenta_el_mismo_stock_que_vender_en_pesos()
    {
        $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, 2.5, $this->price_vender_para($this->en_pesos, 1, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, 1.5, $this->price_vender_para($this->en_dolares, 1, $this->VALOR_DOLAR)),
        ]));

        $descuento_pesos_a = self::STOCK - $this->stock_de($this->en_pesos);
        $descuento_pesos_b = self::STOCK - $this->stock_de($this->en_dolares);

        $this->assertEqualsWithDelta(2.5, $descuento_pesos_a, 0.0001);
        $this->assertEqualsWithDelta(1.5, $descuento_pesos_b, 0.0001);

        $antes_a = $this->stock_de($this->en_pesos);
        $antes_b = $this->stock_de($this->en_dolares);

        $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($this->en_pesos, 2.5, $this->price_vender_para($this->en_pesos, 2, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, 1.5, $this->price_vender_para($this->en_dolares, 2, $this->VALOR_DOLAR)),
        ]));

        $this->assertEqualsWithDelta($descuento_pesos_a, $antes_a - $this->stock_de($this->en_pesos), 0.0001, 'La venta en dolares descuento otro stock del articulo en pesos.');
        $this->assertEqualsWithDelta($descuento_pesos_b, $antes_b - $this->stock_de($this->en_dolares), 0.0001, 'La venta en dolares descuento otro stock del articulo en dolares.');
    }

    /**
     * Editar la cantidad en una venta en dólares mueve el stock por la DIFERENCIA (movimiento "Act
     * Venta"): 3 -> 5 unidades saca 2 más; 5 -> 1 devuelve 4.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function editar_la_cantidad_de_una_venta_en_dolares_mueve_el_stock_por_la_diferencia()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $this->assertEqualsWithDelta(self::STOCK - 3, $this->stock_de($this->en_pesos), 0.0001);

        $items = [
            $this->item_de_edicion($this->en_pesos, $venta, 5),
            $this->item_de_edicion($this->en_dolares, $venta, 2),
        ];
        $this->editar($venta, $this->payload_edicion_venta($venta, $items));

        $this->assertEqualsWithDelta(self::STOCK - 5, $this->stock_de($this->en_pesos), 0.0001, 'Pasar de 3 a 5 tenia que sacar 2 mas.');
        $this->assertEqualsWithDelta(self::STOCK - 2, $this->stock_de($this->en_dolares), 0.0001, 'El renglon que no cambio no puede mover stock.');

        $venta = Sale::find($venta->id);

        $items = [
            $this->item_de_edicion($this->en_pesos, $venta, 1),
            $this->item_de_edicion($this->en_dolares, $venta, 2),
        ];
        $this->editar($venta, $this->payload_edicion_venta($venta, $items));

        $this->assertEqualsWithDelta(self::STOCK - 1, $this->stock_de($this->en_pesos), 0.0001, 'Pasar de 5 a 1 tenia que devolver 4.');
    }

    /**
     * Eliminar la venta en dólares (`DELETE api/sale/{id}`): el stock vuelve exactamente a lo de
     * antes, el neto de `stock_movements` de la venta queda en cero por artículo, `article_purchases`
     * (lo que leen los reportes por artículo) queda sin filas de la venta y la venta queda en la
     * papelera (soft delete), fuera del listado.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function eliminar_una_venta_en_dolares_devuelve_el_stock_y_no_deja_restos()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $this->assertEqualsWithDelta(self::STOCK - 3, $this->stock_de($this->en_pesos), 0.0001);
        $this->assertEqualsWithDelta(self::STOCK - 2, $this->stock_de($this->en_dolares), 0.0001);
        $this->assertGreaterThan(0, DB::table('article_purchases')->where('sale_id', $venta->id)->count(), 'Sanidad: la venta genero article_purchases.');

        $response = $this->deleteJson('api/sale/'.$venta->id);

        $this->assertEquals(200, $response->getStatusCode(), 'DELETE no devolvio 200. Cuerpo: '.substr($response->getContent(), 0, 300));

        $this->assertEqualsWithDelta(self::STOCK, $this->stock_de($this->en_pesos), 0.0001, 'El stock del articulo en pesos no volvio.');
        $this->assertEqualsWithDelta(self::STOCK, $this->stock_de($this->en_dolares), 0.0001, 'El stock del articulo en dolares no volvio.');

        foreach ([$this->en_pesos, $this->en_dolares] as $articulo) {
            $neto = (float) DB::table('stock_movements')
                ->where('sale_id', $venta->id)
                ->where('article_id', $articulo->id)
                ->sum('amount');

            $this->assertEqualsWithDelta(0, $neto, 0.0001, 'El neto de movimientos de stock de la venta eliminada tiene que ser cero.');
        }

        $this->assertEquals(0, DB::table('article_purchases')->where('sale_id', $venta->id)->count(), 'Quedaron article_purchases de la venta eliminada.');

        $this->assertNull(Sale::find($venta->id), 'La venta eliminada sigue visible.');
        $this->assertNotNull(Sale::withTrashed()->find($venta->id), 'La venta eliminada tiene que quedar en la papelera (soft delete).');
    }

    /**
     * Eliminar la venta dos veces (doble clic): la segunda es un 404 y NO repone stock de nuevo.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function eliminar_dos_veces_una_venta_en_dolares_no_repone_el_stock_dos_veces()
    {
        $venta = $this->venta_en_dolares(3, 2);

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);
        $segunda = $this->deleteJson('api/sale/'.$venta->id);

        $this->assertEquals(404, $segunda->getStatusCode());
        $this->assertEqualsWithDelta(self::STOCK, $this->stock_de($this->en_pesos), 0.0001, 'El segundo DELETE repuso stock de mas.');
        $this->assertEqualsWithDelta(self::STOCK, $this->stock_de($this->en_dolares), 0.0001);
    }
}
