<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Venta EN PESOS (`moneda_id = 1`, `valor_dolar = 1200`) de una cuenta como la de 2R, con un
 * renglón de un artículo cargado en pesos y otro de un artículo cargado en dólares
 * (`cost_in_dollars = 1`, `cotizar_precios_en_dolares = 0`).
 *
 * LAS REGLAS QUE SE VERIFICAN (ver `EscenarioDeMonedas` para el detalle de lo que manda la SPA):
 *
 *  - `items[].price_vender` llega YA en pesos: el artículo en dólares llega como `final_price *
 *    valor_dolar` (15 USD * 1200 = 18.000). La API lo persiste tal cual en `article_sale.price`.
 *  - `SaleHelper::getCost()`: en una venta en pesos, un artículo con `cost_in_dollars = 1` guarda su
 *    costo multiplicado por el `valor_dolar` DE LA VENTA (10 * 1200 = 12.000), no por el dólar global
 *    de la cuenta (`users.dollar`, que en estas pruebas vale 1000 justamente para distinguirlos).
 *  - `SaleHelper::attachArticle()`: `article_sale.ganancia = (price - cost) * amount`, en pesos.
 *  - `SaleTotalesHelper::set_total_cost()`: `sales.total_cost = SUM(pivot.cost * pivot.amount)`.
 *  - `SaleHelper::calcular_ganancia()`: `sales.ganancia = total - total_cost - IVA declarado`. Una
 *    venta de mostrador sin comprobante de ARCA declara IVA 0 (`IvaDeVentaHelper`), así que la
 *    ganancia es `total - total_cost`.
 *  - `sub_total` y `total` los arma la SPA y la API los guarda como llegan (no los recalcula).
 *
 * Los valores esperados se calculan en el test con la cuenta a la vista (nunca memorizados).
 *
 * @group ventas-en-dolares
 */
class Venta_En_Pesos_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    /** Tolerancia de medio centavo: las columnas de dinero guardan 2 decimales. */
    const DELTA = 0.005;

    /** @var \App\Models\Article Artículo cargado en pesos (costo 1000, margen 50 % => 1500). */
    protected $en_pesos;

    /** @var \App\Models\Article Artículo cargado en dólares (costo 10 USD, margen 50 % => 15 USD). */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-04-01 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos venta', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares venta', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * Los dos renglones con cantidades enteras: 2 del artículo en pesos y 3 del artículo en dólares.
     *
     * @param  float  $cant_pesos
     * @param  float  $cant_dolares
     * @return array
     */
    protected function items_en_pesos($cant_pesos, $cant_dolares)
    {
        return [
            $this->item($this->en_pesos, $cant_pesos, $this->price_vender_para($this->en_pesos, 1, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, $cant_dolares, $this->price_vender_para($this->en_dolares, 1, $this->VALOR_DOLAR)),
        ];
    }

    /**
     * Venta en pesos con cantidades enteras. Todo lo que se guarda tiene que estar en pesos:
     *  - renglón A: precio 1500, costo 1000, ganancia (1500-1000)*2 = 1000;
     *  - renglón B: precio 18000 (15 USD * 1200), costo 12000 (10 USD * 1200), ganancia 6000*3 = 18000;
     *  - venta: total 57000, costo 38000, ganancia 19000; moneda_id 1 y valor_dolar 1200.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_cantidades_enteras_guarda_todo_en_pesos()
    {
        $items = $this->items_en_pesos(2, 3);

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items));

        $this->assertEquals(1, (int) $venta->moneda_id);
        $this->assertEquals(1200, (int) $venta->valor_dolar);
        $this->assertEqualsWithDelta(2 * 1500 + 3 * 18000, (float) $venta->sub_total, self::DELTA);
        $this->assertEqualsWithDelta(2 * 1500 + 3 * 18000, (float) $venta->total, self::DELTA);

        $a = $this->pivot_de($venta, $this->en_pesos);
        $this->assertEqualsWithDelta(2, (float) $a->amount, self::DELTA);
        $this->assertEqualsWithDelta(1500, (float) $a->price, self::DELTA, 'El precio del articulo en pesos tiene que quedar en pesos.');
        $this->assertEqualsWithDelta(1000, (float) $a->cost, self::DELTA, 'El costo del articulo en pesos tiene que quedar en pesos.');
        $this->assertEqualsWithDelta((1500 - 1000) * 2, (float) $a->ganancia, self::DELTA);

        $b = $this->pivot_de($venta, $this->en_dolares);
        $this->assertEqualsWithDelta(3, (float) $b->amount, self::DELTA);
        $this->assertEqualsWithDelta(15 * 1200, (float) $b->price, self::DELTA, 'El precio del articulo en dolares, vendido en pesos, es 15 USD * valor_dolar de la venta.');
        $this->assertEqualsWithDelta(10 * 1200, (float) $b->cost, self::DELTA, 'El costo del articulo en dolares, vendido en pesos, es 10 USD * valor_dolar de la venta (no el dolar global de la cuenta, que vale 1000).');
        $this->assertEqualsWithDelta((18000 - 12000) * 3, (float) $b->ganancia, self::DELTA);

        $costo_esperado = 1000 * 2 + 12000 * 3;

        $this->assertEqualsWithDelta($costo_esperado, (float) $venta->total_cost, self::DELTA);
        $this->assertEqualsWithDelta((2 * 1500 + 3 * 18000) - $costo_esperado, (float) $venta->ganancia, self::DELTA);
    }

    /**
     * Cantidades DECIMALES (2,5 y 1,5): `article_sale.amount` guarda 2 decimales, y precio, costo y
     * ganancia siguen en pesos y multiplican por la cantidad decimal exacta. Además la ganancia de la
     * venta es la suma de las ganancias de los renglones (no hay IVA declarado en una venta de
     * mostrador sin comprobante).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_cantidades_decimales_multiplica_por_la_cantidad_exacta()
    {
        $items = $this->items_en_pesos(2.5, 1.5);

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items));

        $a = $this->pivot_de($venta, $this->en_pesos);
        $b = $this->pivot_de($venta, $this->en_dolares);

        $this->assertEqualsWithDelta(2.5, (float) $a->amount, self::DELTA);
        $this->assertEqualsWithDelta(1.5, (float) $b->amount, self::DELTA);
        $this->assertEqualsWithDelta((1500 - 1000) * 2.5, (float) $a->ganancia, self::DELTA);
        $this->assertEqualsWithDelta((18000 - 12000) * 1.5, (float) $b->ganancia, self::DELTA);

        $total_esperado = 1500 * 2.5 + 18000 * 1.5;
        $costo_esperado = 1000 * 2.5 + 12000 * 1.5;

        $this->assertEqualsWithDelta($total_esperado, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta($costo_esperado, (float) $venta->total_cost, self::DELTA);
        $this->assertEqualsWithDelta($total_esperado - $costo_esperado, (float) $venta->ganancia, self::DELTA);
        $this->assertEqualsWithDelta(
            (float) $a->ganancia + (float) $b->ganancia,
            (float) $venta->ganancia,
            self::DELTA,
            'Sin IVA declarado y sin descuentos de venta la ganancia de la venta es la suma de las de sus renglones.'
        );
    }

    /**
     * Descuento de venta del 10 % y recargo del 5 % (con `aplicar_descuentos_de_venta_a_costos = 0`,
     * que es lo que trae el fixture): el `total` que manda la SPA (sub_total * 0,90 * 1,05) se guarda
     * tal cual, el COSTO no se toca, y por lo tanto la ganancia de la venta baja lo que bajó el
     * total: total - total_cost. Los porcentajes quedan en las pivots `discount_sale` /
     * `sale_surchage`.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_descuento_y_recargo_no_toca_el_costo_y_la_ganancia_sigue_al_total()
    {
        $descuento = $this->crear_descuento(10);
        $recargo   = $this->crear_recargo(5);

        $items = $this->items_en_pesos(2, 3);

        $payload = $this->payload_venta(1, $this->VALOR_DOLAR, $items, [
            'discounts' => [['id' => $descuento->id, 'percentage' => 10]],
            'surchages' => [['id' => $recargo->id, 'percentage' => 5]],
        ]);

        $venta = $this->guardar_venta($payload);

        $sub_total = 2 * 1500 + 3 * 18000;
        $total     = $sub_total * 0.90 * 1.05;
        $costo     = 1000 * 2 + 12000 * 3;

        $this->assertEqualsWithDelta($sub_total, (float) $venta->sub_total, self::DELTA);
        $this->assertEqualsWithDelta($total, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta($costo, (float) $venta->total_cost, self::DELTA, 'Sin aplicar_descuentos_de_venta_a_costos el costo no cambia con el descuento.');
        $this->assertEqualsWithDelta($total - $costo, (float) $venta->ganancia, self::DELTA);

        $this->assertEquals(
            10,
            (float) DB::table('discount_sale')->where('sale_id', $venta->id)->value('percentage'),
            'El porcentaje del descuento tiene que quedar en la pivot de la venta.'
        );
        $this->assertEquals(
            5,
            (float) DB::table('sale_surchage')->where('sale_id', $venta->id)->value('percentage'),
            'El porcentaje del recargo tiene que quedar en la pivot de la venta.'
        );
    }

    /**
     * Con `aplicar_descuentos_de_venta_a_costos = 1` el costo de cada renglón se ajusta por los
     * descuentos y recargos de la venta DESPUÉS de convertirlo a la moneda de la venta
     * (`getCost()`: primero * valor_dolar, luego -10 % y +5 %). Renglón A: 1000 * 0,9 * 1,05 = 945;
     * renglón B: 12000 * 0,9 * 1,05 = 11340.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_descuentos_aplicados_a_costos_ajusta_el_costo_ya_convertido()
    {
        User::where('id', $this->dueno->id)->update(['aplicar_descuentos_de_venta_a_costos' => 1]);

        $descuento = $this->crear_descuento(10);
        $recargo   = $this->crear_recargo(5);

        $items = $this->items_en_pesos(2, 3);

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items, [
            'discounts' => [['id' => $descuento->id, 'percentage' => 10]],
            'surchages' => [['id' => $recargo->id, 'percentage' => 5]],
        ]));

        $factor = 0.90 * 1.05;

        $a = $this->pivot_de($venta, $this->en_pesos);
        $b = $this->pivot_de($venta, $this->en_dolares);

        $this->assertEqualsWithDelta(1000 * $factor, (float) $a->cost, self::DELTA);
        $this->assertEqualsWithDelta(12000 * $factor, (float) $b->cost, self::DELTA);
        $this->assertEqualsWithDelta((1000 * 2 + 12000 * 3) * $factor, (float) $venta->total_cost, 0.01);
    }

    /**
     * Con IVA 21 % (fixture: el IVA se suma al precio de venta, no al costo): los artículos salen a
     * 1815 pesos y 18,15 USD; en una venta en pesos el segundo llega a 21.780. `article_sale`
     * conserva `iva_percentage = '21'` y `price_sin_iva = price / 1,21` por renglón, en pesos.
     * `sales.ganancia` es `total - total_cost` porque una venta sin comprobante no declara IVA (el
     * IVA cobrado queda dentro de la ganancia; es el criterio de `IvaDeVentaHelper`).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_con_iva_21_guarda_iva_y_precio_sin_iva_en_pesos()
    {
        $a = $this->crear_articulo('zz Articulo pesos iva venta', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2], 100);
        $b = $this->crear_articulo('zz Articulo dolares iva venta', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2], 100);

        $items = [
            $this->item($a, 2, $this->price_vender_para($a, 1, $this->VALOR_DOLAR)),
            $this->item($b, 1, $this->price_vender_para($b, 1, $this->VALOR_DOLAR)),
        ];

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items));

        $pa = $this->pivot_de($venta, $a);
        $pb = $this->pivot_de($venta, $b);

        $this->assertEqualsWithDelta(1815, (float) $pa->price, self::DELTA);
        $this->assertEqualsWithDelta(18.15 * 1200, (float) $pb->price, self::DELTA);
        $this->assertEquals('21', (string) $pa->iva_percentage);
        $this->assertEquals('21', (string) $pb->iva_percentage);
        $this->assertEqualsWithDelta(round(1815 / 1.21, 2), (float) $pa->price_sin_iva, self::DELTA);
        $this->assertEqualsWithDelta(round(21780 / 1.21, 2), (float) $pb->price_sin_iva, self::DELTA);

        // El costo es neto (el IVA no entra al costo).
        $this->assertEqualsWithDelta(1000, (float) $pa->cost, self::DELTA);
        $this->assertEqualsWithDelta(12000, (float) $pb->cost, self::DELTA);

        $total = 1815 * 2 + 21780;
        $costo = 1000 * 2 + 12000;

        $this->assertEqualsWithDelta($total, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta($costo, (float) $venta->total_cost, self::DELTA);
        $this->assertEqualsWithDelta($total - $costo, (float) $venta->ganancia, self::DELTA);
    }

    /**
     * El artículo en dólares con PRECIO MANUAL (25 USD) vendido en pesos llega como 25 * 1200 =
     * 30.000, y su costo sigue siendo 10 USD * 1200 = 12.000: el precio manual no altera la
     * conversión del costo.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_pesos_de_articulo_en_dolares_con_precio_manual()
    {
        $m = $this->crear_articulo('zz Articulo dolares manual venta', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => null, 'price' => 25], 100);

        $items = [$this->item($m, 2, $this->price_vender_para($m, 1, $this->VALOR_DOLAR))];

        $venta = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $items));

        $p = $this->pivot_de($venta, $m);

        $this->assertEqualsWithDelta(30000, (float) $p->price, self::DELTA);
        $this->assertEqualsWithDelta(12000, (float) $p->cost, self::DELTA);
        $this->assertEqualsWithDelta((30000 - 12000) * 2, (float) $p->ganancia, self::DELTA);
        $this->assertEqualsWithDelta(60000, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta(24000, (float) $venta->total_cost, self::DELTA);
        $this->assertEqualsWithDelta(36000, (float) $venta->ganancia, self::DELTA);
    }
}
