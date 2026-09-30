<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\MovimientoCaja;
use Illuminate\Support\Facades\DB;

/**
 * Pagos CRUZADOS de cuenta corriente: el cliente entrega plata en una moneda distinta a la de la
 * deuda (dólares para una deuda en pesos, pesos para una deuda en dólares), o reparte el pago entre
 * las dos monedas. Comercio 2R, dólar a 1200.
 *
 * Reglas (derivadas de `CurrentAcountPagoAltaHelper::get_haber()`, `PaymentMethodHelper::
 * attach_payment_methods()` y `CurrentAcountPagoHelper::attachPaymentMethods()`):
 *
 *  - El `haber` del pago (y por lo tanto lo que baja el saldo de la cuenta) está en LA MONEDA DE LA
 *    CUENTA: es el `amount_cotizado` de cada fila cruzada, o el `amount` de la fila en la moneda de
 *    la cuenta.
 *  - La fila de medio de pago guarda lo que el cliente entregó (`amount`, en SU moneda) más
 *    `cotizacion`, `amount_cotizado` y `moneda_id`.
 *  - La caja destino recibe el `amount` de la fila en SU moneda, NO el cotizado.
 *  - La cuenta de la otra moneda y el saldo del cliente en la otra moneda no se tocan.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_3_Pago_cruzado_Test extends CuentaCorriente_Base
{
    /**
     * Regla (4a): pagar en DÓLARES una deuda en PESOS. 100 USD a 1200 = 120000 ARS. El haber está en
     * pesos (120000), la deuda en pesos queda saldada, `saldo_pesos` en 0, la caja en dólares recibe
     * 100 (no 120000), la caja en pesos no se mueve y la cuenta en dólares no se toca.
     *
     * @test
     */
    public function pagar_en_dolares_una_deuda_en_pesos_baja_la_cuenta_en_pesos_y_entra_a_la_caja_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 120000);
        $debito = $this->debito_de($venta, $pesos);

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        // El haber en la moneda de la cuenta (pesos).
        $this->assertMonto(120000, $pago->haber, 'El haber tiene que estar en pesos (100 USD x 1200).');
        $this->assertMonto(0, $pago->saldo);
        $this->assertEquals($pesos->id, (int) $pago->credit_account_id);

        // El saldo de la cuenta en pesos y el del cliente.
        $this->assertMonto(0, $this->saldo_de($pesos));
        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(0, $saldos['pesos']);
        $this->assertMonto(0, $saldos['dolares'], 'El pago en dólares no tiene que dejar saldo en la cuenta en dólares.');

        // La imputación, en pesos.
        $debito = $debito->fresh();
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto(120000, $debito->pagandose);
        $imputacion = DB::table('pagado_por')->where('haber_id', $pago->id)->first();
        $this->assertMonto(120000, $imputacion->pagado);

        // El desglose del medio de pago: lo que entregó el cliente, en SU moneda, más la cotización.
        $pago->load('current_acount_payment_methods');
        $this->assertCount(1, $pago->current_acount_payment_methods);
        $pivot = $pago->current_acount_payment_methods[0]->pivot;
        $this->assertMonto(100, $pivot->amount, 'La fila guarda lo entregado (100 USD), no el cotizado.');
        $this->assertEquals(self::DOLARES, (int) $pivot->moneda_id);
        $this->assertMonto(1200, $pivot->cotizacion);
        $this->assertMonto(120000, $pivot->amount_cotizado);
        $this->assertEquals($this->caja_dolares->id, (int) $pivot->caja_id);

        // La caja destino recibe el monto en SU moneda (100), la otra caja no se mueve.
        $movimientos = $this->movimientos_de_caja($this->caja_dolares, $desde);
        $this->assertCount(1, $movimientos);
        $this->assertMonto(100, $movimientos[0]->ingreso, 'La caja en dólares recibió el monto cotizado en vez de lo entregado.');
        $this->assertMonto(100, $this->saldo_de_caja_bd($this->caja_dolares));
        $this->assertCount(0, $this->movimientos_de_caja($this->caja_pesos, $desde));
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_pesos));

        // La cuenta en dólares no se tocó.
        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertCount(0, $this->movimientos($dolares));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla (4b): pagar en PESOS una deuda en DÓLARES. 120000 ARS a 1200 = 100 USD. El haber está en
     * dólares (100), la deuda en dólares queda saldada, `saldo_dolares` en 0, la caja en pesos
     * recibe 120000 (no 100), la caja en dólares no se mueve y la cuenta en pesos no se toca.
     *
     * @test
     */
    public function pagar_en_pesos_una_deuda_en_dolares_baja_la_cuenta_en_dolares_y_entra_a_la_caja_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);
        $debito = $this->debito_de($venta, $dolares);

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(100, $pago->haber, 'El haber tiene que estar en dólares (120000 ARS / 1200).');
        $this->assertMonto(0, $pago->saldo);
        $this->assertEquals($dolares->id, (int) $pago->credit_account_id);

        $this->assertMonto(0, $this->saldo_de($dolares));
        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(0, $saldos['dolares']);
        $this->assertMonto(0, $saldos['pesos'], 'El pago en pesos no tiene que dejar saldo en la cuenta en pesos.');

        $debito = $debito->fresh();
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto(100, $debito->pagandose);

        $pago->load('current_acount_payment_methods');
        $pivot = $pago->current_acount_payment_methods[0]->pivot;
        $this->assertMonto(120000, $pivot->amount, 'La fila guarda lo entregado (120000 ARS), no el cotizado.');
        $this->assertEquals(self::PESOS, (int) $pivot->moneda_id);
        $this->assertMonto(1200, $pivot->cotizacion);
        $this->assertMonto(100, $pivot->amount_cotizado);
        $this->assertEquals($this->caja_pesos->id, (int) $pivot->caja_id);

        $movimientos = $this->movimientos_de_caja($this->caja_pesos, $desde);
        $this->assertCount(1, $movimientos);
        $this->assertMonto(120000, $movimientos[0]->ingreso, 'La caja en pesos recibió el monto cotizado en vez de lo entregado.');
        $this->assertMonto(120000, $this->saldo_de_caja_bd($this->caja_pesos));
        $this->assertCount(0, $this->movimientos_de_caja($this->caja_dolares, $desde));
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_dolares));

        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCount(0, $this->movimientos($pesos));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: pago cruzado PARCIAL. 50 USD (= 60000 ARS) sobre una deuda de 120000 ARS deja 60000 y el
     * débito `pagandose`; 60000 ARS (= 50 USD) sobre 100 USD deja 50 y el débito `pagandose`.
     *
     * @test
     */
    public function un_pago_cruzado_parcial_baja_el_saldo_en_la_moneda_de_la_cuenta()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta_pesos = $this->vender($cliente, self::PESOS, 120000);
        $venta_dolares = $this->vender($cliente, self::DOLARES, 100);

        // 50 USD pagan la mitad de la deuda en pesos.
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 50, $this->caja_dolares, 1200),
        ]);

        $this->assertMonto(60000, $this->saldo_de($pesos));
        $this->assertEquals('pagandose', $this->debito_de($venta_pesos, $pesos)->status);
        $this->assertMonto(60000, $this->debito_de($venta_pesos, $pesos)->pagandose);
        $this->assertMonto(100, $this->saldo_de($dolares), 'El pago en dólares sobre la deuda en pesos movió la cuenta en dólares.');
        $this->assertEquals('sin_pagar', $this->debito_de($venta_dolares, $dolares)->status);

        // 60000 ARS pagan la mitad de la deuda en dólares.
        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 60000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(50, $this->saldo_de($dolares));
        $this->assertEquals('pagandose', $this->debito_de($venta_dolares, $dolares)->status);
        $this->assertMonto(50, $this->debito_de($venta_dolares, $dolares)->pagandose);
        $this->assertMonto(60000, $this->saldo_de($pesos), 'El pago en pesos sobre la deuda en dólares movió la cuenta en pesos.');

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(60000, $saldos['pesos']);
        $this->assertMonto(50, $saldos['dolares']);

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: un pago cruzado MAYOR a la deuda deja el saldo a favor en la moneda de la cuenta
     * pagada: 150 USD (= 180000 ARS) sobre 120000 ARS deja -60000 pesos; 240000 ARS (= 200 USD) sobre
     * 100 USD deja -100 dólares.
     *
     * @test
     */
    public function un_pago_cruzado_mayor_a_la_deuda_deja_el_saldo_a_favor_en_la_moneda_de_la_cuenta()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 150, $this->caja_dolares, 1200),
        ]);
        $this->assertMonto(-60000, $this->saldo_de($pesos));

        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 240000, $this->caja_pesos, 1200),
        ]);
        $this->assertMonto(-100, $this->saldo_de($dolares));

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(-60000, $saldos['pesos']);
        $this->assertMonto(-100, $saldos['dolares']);

        $this->assertMonto(150, $this->saldo_de_caja_bd($this->caja_dolares));
        $this->assertMonto(240000, $this->saldo_de_caja_bd($this->caja_pesos));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla (4c): un pago REPARTIDO en las dos monedas sobre una sola cuenta (mezcla USD + ARS).
     * Deuda en pesos 300000: 100 USD (= 120000 ARS, caja en dólares) + 180000 ARS (caja en pesos).
     * El haber es 300000; el débito queda pagado; cada caja recibe SU monto en SU moneda; el pago
     * guarda las dos filas de medio de pago, cada una con su moneda y su caja.
     *
     * @test
     */
    public function un_pago_repartido_en_dolares_y_pesos_sobre_una_cuenta_en_pesos_impacta_cada_caja_en_su_moneda()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 300000);

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
            $this->fila_de_pago(self::PESOS, self::PESOS, 180000, $this->caja_pesos),
        ]);

        $this->assertMonto(300000, $pago->haber);
        $this->assertMonto(0, $pago->saldo);
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertEquals('pagado', $this->debito_de($venta, $pesos)->status);

        $pago->load('current_acount_payment_methods');
        $this->assertCount(2, $pago->current_acount_payment_methods, 'El pago no guardó las dos filas de medios de pago.');

        $por_moneda = [];
        foreach ($pago->current_acount_payment_methods as $metodo) {
            $por_moneda[(int) $metodo->pivot->moneda_id] = $metodo->pivot;
        }

        $this->assertMonto(100, $por_moneda[self::DOLARES]->amount);
        $this->assertMonto(120000, $por_moneda[self::DOLARES]->amount_cotizado);
        $this->assertEquals($this->caja_dolares->id, (int) $por_moneda[self::DOLARES]->caja_id);
        $this->assertMonto(180000, $por_moneda[self::PESOS]->amount);
        $this->assertEquals($this->caja_pesos->id, (int) $por_moneda[self::PESOS]->caja_id);

        $mov_dolares = $this->movimientos_de_caja($this->caja_dolares, $desde);
        $mov_pesos = $this->movimientos_de_caja($this->caja_pesos, $desde);
        $this->assertCount(1, $mov_dolares);
        $this->assertCount(1, $mov_pesos);
        $this->assertMonto(100, $mov_dolares[0]->ingreso);
        $this->assertMonto(180000, $mov_pesos[0]->ingreso);
        $this->assertMonto(100, $this->saldo_de_caja_bd($this->caja_dolares));
        $this->assertMonto(180000, $this->saldo_de_caja_bd($this->caja_pesos));

        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertCuentaConsistente($pesos);
    }

    /**
     * Regla (4c, espejo): un pago repartido sobre una cuenta en DÓLARES. Deuda de 300 USD: 100 USD
     * (caja en dólares) + 240000 ARS (= 200 USD, caja en pesos). Haber 300; débito pagado.
     *
     * @test
     */
    public function un_pago_repartido_en_dolares_y_pesos_sobre_una_cuenta_en_dolares_impacta_cada_caja_en_su_moneda()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 300);

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 100, $this->caja_dolares),
            $this->fila_de_pago(self::DOLARES, self::PESOS, 240000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(300, $pago->haber);
        $this->assertMonto(0, $pago->saldo);
        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertEquals('pagado', $this->debito_de($venta, $dolares)->status);

        $mov_dolares = $this->movimientos_de_caja($this->caja_dolares, $desde);
        $mov_pesos = $this->movimientos_de_caja($this->caja_pesos, $desde);
        $this->assertCount(1, $mov_dolares);
        $this->assertCount(1, $mov_pesos);
        $this->assertMonto(100, $mov_dolares[0]->ingreso);
        $this->assertMonto(240000, $mov_pesos[0]->ingreso);

        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: la cotización del PAGO es la que manda, no la de la venta. Una venta en dólares
     * hecha con el dólar a 1200 cobrada en pesos con el dólar a 1300: 130000 ARS a 1300 = 100 USD,
     * que saldan los 100 USD de la venta. (El sistema no reexpresa la deuda: la cuenta en dólares
     * se cancela por los dólares equivalentes de la fecha del cobro.)
     *
     * @test
     */
    public function la_cotizacion_del_pago_manda_sobre_la_de_la_venta()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);
        $this->assertMonto(1200, $venta->valor_dolar);

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 130000, $this->caja_pesos, 1300),
        ]);

        $this->assertMonto(100, $pago->haber);
        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertEquals('pagado', $this->debito_de($venta, $dolares)->status);

        $pago->load('current_acount_payment_methods');
        $this->assertMonto(1300, $pago->current_acount_payment_methods[0]->pivot->cotizacion);
        $this->assertMonto(130000, $pago->current_acount_payment_methods[0]->pivot->amount);
    }

    /**
     * Regla: una cotización que NO cierra en centavos (50000 ARS a 1200 = 41,6667 USD) deja el
     * `amount_cotizado` y el `haber` redondeados a 2 decimales, y la cadena de saldos queda
     * consistente (el saldo del pago y el de la cuenta salen del `haber` guardado, no del float
     * del payload).
     *
     * @test
     */
    public function una_cotizacion_que_no_cierra_en_centavos_deja_la_cuenta_consistente()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::DOLARES, 100);

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 50000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(41.67, $pago->haber);
        $this->assertMonto(100 - 41.67, $this->saldo_de($dolares));
        $this->assertMonto(100 - 41.67, $pago->fresh()->saldo);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: un pago cruzado con FECHA PASADA (rama `check_saldos_y_pagos`) da el mismo resultado
     * que el de hoy: haber en la moneda de la cuenta, cadena consistente, cuenta de la otra moneda
     * intacta.
     *
     * @test
     */
    public function un_pago_cruzado_con_fecha_pasada_da_el_mismo_resultado()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 120000);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ], [
            'current_date' => false,
            'created_at'   => date('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertMonto(120000, $pago->haber);
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertEquals('pagado', $this->debito_de($venta, $pesos)->status);
        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: la caja destino tiene que ser de la MISMA moneda que la fila. Si la SPA (o una
     * integración) manda una fila en DÓLARES apuntando a la caja en PESOS, el sistema no puede
     * acreditar "100" en una caja de pesos: o rechaza el pago o no deja el movimiento en la caja
     * equivocada. Ninguna caja puede recibir un ingreso en una moneda que no es la suya.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_fila_en_dolares_no_puede_impactar_en_una_caja_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::PESOS, 120000);

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $pesos, [
            // 100 dólares, pero con la caja EN PESOS elegida.
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_pesos, 1200),
        ]);

        if ($response->getStatusCode() >= 400) {
            $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count(), 'El pago se rechazó pero dejó movimientos de caja.');
            return;
        }

        $movimiento_en_pesos = $this->movimientos_de_caja($this->caja_pesos, $desde);

        $this->assertCount(
            0,
            $movimiento_en_pesos,
            'Una fila de 100 USD dejó un ingreso de '.($movimiento_en_pesos->count() ? $movimiento_en_pesos[0]->ingreso : '?').' en la caja en PESOS (moneda de la caja: '.Caja::find($this->caja_pesos->id)->moneda_id.').'
        );
    }

    /**
     * Regla: lo mismo a la inversa. Una fila en PESOS apuntando a la caja en DÓLARES no puede dejar
     * 120000 "dólares" en esa caja.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_fila_en_pesos_no_puede_impactar_en_una_caja_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::DOLARES, 100);

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_dolares, 1200),
        ]);

        if ($response->getStatusCode() >= 400) {
            $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count(), 'El pago se rechazó pero dejó movimientos de caja.');
            return;
        }

        $movimiento_en_dolares = $this->movimientos_de_caja($this->caja_dolares, $desde);

        $this->assertCount(
            0,
            $movimiento_en_dolares,
            'Una fila de 120000 ARS dejó un ingreso de '.($movimiento_en_dolares->count() ? $movimiento_en_dolares[0]->ingreso : '?').' en la caja en DÓLARES (moneda de la caja: '.Caja::find($this->caja_dolares->id)->moneda_id.').'
        );
    }

    /**
     * Regla: el pago cruzado no crea ningún movimiento en la cuenta de la otra moneda (ni un
     * "espejo" convertido): un pago = un movimiento de cuenta corriente, en la cuenta que se pagó.
     *
     * @test
     */
    public function un_pago_cruzado_deja_un_solo_movimiento_de_cuenta_corriente()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::PESOS, 120000);

        $antes = CurrentAcount::where('client_id', $cliente->id)->count();

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        $this->assertEquals($antes + 1, CurrentAcount::where('client_id', $cliente->id)->count());
        $this->assertEquals(1, CurrentAcount::where('id', $pago->id)->count());
    }
}
