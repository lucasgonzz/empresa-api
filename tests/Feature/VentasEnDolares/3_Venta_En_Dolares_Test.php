<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Venta EN DÓLARES (`moneda_id = 2`, `valor_dolar = 1200`) de una cuenta como la de 2R
 * (`cotizar_precios_en_dolares = 0`), con un renglón de un artículo cargado en pesos y otro de un
 * artículo cargado en dólares.
 *
 * LAS REGLAS QUE SE VERIFICAN:
 *
 *  - `items[].price_vender` llega YA en dólares (`convertir_precio_a_moneda_de_la_venta()` de la SPA):
 *    el artículo en pesos llega como `final_price / valor_dolar` (1500 / 1200 = 1,25) y el artículo
 *    en dólares llega tal cual (15).
 *  - `SaleHelper::getCost()`: en una venta en dólares, un artículo SIN `cost_in_dollars` guarda su
 *    costo dividido por el `valor_dolar` DE LA VENTA (1000 / 1200 = 0,8333), no por el dólar global
 *    de la cuenta (1000 en estas pruebas); un artículo CON `cost_in_dollars` guarda su costo sin
 *    tocar (10).
 *  - `article_sale.ganancia = (price - cost) * amount` y `sales.ganancia = total - total_cost - IVA
 *    declarado` (0 en una venta de mostrador), todo en dólares.
 *
 * 🔴 REDONDEO. `article_sale.price`/`cost` y `sales.total`/`total_cost` son DECIMAL(25,2) /
 * DECIMAL(22,2): en dólares el costo unitario 0,8333 se guarda como 0,83 y ese 0,83 es el que
 * `SaleTotalesHelper::set_total_cost()` multiplica por la cantidad. Por eso las comparaciones de
 * acá usan como tolerancia medio centavo POR UNIDAD vendida de un artículo convertido (el error
 * máximo de guardar el unitario con 2 decimales); el comportamiento del redondeo en sí, con
 * cantidades grandes, se prueba aparte en `Redondeo_En_Dolares_Test`.
 *
 * @group ventas-en-dolares
 */
class Venta_En_Dolares_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    /** Tolerancia de medio centavo (2 decimales). */
    const DELTA = 0.005;

    /** @var \App\Models\Article Artículo cargado en pesos (costo 1000, margen 50 % => 1500). */
    protected $en_pesos;

    /** @var \App\Models\Article Artículo cargado en dólares (costo 10 USD, margen 50 % => 15 USD). */
    protected $en_dolares;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_escenario_2r('2015-05-01 10:00:00');

        $this->en_pesos   = $this->crear_articulo('zz Articulo pesos venta usd', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50], 500);
        $this->en_dolares = $this->crear_articulo('zz Articulo dolares venta usd', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50], 500);
    }

    protected function tearDown(): void
    {
        $this->limpiar_escenario_2r();

        parent::tearDown();
    }

    /**
     * @param  int    $moneda_id
     * @param  float  $cant_pesos
     * @param  float  $cant_dolares
     * @return array
     */
    protected function items($moneda_id, $cant_pesos, $cant_dolares)
    {
        return [
            $this->item($this->en_pesos, $cant_pesos, $this->price_vender_para($this->en_pesos, $moneda_id, $this->VALOR_DOLAR)),
            $this->item($this->en_dolares, $cant_dolares, $this->price_vender_para($this->en_dolares, $moneda_id, $this->VALOR_DOLAR)),
        ];
    }

    /**
     * Venta en dólares con cantidades enteras (2 y 3). Todo tiene que quedar en dólares:
     *  - A (pesos): precio 1,25, costo 1000/1200 = 0,8333, ganancia (1,25-0,8333)*2 = 0,8333;
     *  - B (dólares): precio 15, costo 10, ganancia 5*3 = 15;
     *  - venta: total 2*1,25 + 3*15 = 47,50, costo 2*0,8333 + 30 = 31,6667, ganancia 15,8333.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_cantidades_enteras_guarda_todo_en_dolares()
    {
        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $this->items(2, 2, 3)));

        $this->assertEquals(2, (int) $venta->moneda_id);
        $this->assertEquals(1200, (int) $venta->valor_dolar);

        $total = 2 * (1500 / 1200) + 3 * 15;
        $costo = 2 * (1000 / 1200) + 3 * 10;

        $this->assertEqualsWithDelta($total, (float) $venta->sub_total, self::DELTA);
        $this->assertEqualsWithDelta($total, (float) $venta->total, self::DELTA);

        $a = $this->pivot_de($venta, $this->en_pesos);
        $this->assertEqualsWithDelta(1500 / 1200, (float) $a->price, self::DELTA, 'El articulo en pesos, vendido en dolares, es precio / valor_dolar.');
        $this->assertEqualsWithDelta(1000 / 1200, (float) $a->cost, self::DELTA, 'El costo del articulo en pesos, vendido en dolares, es costo / valor_dolar DE LA VENTA (no el dolar global, que da 1,00).');
        $this->assertEqualsWithDelta((1.25 - 1000 / 1200) * 2, (float) $a->ganancia, self::DELTA);

        $b = $this->pivot_de($venta, $this->en_dolares);
        $this->assertEqualsWithDelta(15, (float) $b->price, self::DELTA, 'El articulo en dolares vendido en dolares queda tal cual.');
        $this->assertEqualsWithDelta(10, (float) $b->cost, self::DELTA, 'El costo del articulo en dolares vendido en dolares queda tal cual (sin dividir).');
        $this->assertEqualsWithDelta(5 * 3, (float) $b->ganancia, self::DELTA);

        // Tolerancia: medio centavo por unidad convertida (2 unidades del articulo en pesos).
        $this->assertEqualsWithDelta($costo, (float) $venta->total_cost, self::DELTA * 2 + self::DELTA);
        $this->assertEqualsWithDelta($total - $costo, (float) $venta->ganancia, self::DELTA * 2 + self::DELTA);
    }

    /**
     * Cantidades decimales (2,5 y 1,5) en dólares: multiplican por la cantidad exacta.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_cantidades_decimales_multiplica_por_la_cantidad_exacta()
    {
        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $this->items(2, 2.5, 1.5)));

        $a = $this->pivot_de($venta, $this->en_pesos);
        $b = $this->pivot_de($venta, $this->en_dolares);

        $this->assertEqualsWithDelta(2.5, (float) $a->amount, self::DELTA);
        $this->assertEqualsWithDelta(1.5, (float) $b->amount, self::DELTA);
        $this->assertEqualsWithDelta((1.25 - 1000 / 1200) * 2.5, (float) $a->ganancia, self::DELTA);
        $this->assertEqualsWithDelta(5 * 1.5, (float) $b->ganancia, self::DELTA);

        $total = 2.5 * (1500 / 1200) + 1.5 * 15;
        $costo = 2.5 * (1000 / 1200) + 1.5 * 10;

        $this->assertEqualsWithDelta($total, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta($costo, (float) $venta->total_cost, self::DELTA * 2.5 + self::DELTA);
        $this->assertEqualsWithDelta($total - $costo, (float) $venta->ganancia, self::DELTA * 2.5 + 2 * self::DELTA);
    }

    /**
     * COMPARACIÓN con la venta en pesos equivalente (mismos renglones y cantidades, moneda 1): la
     * ganancia en dólares por el `valor_dolar` tiene que coincidir con la ganancia en pesos. Los
     * costos y el total también, cada uno por separado. La tolerancia es un centavo de dólar por
     * cada magnitud guardada (1 USD cent * 1200 = 12 pesos): el guardado en dólares redondea a 2
     * decimales, el de pesos también, pero un centavo de dólar vale 12 pesos.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function la_ganancia_en_dolares_por_el_valor_dolar_coincide_con_la_de_pesos()
    {
        $en_pesos = $this->guardar_venta($this->payload_venta(1, $this->VALOR_DOLAR, $this->items(1, 2.5, 1.5)));
        $en_usd   = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $this->items(2, 2.5, 1.5)));

        $tolerancia = 0.01 * $this->VALOR_DOLAR;

        $this->assertEqualsWithDelta(
            (float) $en_pesos->total,
            (float) $en_usd->total * $this->VALOR_DOLAR,
            $tolerancia,
            'El total en dolares por el valor_dolar no coincide con el total en pesos.'
        );

        $this->assertEqualsWithDelta(
            (float) $en_pesos->total_cost,
            (float) $en_usd->total_cost * $this->VALOR_DOLAR,
            $tolerancia,
            'El costo en dolares por el valor_dolar no coincide con el costo en pesos.'
        );

        $this->assertEqualsWithDelta(
            (float) $en_pesos->ganancia,
            (float) $en_usd->ganancia * $this->VALOR_DOLAR,
            $tolerancia,
            'La ganancia en dolares por el valor_dolar no coincide con la de la venta en pesos equivalente.'
        );
    }

    /**
     * Descuento 10 % y recargo 5 % en dólares: el total de la SPA se guarda tal cual y la ganancia
     * es total - total_cost (el costo no se toca con `aplicar_descuentos_de_venta_a_costos = 0`).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_descuento_y_recargo_no_toca_el_costo()
    {
        $descuento = $this->crear_descuento(10);
        $recargo   = $this->crear_recargo(5);

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $this->items(2, 2, 3), [
            'discounts' => [['id' => $descuento->id, 'percentage' => 10]],
            'surchages' => [['id' => $recargo->id, 'percentage' => 5]],
        ]));

        $sub_total = 2 * (1500 / 1200) + 3 * 15;
        $total     = $sub_total * 0.90 * 1.05;
        $costo     = 2 * (1000 / 1200) + 3 * 10;

        $this->assertEqualsWithDelta($sub_total, (float) $venta->sub_total, self::DELTA);
        $this->assertEqualsWithDelta($total, (float) $venta->total, self::DELTA);
        $this->assertEqualsWithDelta($costo, (float) $venta->total_cost, 0.015);
        $this->assertEqualsWithDelta($total - $costo, (float) $venta->ganancia, 0.02);
    }

    /**
     * Con `aplicar_descuentos_de_venta_a_costos = 1` el costo se convierte a dólares Y DESPUÉS se
     * ajusta por descuentos/recargos: A = (1000/1200) * 0,9 * 1,05 = 0,7875; B = 10 * 0,945 = 9,45.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_descuentos_aplicados_a_costos_ajusta_el_costo_ya_convertido()
    {
        User::where('id', $this->dueno->id)->update(['aplicar_descuentos_de_venta_a_costos' => 1]);

        $descuento = $this->crear_descuento(10);
        $recargo   = $this->crear_recargo(5);

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, $this->items(2, 2, 3), [
            'discounts' => [['id' => $descuento->id, 'percentage' => 10]],
            'surchages' => [['id' => $recargo->id, 'percentage' => 5]],
        ]));

        $factor = 0.90 * 1.05;

        $this->assertEqualsWithDelta((1000 / 1200) * $factor, (float) $this->pivot_de($venta, $this->en_pesos)->cost, self::DELTA);
        $this->assertEqualsWithDelta(10 * $factor, (float) $this->pivot_de($venta, $this->en_dolares)->cost, self::DELTA);
    }

    /**
     * Con IVA 21 % en dólares: los precios con IVA son 1815/1200 = 1,5125 y 18,15; `iva_percentage`
     * queda '21' y `price_sin_iva = round(price_vender / 1,21, 2)` (se calcula sobre el precio sin
     * redondear que manda la SPA). El costo es neto.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_iva_21_guarda_iva_y_precio_sin_iva_en_dolares()
    {
        $a = $this->crear_articulo('zz Articulo pesos iva usd', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2], 100);
        $b = $this->crear_articulo('zz Articulo dolares iva usd', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => 50, 'aplicar_iva' => 1, 'iva_id' => 2], 100);

        $pa = $this->price_vender_para($a, 2, $this->VALOR_DOLAR);
        $pb = $this->price_vender_para($b, 2, $this->VALOR_DOLAR);

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($a, 2, $pa),
            $this->item($b, 1, $pb),
        ]));

        $fa = $this->pivot_de($venta, $a);
        $fb = $this->pivot_de($venta, $b);

        $this->assertEquals('21', (string) $fa->iva_percentage);
        $this->assertEquals('21', (string) $fb->iva_percentage);
        $this->assertEqualsWithDelta(round($pa / 1.21, 2), (float) $fa->price_sin_iva, self::DELTA);
        $this->assertEqualsWithDelta(round($pb / 1.21, 2), (float) $fb->price_sin_iva, self::DELTA);
        $this->assertEqualsWithDelta(1000 / 1200, (float) $fa->cost, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $fb->cost, self::DELTA);
    }

    /**
     * Precios MANUALES: el artículo en pesos con precio manual 2000 llega como 2000/1200 = 1,6667 y
     * el artículo en dólares con precio manual 25 llega como 25 (ya está en dólares).
     *
     * @group ventas-en-dolares
     * @test
     */
    public function venta_en_dolares_con_articulos_de_precio_manual()
    {
        $mp = $this->crear_articulo('zz Articulo pesos manual usd', ['cost' => 1000, 'cost_in_dollars' => 0, 'percentage_gain' => null, 'price' => 2000], 100);
        $md = $this->crear_articulo('zz Articulo dolares manual usd', ['cost' => 10, 'cost_in_dollars' => 1, 'percentage_gain' => null, 'price' => 25], 100);

        $venta = $this->guardar_venta($this->payload_venta(2, $this->VALOR_DOLAR, [
            $this->item($mp, 3, $this->price_vender_para($mp, 2, $this->VALOR_DOLAR)),
            $this->item($md, 2, $this->price_vender_para($md, 2, $this->VALOR_DOLAR)),
        ]));

        $fp = $this->pivot_de($venta, $mp);
        $fd = $this->pivot_de($venta, $md);

        $this->assertEqualsWithDelta(2000 / 1200, (float) $fp->price, self::DELTA);
        $this->assertEqualsWithDelta(1000 / 1200, (float) $fp->cost, self::DELTA);
        $this->assertEqualsWithDelta(25, (float) $fd->price, self::DELTA);
        $this->assertEqualsWithDelta(10, (float) $fd->cost, self::DELTA);
        $this->assertEqualsWithDelta(3 * (2000 / 1200) + 2 * 25, (float) $venta->total, self::DELTA);
    }
}
