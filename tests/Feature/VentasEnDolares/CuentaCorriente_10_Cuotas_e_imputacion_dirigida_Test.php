<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\PaymentPlanCuota;
use Illuminate\Support\Facades\DB;

/**
 * CUOTAS de un plan de pago e IMPUTACIÓN DIRIGIDA (`to_pay`) con las dos monedas. Comercio 2R.
 *
 * Reglas:
 *  - Un plan de pago sobre una venta en dólares tiene cuotas en dólares. Pagar una cuota (aunque sea
 *    con pesos, pago cruzado) acredita la cuota por el `haber` del pago, que está en la moneda de la
 *    cuenta (dólares), y se imputa al débito de esa venta (`CurrentAcountCuotaHelper`).
 *  - Un `to_pay` explícito dirige la imputación al débito elegido; el resto sigue FIFO en la MISMA
 *    cuenta.
 *  - Un `to_pay` no puede imputar plata de una cuenta a un débito de otra moneda: pesos no saldan
 *    dólares sin cotizar.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_10_Cuotas_e_imputacion_dirigida_Test extends CuentaCorriente_Base
{
    /**
     * Regla: pagar una cuota de una venta en DÓLARES con PESOS (pago cruzado, 60000 ARS a 1200 = 50
     * USD) sobre la cuenta en dólares acredita la cuota por 50 (dólares) y la marca pagada; el pago se
     * imputa dirigido al débito de la venta (que queda `pagandose` por 50) y no al más viejo.
     *
     * @test
     */
    public function pagar_la_cuota_de_una_venta_en_dolares_con_pesos_acredita_la_cuota_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);

        // Una deuda vieja en dólares y la venta del plan, más nueva.
        $vieja = $this->vender($cliente, self::DOLARES, 20);
        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->avanzar_reloj_de_ventas();

        $this->postJson('api/payment-plan', [
            'sale_id'         => $venta->id,
            'cantidad_cuotas' => 2,
            'frequency'       => 'monthly',
            'start_date'      => date('Y-m-d'),
        ])->assertStatus(201);

        $cuota = PaymentPlanCuota::where('sale_id', $venta->id)->orderBy('numero_cuota')->first();

        $this->assertNotNull($cuota, 'El plan no dejó cuotas.');
        $this->assertMonto(50, $cuota->amount, 'Las cuotas de una venta en dólares tienen que estar en dólares.');

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 60000, $this->caja_pesos, 1200),
        ], ['payment_plan_cuota' => ['id' => $cuota->id]]);

        $this->assertMonto(50, $pago->haber);

        $cuota = $cuota->fresh();
        $this->assertMonto(50, $cuota->amount_paid, 'La cuota se acreditó por el monto en pesos en vez de por el haber en dólares.');
        $this->assertSame('pagado', $cuota->estado);

        // Imputación dirigida al débito de la venta del plan; la deuda vieja queda intacta.
        $this->assertEquals($this->debito_de($venta, $dolares)->id, (int) $pago->to_pay_id);
        $this->assertEquals('pagandose', $this->debito_de($venta, $dolares)->status);
        $this->assertMonto(50, $this->debito_de($venta, $dolares)->pagandose);
        $this->assertEquals('sin_pagar', $this->debito_de($vieja, $dolares)->status);

        $this->assertMonto(70, $this->saldo_de($dolares));
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: un `to_pay` explícito sobre un débito en dólares dirige el pago en dólares a ese débito
     * y no al más viejo; el sobrante sigue FIFO en la misma cuenta y las deudas en pesos no se tocan.
     *
     * @test
     */
    public function un_to_pay_en_dolares_dirige_el_pago_y_el_sobrante_sigue_fifo_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $v_ars = $this->vender($cliente, self::PESOS, 60000);
        $v_vieja = $this->vender($cliente, self::DOLARES, 50);
        $v_elegida = $this->vender($cliente, self::DOLARES, 40);

        $debito_elegido = $this->debito_de($v_elegida, $dolares);

        // 60 USD dirigidos a la venta de 40: 40 la saldan y 20 caen en la más vieja.
        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 60, $this->caja_dolares),
        ], ['to_pay' => ['id' => $debito_elegido->id]]);

        $this->assertEquals($debito_elegido->id, (int) $pago->to_pay_id);
        $this->assertEquals('pagado', $this->debito_de($v_elegida, $dolares)->status);
        $this->assertEquals('pagandose', $this->debito_de($v_vieja, $dolares)->status);
        $this->assertMonto(20, $this->debito_de($v_vieja, $dolares)->pagandose);
        $this->assertEquals('sin_pagar', $this->debito_de($v_ars, $pesos)->status);
        $this->assertMonto(30, $this->saldo_de($dolares));
        $this->assertMonto(60000, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($dolares);
        $this->assertCuentaConsistente($pesos);
    }

    /**
     * Regla: un `to_pay` que apunta a un débito de OTRA cuenta (de otra moneda) no puede imputar el
     * pago a ese débito: `CurrentAcountPagoHelper::setSinPagar()` resuelve el débito dirigido con un
     * `CurrentAcount::find()` sin mirar la cuenta cuando no está entre los pendientes de la cuenta
     * pagada, así que 50000 pesos terminan "pagando" un débito en dólares. O se rechaza el pago, o se
     * ignora el `to_pay` y entra FIFO en pesos; lo que no puede pasar es que el débito en dólares
     * cambie de estado por un pago en pesos.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function un_to_pay_de_una_cuenta_en_otra_moneda_no_imputa_el_pago()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 120000);
        $venta_usd = $this->vender($cliente, self::DOLARES, 100);
        $debito_usd = $this->debito_de($venta_usd, $dolares);

        $response = $this->postear_pago($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::PESOS, 50000, $this->caja_pesos),
        ], ['to_pay' => ['id' => $debito_usd->id]]);

        $debito_usd = $debito_usd->fresh();

        $this->assertEquals(
            'sin_pagar',
            $debito_usd->status,
            'Un pago de 50000 PESOS (status '.$response->getStatusCode().') dejó el débito en DÓLARES en "'.$debito_usd->status.'" con pagandose = '.$debito_usd->pagandose
            .' mientras el saldo de la cuenta en dólares sigue en '.$this->saldo_de($dolares).'.'
        );
        $this->assertMonto(0, $debito_usd->pagandose);
        $this->assertEquals(
            0,
            DB::table('pagado_por')->where('debe_id', $debito_usd->id)->count(),
            'Se creó una imputación de un pago en pesos contra un débito en dólares.'
        );
    }
}
