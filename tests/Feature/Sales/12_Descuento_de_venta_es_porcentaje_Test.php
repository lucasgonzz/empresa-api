<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 12 — `sales.descuento` ES PORCENTAJE, también para `SaleHelper::getTotalSale()`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE BLINDA (tanda correctivos 24/8, item 21)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El campo `sales.descuento` (el descuento global que se tipea en Vender) se aplicaba de dos
 *  maneras incompatibles:
 *
 *   - La SPA (`vender_set_total.js::aplicar_descuento()`) y el calculador de AFIP
 *     (`AfipItemCalculator::get_article_price_with_discounts()`) lo tratan como PORCENTAJE.
 *   - `SaleHelper::getTotalSale()` lo restaba como MONTO FIJO en pesos.
 *
 *  Lucas confirmó el 24/8/2026 que ES porcentaje. La discrepancia pegaba en los caminos donde
 *  el backend recalcula el total y PISA el que mandó el front: la confirmación de una venta
 *  chequeada (`attachProperies()` → `update_total_sale()`) y la reparación masiva
 *  (`set_total_sales()`). Una venta de $1.000 con descuento 10 quedaba en $990 (le restaba
 *  $10) en vez de $900 (el −10% que vio el vendedor en pantalla).
 *
 *  Los esperados de estos tests están CALCULADOS A MANO en cada aserción — nunca contra otro
 *  método del mismo helper, que es justamente lo que está bajo prueba.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sales
 */
class Descuento_de_venta_es_porcentaje_Test extends EmpresaTestCase
{
    /**
     * Delta para comparaciones de plata (nunca igualdad exacta sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Crea una venta mínima del comercio del fixture, sin cuenta corriente ni caja.
     *
     * @param  array  $overrides  Campos a pisar (descuento, total, ...).
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Sale::create(array_merge([
            'user_id'                    => $user->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ], $overrides));
    }

    /**
     * Venta con un solo renglón de artículo: 10 unidades a $100 = $1.000 brutos.
     *
     * Números redondos a propósito: un rojo se lee como error de lógica, no como ruido
     * de redondeo.
     *
     * @param  array  $overrides  Campos de la venta a pisar.
     * @return \App\Models\Sale
     */
    protected function venta_de_mil($overrides = [])
    {
        $sale = $this->crear_venta($overrides);

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $this->assertNotNull($articulo, 'Falta el artículo centinela del fixture.');

        $sale->articles()->attach($articulo->id, [
            'amount' => 10,
            'price'  => 100.00,
        ]);

        return $sale->fresh();
    }

    /**
     * El total que calcula el backend para la venta, con relaciones recargadas.
     *
     * @param  \App\Models\Sale  $sale
     * @return float
     */
    protected function total_del_backend($sale)
    {
        return (float) SaleHelper::getTotalSale($sale, true, true, false, true);
    }

    /**
     * Test 1 - el caso de la especificación: venta de $1.000 con descuento 10 tiene que dar
     * $900 (el −10% que calcula el front), no $990 (los $10 que restaba el criterio viejo).
     *
     * @group sales
     * @test
     */
    public function descuento_10_deja_el_total_en_el_90_por_ciento()
    {
        $sale = $this->venta_de_mil(['descuento' => 10]);

        // 1000 - (1000 * 10 / 100) = 900. Calculado a mano, no contra otro método del helper.
        $this->assertEqualsWithDelta(
            900.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'con descuento 10, el total del backend tiene que ser el -10% (900), no el total menos $10 (990)'
        );
    }

    /**
     * Test 2 - un descuento con decimales delata el criterio: 0,5 es medio por ciento ($5
     * sobre $1.000), no 50 centavos. Con el criterio viejo daba $999,50.
     *
     * @group sales
     * @test
     */
    public function descuento_decimal_tambien_es_porcentaje()
    {
        $sale = $this->venta_de_mil(['descuento' => 0.5]);

        // 1000 - (1000 * 0.5 / 100) = 995.
        $this->assertEqualsWithDelta(
            995.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'descuento 0,5 = medio por ciento: el total tiene que ser 995, no 999,50'
        );
    }

    /**
     * Test 3 - un descuento NEGATIVO es como el sistema representa un recargo global, y la SPA
     * lo aplica igual (`if (this.descuento)` es verdadero con negativos). La guarda truthy de
     * `getTotalSale()` tiene que seguir ese criterio — el mismo que documenta
     * `PuntosBaseHelper::factor_descuentos_de_venta()`.
     *
     * @group sales
     * @test
     */
    public function descuento_negativo_recarga_en_porcentaje()
    {
        $sale = $this->venta_de_mil(['descuento' => -10]);

        // 1000 - (1000 * -10 / 100) = 1100.
        $this->assertEqualsWithDelta(
            1100.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'descuento -10 es un recargo global del 10%: el total tiene que ser 1100'
        );
    }

    /**
     * Test 4 - sin descuento, nada cambia: el total sigue siendo la suma de los renglones.
     * Es la no-regresión del camino común (la enorme mayoría de las ventas tiene descuento 0).
     *
     * @group sales
     * @test
     */
    public function sin_descuento_el_total_es_la_suma_de_renglones()
    {
        $sale = $this->venta_de_mil();

        $this->assertEqualsWithDelta(
            1000.00,
            $this->total_del_backend($sale),
            self::DELTA,
            'sin descuento, el total tiene que ser la suma bruta de los renglones'
        );
    }
}
