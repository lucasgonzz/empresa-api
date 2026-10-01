<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * ELIMINAR y EDITAR una venta a cuenta corriente en dólares (y en pesos), y RECALCULAR los saldos de
 * un cliente con movimientos en las dos monedas. Comercio 2R.
 *
 * Reglas:
 *  - Borrar una venta (`DELETE api/sale/{id}` → `DeleteSaleHelper`) saca su movimiento de SU cuenta
 *    (la de la moneda de la venta) y recalcula esa cuenta; la de la otra moneda no se toca.
 *  - Editar una venta (`PUT api/sale/{id}`) recrea su movimiento en la cuenta de la moneda de la
 *    venta con el total nuevo. `update()` nunca reasigna `moneda_id`.
 *  - El recálculo (`GET api/check-saldos/{credit_account_id}` → `CurrentAcountHelper::
 *    check_saldos_y_pagos()`) es idempotente sobre una cuenta consistente y repara una cadena
 *    corrupta: los saldos recalculados coinciden con los guardados
 *    (`CurrentAcountHelper::primer_corte_de_la_cadena()` / `descuadre_del_saldo_final()`).
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_6_Venta_eliminar_editar_y_recalculo_Test extends CuentaCorriente_Base
{
    /**
     * Payload mínimo de `PUT api/sale/{id}` (calcado de tests/Feature/LimiteCredito/1: `update()`
     * asigna a secas estas claves NOT NULL). `items` va vacío a propósito: el total que cuenta es el
     * que viaja en `total`.
     *
     * @param \App\Models\Sale $venta
     * @param float $total
     * @param array $overrides
     * @return array
     */
    protected function payload_de_edicion($venta, $total, $overrides = [])
    {
        return array_merge([
            'client_id'                  => $venta->client_id,
            'save_current_acount'        => $venta->save_current_acount,
            'omitir_en_cuenta_corriente' => $venta->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'items'                      => [],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * Edita una venta por el endpoint real.
     *
     * @param \App\Models\Sale $venta
     * @param float $total
     * @param array $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function editar_venta($venta, $total, $overrides = [])
    {
        $this->avanzar_reloj_de_ventas();

        return $this->putJson('api/sale/'.$venta->id, $this->payload_de_edicion($venta, $total, $overrides));
    }

    /**
     * Elimina una venta por el endpoint real.
     *
     * @param \App\Models\Sale $venta
     * @return \Illuminate\Testing\TestResponse
     */
    protected function eliminar_venta($venta)
    {
        $this->avanzar_reloj_de_ventas();

        return $this->deleteJson('api/sale/'.$venta->id);
    }

    /**
     * "Foto" de una cuenta: el saldo guardado y, por cada movimiento, su saldo, estado e imputado.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @return array
     */
    protected function foto($cuenta)
    {
        $filas = [];

        foreach ($this->movimientos($cuenta) as $m) {
            $filas[] = [
                'id'        => (int) $m->id,
                'debe'      => is_null($m->debe) ? null : round((float) $m->debe, 2),
                'haber'     => is_null($m->haber) ? null : round((float) $m->haber, 2),
                'saldo'     => is_null($m->saldo) ? null : round((float) $m->saldo, 2),
                'status'    => $m->status,
                'pagandose' => is_null($m->pagandose) ? null : round((float) $m->pagandose, 2),
            ];
        }

        return [
            'saldo_cuenta' => round($this->saldo_de($cuenta), 2),
            'movimientos'  => $filas,
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Eliminar la venta
    // ------------------------------------------------------------------------------------------

    /**
     * Regla (7): eliminar una venta en DÓLARES devuelve la cuenta en dólares a su saldo anterior (y
     * `saldo_dolares` del cliente) sin tocar la de pesos.
     *
     * @test
     */
    public function eliminar_una_venta_en_dolares_devuelve_la_cuenta_en_dolares_a_su_saldo_y_no_toca_la_de_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 100000);
        $v_usd_1 = $this->vender($cliente, self::DOLARES, 100);
        $v_usd_2 = $this->vender($cliente, self::DOLARES, 30);

        $foto_pesos = $this->foto($pesos);

        $this->assertMonto(130, $this->saldo_de($dolares));

        $this->eliminar_venta($v_usd_1)->assertStatus(200);

        $this->assertNull(Sale::find($v_usd_1->id), 'La venta no se eliminó.');
        $this->assertNull($this->debito_de($v_usd_1, $dolares), 'Quedó el movimiento de la venta eliminada.');

        // La cuenta en dólares queda solo con la otra venta.
        $this->assertMonto(30, $this->saldo_de($dolares));
        $this->assertMonto(30, $this->debito_de($v_usd_2, $dolares)->saldo, 'El saldo acumulado de la venta que quedó no se recalculó.');
        $this->assertMonto(30, $this->saldos_del_cliente($cliente)['dolares']);

        // La de pesos, idéntica.
        $this->assertEquals($foto_pesos, $this->foto($pesos), 'Eliminar una venta en dólares modificó la cuenta en pesos.');
        $this->assertMonto(100000, $this->saldos_del_cliente($cliente)['pesos']);

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: eliminar una venta en PESOS no toca la cuenta en dólares.
     *
     * @test
     */
    public function eliminar_una_venta_en_pesos_no_toca_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $v_ars = $this->vender($cliente, self::PESOS, 100000);
        $this->vender($cliente, self::DOLARES, 100);

        $foto_dolares = $this->foto($dolares);

        $this->eliminar_venta($v_ars)->assertStatus(200);

        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertMonto(0, $this->saldos_del_cliente($cliente)['pesos']);
        $this->assertEquals($foto_dolares, $this->foto($dolares), 'Eliminar una venta en pesos modificó la cuenta en dólares.');

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: eliminar una venta en dólares que YA fue cobrada con un pago cruzado (120000 ARS = 100
     * USD) deja el pago sin deuda que saldar: la cuenta en dólares queda con saldo a favor (-100) y la
     * cuenta en pesos no se entera (el pago era en pesos pero de la cuenta en dólares).
     *
     * @test
     */
    public function eliminar_una_venta_en_dolares_ya_cobrada_deja_el_pago_como_saldo_a_favor_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(0, $this->saldo_de($dolares));

        $this->eliminar_venta($venta)->assertStatus(200);

        $this->assertNotNull(CurrentAcount::find($pago->id), 'Eliminar la venta borró el pago.');
        $this->assertMonto(-100, $this->saldo_de($dolares), 'El pago tiene que quedar como saldo a favor en dólares.');
        $this->assertMonto(-100, $this->saldos_del_cliente($cliente)['dolares']);
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCount(0, $this->movimientos($pesos));

        // La imputación del pago a la venta borrada desapareció.
        $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $pago->id)->count());

        $this->assertCuentaConsistente($dolares);
    }

    // ------------------------------------------------------------------------------------------
    // Editar la venta
    // ------------------------------------------------------------------------------------------

    /**
     * Regla (7): editar el total de una venta en dólares (100 → 60) recrea el movimiento en la
     * cuenta en dólares con el total nuevo; sigue siendo UN solo movimiento; la venta conserva
     * `moneda_id = 2`; la cuenta en pesos queda intacta.
     *
     * @test
     */
    public function editar_el_total_de_una_venta_en_dolares_actualiza_solo_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 100000);
        $venta = $this->vender($cliente, self::DOLARES, 100);

        $foto_pesos = $this->foto($pesos);

        $this->editar_venta($venta, 60)->assertStatus(200);

        $venta = Sale::find($venta->id);
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id, 'La edición cambió la moneda de la venta.');
        $this->assertMonto(60, $venta->total);

        $debitos = $this->debitos($dolares);
        $this->assertCount(1, $debitos, 'La edición duplicó o borró el movimiento de la venta.');
        $this->assertMonto(60, $debitos[0]->debe);
        $this->assertMonto(60, $debitos[0]->saldo);
        $this->assertMonto(60, $this->saldo_de($dolares));
        $this->assertMonto(60, $this->saldos_del_cliente($cliente)['dolares']);

        $this->assertEquals($foto_pesos, $this->foto($pesos), 'Editar una venta en dólares modificó la cuenta en pesos.');

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: editar una venta en dólares que ya fue cobrada (pago cruzado 120000 ARS = 100 USD)
     * bajándole el total a 60 deja el débito `pagado` y 40 dólares a favor.
     *
     * @test
     */
    public function editar_a_la_baja_una_venta_en_dolares_ya_cobrada_deja_el_excedente_a_favor_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200),
        ]);

        $this->editar_venta($venta, 60)->assertStatus(200);

        $debito = $this->debito_de(Sale::find($venta->id), $dolares);
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto(60, $debito->pagandose);
        $this->assertMonto(-40, $this->saldo_de($dolares));
        $this->assertMonto(-40, $this->saldos_del_cliente($cliente)['dolares']);
        $this->assertMonto(0, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: editar a la ALZA una venta en dólares que tenía un pago parcial (cruzado): el débito
     * pasa a `pagandose` con lo ya pagado y el saldo es la diferencia.
     *
     * @test
     */
    public function editar_a_la_alza_una_venta_en_dolares_con_un_pago_cruzado_parcial_la_deja_pagandose()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200),
        ]);

        $this->editar_venta($venta, 150)->assertStatus(200);

        $debito = $this->debito_de(Sale::find($venta->id), $dolares);
        $this->assertEquals('pagandose', $debito->status);
        $this->assertMonto(100, $debito->pagandose);
        $this->assertMonto(50, $this->saldo_de($dolares));

        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: `SaleController::update()` NUNCA reasigna `moneda_id` (ver también el comentario de
     * `LimiteCreditoHelper::validar_venta_actualizada()`): un PUT que manda `moneda_id = 1` sobre una
     * venta en dólares no la pasa a pesos; el movimiento sigue en la cuenta en dólares.
     *
     * @test
     */
    public function un_put_con_otra_moneda_no_cambia_la_moneda_de_la_venta()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->editar_venta($venta, 100, ['moneda_id' => self::PESOS, 'valor_dolar' => 1500])->assertStatus(200);

        $venta = Sale::find($venta->id);
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);
        $this->assertMonto(self::DOLAR, $venta->valor_dolar, 'La edición pisó el valor del dólar con el que se hizo la venta.');
        $this->assertNotNull($this->debito_de($venta, $dolares));
        $this->assertNull($this->debito_de($venta, $pesos));
        $this->assertMonto(0, $this->saldo_de($pesos));
    }

    /**
     * Regla: editar una venta en PESOS no toca la cuenta en dólares.
     *
     * @test
     */
    public function editar_una_venta_en_pesos_no_toca_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 100000);
        $this->vender($cliente, self::DOLARES, 100);

        $foto_dolares = $this->foto($dolares);

        $this->editar_venta($venta, 70000)->assertStatus(200);

        $this->assertMonto(70000, $this->saldo_de($pesos));
        $this->assertEquals($foto_dolares, $this->foto($dolares));
        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    // ------------------------------------------------------------------------------------------
    // Recálculo de saldos
    // ------------------------------------------------------------------------------------------

    /**
     * Arma un historial mezclado: ventas y pagos en las dos monedas, con pagos cruzados en los dos
     * sentidos, parciales y un sobrepago.
     *
     * @return array [cliente, pesos, dolares]
     */
    protected function historial_mezclado()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 250000);
        $this->vender($cliente, self::DOLARES, 200);
        $this->vender($cliente, self::PESOS, 80000);

        // 100 USD pagan 120000 ARS de la deuda en pesos (cruzado).
        $this->pagar($cliente, $pesos, [$this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200)]);
        // 90000 ARS pagan 75 USD de la deuda en dólares (cruzado inverso).
        $this->pagar($cliente, $dolares, [$this->fila_de_pago(self::DOLARES, self::PESOS, 90000, $this->caja_pesos, 1200)]);

        $this->vender($cliente, self::DOLARES, 40);

        // Pago repartido sobre la cuenta en pesos: 30 USD + 60000 ARS.
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 30, $this->caja_dolares, 1200),
            $this->fila_de_pago(self::PESOS, self::PESOS, 60000, $this->caja_pesos),
        ]);

        // Pago en dólares de más: deja saldo a favor en la cuenta en dólares.
        $this->pagar($cliente, $dolares, [$this->fila_de_pago(self::DOLARES, self::DOLARES, 200, $this->caja_dolares)]);

        return [$cliente, $pesos, $dolares];
    }

    /**
     * Regla (7, consistencia): sobre un cliente con historial en las dos monedas, la cadena de
     * saldos de cada cuenta cierra y el saldo guardado de la cuenta y del cliente coinciden con el
     * del último movimiento (los chequeos de `cuenta_corriente:reparar_cadenas`).
     *
     * @test
     */
    public function un_cliente_con_historial_en_las_dos_monedas_tiene_las_dos_cuentas_consistentes()
    {
        list($cliente, $pesos, $dolares) = $this->historial_mezclado();

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);

        // Los números concretos del historial: pesos 250000 + 80000 - 120000 - 36000 - 60000 = 114000.
        $this->assertMonto(114000, $this->saldo_de($pesos));
        // Dólares: 200 + 40 - 75 - 200 = -35 (a favor).
        $this->assertMonto(-35, $this->saldo_de($dolares));

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(114000, $saldos['pesos']);
        $this->assertMonto(-35, $saldos['dolares']);
    }

    /**
     * Regla (7): recalcular (`GET api/check-saldos/{id}`) una cuenta consistente es IDEMPOTENTE: no
     * cambia ningún saldo, estado ni imputado de ningún movimiento, en ninguna de las dos cuentas.
     *
     * @test
     */
    public function recalcular_una_cuenta_consistente_no_cambia_nada()
    {
        list($cliente, $pesos, $dolares) = $this->historial_mezclado();

        $antes_pesos = $this->foto($pesos);
        $antes_dolares = $this->foto($dolares);
        $saldos_antes = $this->saldos_del_cliente($cliente);

        $this->getJson('api/check-saldos/'.$pesos->id)->assertStatus(200);
        $this->getJson('api/check-saldos/'.$dolares->id)->assertStatus(200);

        $this->assertEquals($antes_pesos, $this->foto($pesos), 'Recalcular la cuenta en pesos cambió sus movimientos.');
        $this->assertEquals($antes_dolares, $this->foto($dolares), 'Recalcular la cuenta en dólares cambió sus movimientos.');
        $this->assertEquals($saldos_antes, $this->saldos_del_cliente($cliente));
    }

    /**
     * Regla (7): recalcular REPARA una cadena corrupta y llega a los mismos saldos que había antes de
     * la corrupción. Se corrompen a mano (setup) el `saldo` de movimientos de las dos cuentas y el
     * saldo denormalizado del cliente; el recálculo tiene que devolverlo todo a lo original.
     *
     * @test
     */
    public function recalcular_repara_una_cadena_corrupta_en_las_dos_monedas()
    {
        list($cliente, $pesos, $dolares) = $this->historial_mezclado();

        $original_pesos = $this->foto($pesos);
        $original_dolares = $this->foto($dolares);

        // Corrupción: un saldo intermedio de cada cuenta y los saldos del cliente.
        $mov_pesos = $this->movimientos($pesos);
        $mov_dolares = $this->movimientos($dolares);
        CurrentAcount::where('id', $mov_pesos[1]->id)->update(['saldo' => 1]);
        CurrentAcount::where('id', $mov_dolares[1]->id)->update(['saldo' => 999999]);
        CreditAccount::where('id', $pesos->id)->update(['saldo' => 5]);
        CreditAccount::where('id', $dolares->id)->update(['saldo' => 7]);
        DB::table('clients')->where('id', $cliente->id)->update(['saldo_pesos' => 3, 'saldo_dolares' => 4]);

        $this->assertNotNull(
            \App\Http\Controllers\Helpers\CurrentAcountHelper::primer_corte_de_la_cadena($pesos->id),
            'La corrupción de prueba no cortó la cadena en pesos: el test no mide nada.'
        );

        $this->getJson('api/check-saldos/'.$pesos->id)->assertStatus(200);
        $this->getJson('api/check-saldos/'.$dolares->id)->assertStatus(200);

        $this->assertEquals($original_pesos, $this->foto($pesos), 'El recálculo no devolvió la cuenta en pesos a sus saldos originales.');
        $this->assertEquals($original_dolares, $this->foto($dolares), 'El recálculo no devolvió la cuenta en dólares a sus saldos originales.');

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto($original_pesos['saldo_cuenta'], $saldos['pesos']);
        $this->assertMonto($original_dolares['saldo_cuenta'], $saldos['dolares']);

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: recalcular la cuenta en PESOS no toca la cuenta en DÓLARES (ni su saldo ni el del
     * cliente en dólares), aunque esa esté corrupta: cada cuenta se repara por separado.
     *
     * @test
     */
    public function recalcular_una_cuenta_no_repara_ni_toca_la_de_la_otra_moneda()
    {
        list($cliente, $pesos, $dolares) = $this->historial_mezclado();

        CreditAccount::where('id', $dolares->id)->update(['saldo' => 7]);
        DB::table('clients')->where('id', $cliente->id)->update(['saldo_dolares' => 4]);

        $this->getJson('api/check-saldos/'.$pesos->id)->assertStatus(200);

        $this->assertMonto(7, $this->saldo_de($dolares), 'Recalcular la cuenta en pesos tocó la cuenta en dólares.');
        $this->assertMonto(4, $this->saldos_del_cliente($cliente)['dolares'], 'Recalcular la cuenta en pesos tocó saldo_dolares del cliente.');
        $this->assertMonto(114000, $this->saldos_del_cliente($cliente)['pesos']);
    }
}
