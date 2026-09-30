<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de cuenta corriente en LA MISMA MONEDA que la deuda (pesos sobre cuenta en pesos, dólares
 * sobre cuenta en dólares) en el comercio 2R.
 *
 * Regla común: el pago baja el saldo de SU cuenta, se imputa (FIFO) a los débitos de esa cuenta
 * (`status` pagado / pagandose), la caja de la moneda recibe el monto y NADA de la otra moneda se
 * mueve. Un pago mayor a la deuda deja saldo a favor (saldo negativo) y ese saldo a favor cancela
 * la próxima venta de la misma moneda.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_2_Pago_misma_moneda_Test extends CuentaCorriente_Base
{
    /**
     * Proveedor de datos: la moneda y la deuda (en esa moneda) con la que se corren los mismos
     * escenarios en pesos y en dólares.
     *
     * @return array
     */
    public function monedas()
    {
        return [
            'pesos'   => [self::PESOS, 120000],
            'dolares' => [self::DOLARES, 100],
        ];
    }

    /**
     * Las cuentas y la caja de la moneda pedida y de la otra.
     *
     * @param \App\Models\Client $cliente
     * @param int $moneda
     * @return array [cuenta_propia, cuenta_ajena, caja_propia, caja_ajena]
     */
    protected function lado($cliente, $moneda)
    {
        $otra = $moneda == self::PESOS ? self::DOLARES : self::PESOS;

        return [
            $this->cuenta($cliente, $moneda),
            $this->cuenta($cliente, $otra),
            $moneda == self::PESOS ? $this->caja_pesos : $this->caja_dolares,
            $moneda == self::PESOS ? $this->caja_dolares : $this->caja_pesos,
        ];
    }

    /**
     * Regla: pago TOTAL en la misma moneda. El `haber` es el monto; el saldo de la cuenta queda en 0;
     * el débito de la venta pasa a `pagado` con `pagandose` = deuda; hay una imputación en
     * `pagado_por`; el pago guarda su fila de medio de pago con moneda y caja; la caja de esa moneda
     * recibe el monto y la otra caja no se mueve; la cuenta de la otra moneda no se toca.
     *
     * @test
     * @dataProvider monedas
     */
    public function un_pago_total_en_la_misma_moneda_salda_la_deuda_y_entra_a_la_caja_de_esa_moneda($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja, $caja_ajena) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);
        $debito = $this->debito_de($venta, $propia);

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $deuda, $caja),
        ]);

        // El pago.
        $this->assertEquals('pago_from_client', $pago->status);
        $this->assertMonto($deuda, $pago->haber);
        $this->assertMonto(0, $pago->saldo, 'El saldo acumulado del pago tendría que quedar en cero.');
        $this->assertEquals($propia->id, (int) $pago->credit_account_id);
        $this->assertEquals('Pago N°'.$pago->num_receipt, $pago->detalle);

        // La cuenta y el cliente.
        $this->assertMonto(0, $this->saldo_de($propia));
        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(0, $moneda == self::PESOS ? $saldos['pesos'] : $saldos['dolares']);

        // La imputación.
        $debito = $debito->fresh();
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto($deuda, $debito->pagandose);

        $imputaciones = DB::table('pagado_por')->where('haber_id', $pago->id)->get();
        $this->assertCount(1, $imputaciones);
        $this->assertEquals($debito->id, (int) $imputaciones[0]->debe_id);
        $this->assertMonto($deuda, $imputaciones[0]->pagado);
        $this->assertMonto($deuda, $imputaciones[0]->total_pago);

        // El desglose del medio de pago: en la moneda de la fila, sin cotizar.
        $pago->load('current_acount_payment_methods');
        $this->assertCount(1, $pago->current_acount_payment_methods);
        $pivot = $pago->current_acount_payment_methods[0]->pivot;
        $this->assertMonto($deuda, $pivot->amount);
        $this->assertEquals($moneda, (int) $pivot->moneda_id);
        $this->assertEquals($caja->id, (int) $pivot->caja_id);

        // La caja de la moneda recibe el monto; la otra ni se entera.
        $movimientos = $this->movimientos_de_caja($caja, $desde);
        $this->assertCount(1, $movimientos);
        $this->assertMonto($deuda, $movimientos[0]->ingreso);
        $this->assertNull($movimientos[0]->egreso);
        $this->assertMonto($deuda, $this->saldo_de_caja_bd($caja));
        $this->assertCount(0, $this->movimientos_de_caja($caja_ajena, $desde));
        $this->assertMonto(0, $this->saldo_de_caja_bd($caja_ajena));

        // La otra moneda: ni saldo ni movimientos.
        $this->assertMonto(0, $this->saldo_de($ajena));
        $this->assertCount(0, $this->movimientos($ajena));

        $this->assertCuentaConsistente($propia);
        $this->assertCuentaConsistente($ajena);
    }

    /**
     * Regla: pago PARCIAL en la misma moneda. El saldo baja solo lo pagado; el débito queda
     * `pagandose` con `pagandose` = lo pagado; la caja recibe lo pagado.
     *
     * @test
     * @dataProvider monedas
     */
    public function un_pago_parcial_en_la_misma_moneda_deja_el_debito_pagandose($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);
        $parcial = $deuda * 0.4;

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $parcial, $caja),
        ]);

        $this->assertMonto($deuda - $parcial, $pago->saldo);
        $this->assertMonto($deuda - $parcial, $this->saldo_de($propia));

        $debito = $this->debito_de($venta, $propia);
        $this->assertEquals('pagandose', $debito->status);
        $this->assertMonto($parcial, $debito->pagandose);

        $this->assertMonto($parcial, $this->movimientos_de_caja($caja, $desde)[0]->ingreso);

        $this->assertMonto(0, $this->saldo_de($ajena));
        $this->assertCuentaConsistente($propia);
    }

    /**
     * Regla: pago MAYOR a la deuda. El excedente queda como saldo a favor (saldo negativo); el débito
     * queda `pagado` por su deuda (no por el total del pago); la caja recibe el monto completo.
     *
     * @test
     * @dataProvider monedas
     */
    public function un_pago_mayor_a_la_deuda_deja_saldo_a_favor($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);
        $pagado = $deuda * 1.25;

        $desde = $this->max_id_movimiento_caja();

        $pago = $this->pagar($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $pagado, $caja),
        ]);

        $a_favor = $pagado - $deuda;

        $this->assertMonto(-$a_favor, $pago->saldo);
        $this->assertMonto(-$a_favor, $this->saldo_de($propia), 'El excedente tiene que quedar como saldo a favor del cliente.');

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(-$a_favor, $moneda == self::PESOS ? $saldos['pesos'] : $saldos['dolares']);

        $debito = $this->debito_de($venta, $propia);
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto($deuda, $debito->pagandose, 'El débito se paga por su deuda, no por el total del pago.');

        $this->assertMonto($pagado, $this->movimientos_de_caja($caja, $desde)[0]->ingreso);

        $this->assertMonto(0, $this->saldo_de($ajena));
        $this->assertCuentaConsistente($propia);
    }

    /**
     * Regla: el saldo a favor generado por un pago mayor cancela la PRÓXIMA venta de la misma moneda:
     * la venta entra ya `pagado` (o `pagandose` si el saldo a favor no alcanza) y el saldo queda en
     * la diferencia. (`CurrentAcountFromSaleHelper::update_client_saldo()` re-imputa cuando el saldo
     * previo es negativo.)
     *
     * @test
     * @dataProvider monedas
     */
    public function el_saldo_a_favor_cancela_la_proxima_venta_de_la_misma_moneda($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        // Pago sin deuda previa: todo queda a favor.
        $this->pagar($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $deuda * 1.5, $caja),
        ]);
        $this->assertMonto(-$deuda * 1.5, $this->saldo_de($propia));

        $venta = $this->vender($cliente, $moneda, $deuda);

        $debito = $this->debito_de($venta, $propia);
        $this->assertEquals('pagado', $debito->status, 'La venta no quedó saldada por el saldo a favor.');
        $this->assertMonto($deuda, $debito->pagandose);
        $this->assertMonto(-$deuda * 0.5, $this->saldo_de($propia));

        $this->assertMonto(0, $this->saldo_de($ajena));
        $this->assertCuentaConsistente($propia);
    }

    /**
     * Regla: el saldo a favor en pesos NO cancela una venta en dólares (ni al revés). Son cuentas
     * distintas: cada una se salda solo con pagos de su propia cuenta.
     *
     * @test
     */
    public function el_saldo_a_favor_en_pesos_no_cancela_una_venta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::PESOS, 500000, $this->caja_pesos),
        ]);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $debito = $this->debito_de($venta, $dolares);
        $this->assertEquals('sin_pagar', $debito->status, 'El saldo a favor en pesos saldó una deuda en dólares.');
        $this->assertMonto(0, $debito->pagandose);

        $this->assertMonto(100, $this->saldo_de($dolares));
        $this->assertMonto(-500000, $this->saldo_de($pesos));
    }

    /**
     * Regla: con deuda en las DOS monedas, un pago en pesos imputa solo a los débitos de la cuenta en
     * pesos, y uno en dólares solo a los de la cuenta en dólares. FIFO dentro de cada cuenta.
     *
     * @test
     */
    public function un_pago_solo_imputa_a_los_debitos_de_su_propia_cuenta()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $p1 = $this->vender($cliente, self::PESOS, 100000);
        $d1 = $this->vender($cliente, self::DOLARES, 100);
        $p2 = $this->vender($cliente, self::PESOS, 50000);
        $d2 = $this->vender($cliente, self::DOLARES, 30);

        // 120000 en pesos: salda la primera venta en pesos y 20000 de la segunda. Los dólares, ni se enteran.
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::PESOS, 120000, $this->caja_pesos),
        ]);

        $this->assertEquals('pagado', $this->debito_de($p1, $pesos)->status);
        $this->assertEquals('pagandose', $this->debito_de($p2, $pesos)->status);
        $this->assertMonto(20000, $this->debito_de($p2, $pesos)->pagandose);
        $this->assertEquals('sin_pagar', $this->debito_de($d1, $dolares)->status);
        $this->assertEquals('sin_pagar', $this->debito_de($d2, $dolares)->status);
        $this->assertMonto(30000, $this->saldo_de($pesos));
        $this->assertMonto(130, $this->saldo_de($dolares));

        // 110 en dólares: salda la primera y 10 de la segunda. Los pesos, intactos.
        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 110, $this->caja_dolares),
        ]);

        $this->assertEquals('pagado', $this->debito_de($d1, $dolares)->status);
        $this->assertEquals('pagandose', $this->debito_de($d2, $dolares)->status);
        $this->assertMonto(10, $this->debito_de($d2, $dolares)->pagandose);
        $this->assertEquals('pagandose', $this->debito_de($p2, $pesos)->status);
        $this->assertMonto(20000, $this->debito_de($p2, $pesos)->pagandose);
        $this->assertMonto(30000, $this->saldo_de($pesos));
        $this->assertMonto(20, $this->saldo_de($dolares));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: un pago con FECHA PASADA (`current_date = false` + `created_at`) toma la otra rama de
     * `CurrentAcountPagoAltaHelper::registrar()` (recalcula saldos e imputa la cuenta entera). Tiene
     * que dar el mismo resultado económico que el pago de hoy, también en dólares.
     *
     * @test
     * @dataProvider monedas
     */
    public function un_pago_con_fecha_pasada_recalcula_la_cuenta_y_salda_igual($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);

        $pago = $this->pagar($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $deuda, $caja),
        ], [
            'current_date' => false,
            'created_at'   => date('Y-m-d', strtotime('+1 day')),
        ]);

        $this->assertMonto(0, $this->saldo_de($propia));
        $this->assertEquals('pagado', $this->debito_de($venta, $propia)->status);
        $this->assertMonto(0, $this->saldo_de($ajena));
        $this->assertCuentaConsistente($propia);
    }

    /**
     * Regla: si el payload NO trae `haber` (una integración, un script), el pago igual baja el saldo:
     * `CurrentAcountPagoAltaHelper::registrar()` guarda como haber la suma de las filas
     * (`get_haber()`) pero resta `$pedido->haber` del saldo del pago; el recálculo de la cadena que
     * corre al final (`checkSaldos`) corrige el saldo con el haber guardado. Este test documenta que
     * el saldo de la CUENTA queda bien aunque falte `haber` (el `saldo` de la respuesta puede quedar
     * viejo, ver el comentario "Rareza heredada" del helper).
     *
     * @test
     * @dataProvider monedas
     */
    public function un_pago_sin_haber_en_el_payload_baja_igual_el_saldo_de_la_cuenta($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);

        $payload = $this->payload_de_pago($cliente, $propia, [
            $this->fila_de_pago($moneda, $moneda, $deuda / 2, $caja),
        ]);
        unset($payload['haber']);

        $this->avanzar_reloj_de_ventas();
        $response = $this->postJson('api/current-acount/pago', $payload);
        $response->assertStatus(201);
        $this->cobros_cc_creados_por_escenarios[] = $response->json('current_acount.id');

        $pago = CurrentAcount::find($response->json('current_acount.id'));

        $this->assertMonto($deuda / 2, $pago->haber, 'El haber guardado tiene que ser la suma de las filas.');
        $this->assertMonto($deuda / 2, $this->saldo_de($propia), 'La cuenta no bajó el saldo por el pago.');
        $this->assertMonto($deuda / 2, $pago->fresh()->saldo, 'El saldo del pago quedó sin descontar su haber.');
        $this->assertEquals('pagandose', $this->debito_de($venta, $propia)->status);
        $this->assertCuentaConsistente($propia);
    }

    /**
     * Regla: varios pagos sucesivos en la misma moneda acumulan bien el saldo (cada pago parte del
     * saldo del anterior) y el último los deja en cero. Tres pagos en dólares y tres en pesos.
     *
     * @test
     * @dataProvider monedas
     */
    public function varios_pagos_sucesivos_acumulan_el_saldo_hasta_cero($moneda, $deuda)
    {
        $cliente = $this->cliente_nuevo();
        list($propia, $ajena, $caja) = $this->lado($cliente, $moneda);

        $venta = $this->vender($cliente, $moneda, $deuda);

        $p1 = $this->pagar($cliente, $propia, [$this->fila_de_pago($moneda, $moneda, $deuda * 0.25, $caja)]);
        $this->assertMonto($deuda * 0.75, $p1->saldo);

        $p2 = $this->pagar($cliente, $propia, [$this->fila_de_pago($moneda, $moneda, $deuda * 0.25, $caja)]);
        $this->assertMonto($deuda * 0.5, $p2->saldo);

        $p3 = $this->pagar($cliente, $propia, [$this->fila_de_pago($moneda, $moneda, $deuda * 0.5, $caja)]);
        $this->assertMonto(0, $p3->saldo);

        $this->assertEquals('pagado', $this->debito_de($venta, $propia)->status);
        $this->assertMonto($deuda, $this->saldo_de_caja_bd($caja), 'La caja tiene que haber recibido los tres pagos, en su moneda.');
        $this->assertCount(3, DB::table('pagado_por')->where('debe_id', $this->debito_de($venta, $propia)->id)->get());
        $this->assertCuentaConsistente($propia);
    }
}
