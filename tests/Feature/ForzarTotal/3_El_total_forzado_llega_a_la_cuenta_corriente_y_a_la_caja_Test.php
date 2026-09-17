<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\CurrentAcount;
use App\Models\Sale;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 3 — EL CAMINO DONDE EL BACK RECALCULA EL TOTAL SOLO, sin que VENDER participe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUE ESTE ARCHIVO SE REESCRIBIO ENTERO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La primera version de este archivo afirmaba que la cuenta corriente y la caja recibian el
 *  total forzado, creando una venta por el endpoint y leyendo `current_acounts.debe` y
 *  `movimiento_cajas.ingreso`. Pasaba — pero NO PROBABA NADA de lo que esta mision construyo:
 *  los dos numeros salen de `$request->total` pelado, asi que el test daba verde igual con
 *  `forzar_total_monto` borrado del controller. Lo midio un chequeo independiente, sacando la
 *  clave de `SaleController::store()`: los dos tests siguieron en verde.
 *
 *  Es la trampa clasica del test de integracion: mide un camino real, pero uno que ya andaba.
 *
 *  Lo que SI depende del campo es el camino donde el total no lo manda el front:
 *  `SaleHelper::attachProperies()` llama a `update_total_sale()` cuando una venta `to_check` se
 *  CONFIRMA por primera vez, porque el total que mando VENDER no contempla las unidades que el
 *  deposito chequeo. Ahi `getTotalSale()` rehace la cuenta desde los renglones y el forzado tiene
 *  que seguir estando. Si no esta, el cliente queda debiendo el bruto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 Y ES EL MISMO CAMINO DONDE EL TOTAL PUEDE IRSE A NEGATIVO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El monto queda fijo. Si el deposito chequea muchas menos unidades de las vendidas, la base
 *  baja por debajo del monto y el total forzado daria NEGATIVO. El segundo test fija la guarda:
 *  no se aplica el monto, se deja rastro en el log, y NUNCA se clampea en silencio.
 *
 * @group sales
 * @group forzar_total
 */
class El_total_forzado_llega_a_la_cuenta_corriente_y_a_la_caja_Test extends ForzarTotalTestCase
{
    /**
     * Una venta a cuenta corriente ya chequeada por el deposito, lista para confirmarse.
     *
     * Se crea directo en la base y no por el endpoint porque lo que importa es el ESTADO —`to_check`
     * con `checked` en 1 y sin confirmar—, que es en el que la deja el circuito de deposito, no
     * como se llego a el.
     *
     * @param  float  $price
     * @param  float  $amount
     * @param  float  $monto
     * @return \App\Models\Sale
     */
    protected function venta_chequeada_sin_confirmar($price, $amount, $monto)
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        $sale = $this->crear_venta_en_base([
            'client_id'                  => $cliente->id,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 1,
            'checked'                    => 1,
            'confirmed'                  => 0,
            'terminada'                  => 0,
            'sub_total'                  => $price * $amount,
            'total'                      => ($price * $amount) + $monto,
            'forzar_total_monto'         => $monto,
        ]);

        $this->enganchar_articulo($sale, TestingFerreteriaSeeder::ARTICULO_CENTINELA, $price, $amount);

        return $sale->fresh();
    }

    /**
     * Confirma la venta por el endpoint real, mandando las unidades que el deposito chequeo.
     *
     * `checked_amount` viaja en cada item del request: es lo que lee `SaleHelper::getAmount()`
     * para decidir la cantidad real del renglon cuando la venta se esta confirmando.
     *
     * ⚠️ El request manda `to_check` y `checked` en 0 y `confirmed` en 1: al confirmarla, la venta
     * SALE del circuito de deposito. Los dos importan y por motivos distintos:
     *
     *  - `SaleHelper::get_se_esta_confirmando()` mira `$sale->checked` LEIDO DE LA BASE (que sigue
     *    en 1) contra `$request->confirmed`, asi que el recalculo se dispara igual.
     *  - `SaleController::update()` solo rehace la cuenta corriente `if (!$model->to_check &&
     *    !$model->checked)`, o sea con los valores YA pisados por el request. Mandando 1 y 1, la
     *    venta queda confirmada pero el cliente nunca recibe el movimiento.
     *
     * @param  \App\Models\Sale  $sale
     * @param  float             $price
     * @param  float             $amount
     * @param  float             $checked_amount
     * @param  float             $monto
     * @return \Illuminate\Testing\TestResponse
     */
    protected function confirmar($sale, $price, $amount, $checked_amount, $monto)
    {
        return $this->actualizar_venta($sale, [
            'client_id'                  => $sale->client_id,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 1,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => $price * $amount,
            'total'                      => ($price * $amount) + $monto,
            'forzar_total_monto'         => $monto,
            'items'                      => [
                [
                    'is_article'     => true,
                    'id'             => $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA)->id,
                    'price_vender'   => $price,
                    'amount'         => $amount,
                    'checked_amount' => $checked_amount,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ]);
    }

    /**
     * Test 1 — al confirmar, el back rehace el total con las unidades chequeadas Y CON el forzado,
     * y ese es el numero que le queda debiendo el cliente.
     *
     * Se venden 10 a $500 (bruto 5.000) forzado a 4.988 (monto -12). El deposito chequea 8, o sea
     * que la venta real es de 4.000: el total tiene que quedar en 3.988, no en 4.000 ni en 4.988.
     *
     * @group forzar_total
     * @test
     */
    public function al_confirmar_el_back_rehace_el_total_sin_perder_el_forzado()
    {
        $sale = $this->venta_chequeada_sin_confirmar(500.00, 10, self::MONTO);

        $this->confirmar($sale, 500.00, 10, 8, self::MONTO)->assertStatus(200);

        $fila = Sale::find($sale->id);

        // 8 x 500 = 4.000, menos los 12 del ajuste = 3.988. Calculado a mano.
        $this->assertEqualsWithDelta(
            3988.00,
            (float) $fila->total,
            self::DELTA,
            'el total recalculado tiene que ser 3.988: las 8 unidades chequeadas MENOS el ajuste. '.
            'Si diera 4.000, el forzado se perdio en el recalculo'
        );

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $fila->forzar_total_monto,
            self::DELTA,
            'el monto tiene que seguir guardado despues de confirmar'
        );

        $movimiento = CurrentAcount::where('sale_id', $sale->id)->first();

        $this->assertNotNull($movimiento, 'La venta confirmada tiene que tener su movimiento de cuenta corriente.');

        $this->assertEqualsWithDelta(
            3988.00,
            (float) $movimiento->debe,
            self::DELTA,
            'el cliente tiene que quedar debiendo el total recalculado, no el bruto'
        );
    }

    /**
     * Test 2 — LA GUARDA DEL TOTAL NEGATIVO, del lado del back.
     *
     * Se venden 10 a $10 (bruto 100) forzado a 88 (monto -12). El deposito chequea UNA sola
     * unidad: la base baja a 10 y el forzado daria **-2**.
     *
     * 🔴 Ese -2 iria derecho a la cuenta corriente y al importe del medio de pago, sin que nadie lo
     * mire en el camino: la SPA no participa de este recalculo. La guarda descarta el forzado y
     * deja el total de los renglones que quedaron.
     *
     * Y NO se clampea a 0: un total pisado a cero sin que nadie lo diga es plata que desaparece sin
     * rastro. Se deja el numero real y el warning en el log.
     *
     * @group forzar_total
     * @test
     */
    public function si_los_items_chequeados_dejan_el_total_en_negativo_no_se_aplica_el_forzado()
    {
        $sale = $this->venta_chequeada_sin_confirmar(10.00, 10, self::MONTO);

        $this->confirmar($sale, 10.00, 10, 1, self::MONTO)->assertStatus(200);

        $fila = Sale::find($sale->id);

        $this->assertEqualsWithDelta(
            10.00,
            (float) $fila->total,
            self::DELTA,
            'con una sola unidad chequeada el total tiene que ser 10 —los renglones que quedaron—, '.
            'no -2 (forzado aplicado a ciegas) ni 0 (clampeado en silencio)'
        );

        $this->assertGreaterThanOrEqual(
            0,
            (float) $fila->total,
            'el total de una venta nunca puede quedar negativo'
        );

        $movimiento = CurrentAcount::where('sale_id', $sale->id)->first();

        $this->assertNotNull($movimiento, 'La venta confirmada tiene que tener su movimiento de cuenta corriente.');

        $this->assertGreaterThanOrEqual(
            0,
            (float) $movimiento->debe,
            'y la cuenta corriente tampoco puede recibir un debe negativo'
        );
    }
}
