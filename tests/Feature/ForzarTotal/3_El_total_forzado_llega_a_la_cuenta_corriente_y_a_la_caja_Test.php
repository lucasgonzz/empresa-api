<?php

namespace Tests\Feature\ForzarTotal;

use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MovimientoCaja;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 3 — la plata: el total forzado es el que le queda debiendo el cliente y el que entra a
 * la caja. Ninguno de los dos puede ser el bruto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUE ESTO SE PRUEBA Y NO SE DA POR SENTADO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Los dos caminos leen `sales.total` —`CurrentAcountFromSaleHelper::crear_current_acount()` hace
 *  `$debe = $this->sale->total` y `SaleHelper::attachSelectedPaymentMethods()` engancha el medio
 *  de pago con `amount = (float) $sale->total`—, asi que "deberian" andar solos. Pero esa es
 *  exactamente la clase de conclusion que hay que medir: si algun dia alguien decide recalcular el
 *  total desde los renglones en cualquiera de los dos lugares (que es lo que hacen
 *  `getTotalSale()` y `BudgetHelper::getTotal()`, y lo que hacia el camino de AFIP), el cliente
 *  queda debiendo $4.012 por una venta de $4.000 y nadie se entera hasta que cierra la caja.
 *
 *  Son 12 pesos por venta. En un mostrador son todos los dias.
 *
 * @group sales
 * @group forzar_total
 */
class El_total_forzado_llega_a_la_cuenta_corriente_y_a_la_caja_Test extends ForzarTotalTestCase
{
    /**
     * Test 1 — cuenta corriente: el `debe` del movimiento es el total forzado.
     *
     * @group forzar_total
     * @test
     */
    public function la_cuenta_corriente_recibe_el_total_forzado()
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        $sale = $this->crear_venta_por_endpoint($this->payload_venta(self::FORZADO, self::BRUTO, [
            'client_id'                  => $cliente->id,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'forzar_total_monto'         => self::MONTO,
        ]));

        $movimiento = CurrentAcount::where('sale_id', $sale->id)->first();

        $this->assertNotNull($movimiento, 'La venta tiene que haber dejado su movimiento en la cuenta corriente.');

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $movimiento->debe,
            self::DELTA,
            'el cliente tiene que quedar debiendo los 4.000 que pago, no los 4.012 que daba la venta'
        );
    }

    /**
     * Test 2 — caja: el movimiento de ingreso es por el total forzado.
     *
     * Es una venta de mostrador (omitida de la cuenta corriente) cobrada con un medio de pago
     * atado a una caja, que es el camino por el que una venta mueve plata de verdad:
     * `SaleCajaHelper::crear_movimiento_caja()` toma el `amount` del pivote del medio de pago.
     *
     * @group forzar_total
     * @test
     */
    public function la_caja_recibe_el_total_forzado()
    {
        $metodo = CurrentAcountPaymentMethod::where('name', TestingFerreteriaSeeder::PAGO_EFECTIVO)->first();

        $this->assertNotNull($metodo, 'Falta el metodo de pago "'.TestingFerreteriaSeeder::PAGO_EFECTIVO.'" del fixture.');

        $caja = \App\Models\Caja::where('name', TestingFerreteriaSeeder::CAJA_EFECTIVO)->first();

        $this->assertNotNull($caja, 'Falta la caja "'.TestingFerreteriaSeeder::CAJA_EFECTIVO.'" del fixture.');

        $sale = $this->crear_venta_por_endpoint($this->payload_venta(self::FORZADO, self::BRUTO, [
            'client_id'                        => null,
            'omitir_en_cuenta_corriente'       => 1,
            'current_acount_payment_method_id' => $metodo->id,
            'caja_id'                          => $caja->id,
            'forzar_total_monto'               => self::MONTO,
        ]));

        $sale->load('current_acount_payment_methods');

        $this->assertCount(
            1,
            $sale->current_acount_payment_methods,
            'La venta tiene que haber quedado con su medio de pago enganchado.'
        );

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $sale->current_acount_payment_methods[0]->pivot->amount,
            self::DELTA,
            'el importe del medio de pago tiene que ser el total forzado'
        );

        $movimiento = MovimientoCaja::where('sale_id', $sale->id)->first();

        $this->assertNotNull($movimiento, 'La venta cobrada con caja tiene que haber dejado su movimiento.');

        $this->assertEqualsWithDelta(
            self::FORZADO,
            (float) $movimiento->ingreso,
            self::DELTA,
            'a la caja tienen que entrar los 4.000 que se cobraron, no los 4.012 de la venta sin forzar'
        );
    }
}
