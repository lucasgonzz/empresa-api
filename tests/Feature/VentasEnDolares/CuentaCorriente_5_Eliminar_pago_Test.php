<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Caja;
use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * ELIMINAR pagos de cuenta corriente (`DELETE api/current-acount/client/{id}`), en particular los
 * pagos CRUZADOS de monedas y los repartidos entre las dos, comercio 2R.
 *
 * Reglas (de `CurrentAcountController::delete()` y `DeleteCajaCompensacionHelper`):
 *
 *  - Borrar el pago revierte SU cuenta: la cadena de saldos se recalcula, los débitos vuelven a
 *    `sin_pagar` / se re-imputan, y `saldo_pesos` / `saldo_dolares` del cliente acompañan.
 *  - La cuenta de la otra moneda no se toca.
 *  - La caja SOLO se revierte con `compensar_caja = true`: crea un movimiento inverso por cada fila
 *    con caja, por el `amount` de la fila EN SU MONEDA (no el cotizado). Sin ese flag, el
 *    movimiento original de la caja queda (es decisión de diseño, la revierte Tesorería a mano).
 *  - Si alguna caja involucrada está cerrada y se pidió compensar, se rechaza con 422 y NO se borra
 *    nada.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_5_Eliminar_pago_Test extends CuentaCorriente_Base
{
    /**
     * Elimina un pago de un cliente por el endpoint real.
     *
     * @param \App\Models\CurrentAcount $pago
     * @param bool|null $compensar_caja null = no manda el flag.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function eliminar_pago($pago, $compensar_caja = null)
    {
        $this->avanzar_reloj_de_ventas();

        $desde = $this->max_id_movimiento_caja();

        $payload = is_null($compensar_caja) ? [] : ['compensar_caja' => $compensar_caja];

        $response = $this->deleteJson('api/current-acount/client/'.$pago->id, $payload);

        $this->registrar_movimientos_caja_nuevos($desde);

        return $response;
    }

    /**
     * Regla (6): eliminar un pago cruzado (100 USD sobre una deuda en pesos) SIN compensar caja
     * revierte la cuenta en pesos: el saldo vuelve a 120000, el débito a `sin_pagar`, la imputación
     * se borra y `saldo_pesos` acompaña; la cuenta en dólares no se toca y la caja en dólares
     * conserva su movimiento original (sin compensación no se revierte la caja).
     *
     * @test
     */
    public function eliminar_un_pago_cruzado_revierte_la_cuenta_en_pesos_y_no_toca_la_de_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertMonto(100, $this->saldo_de($dolares));

        $this->eliminar_pago($pago)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($pago->id), 'El pago no se eliminó.');

        // La cuenta en pesos vuelve a como estaba antes del pago.
        $this->assertMonto(120000, $this->saldo_de($pesos));
        $debito = $this->debito_de($venta, $pesos);
        $this->assertEquals('sin_pagar', $debito->status);
        $this->assertMonto(0, $debito->pagandose);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $debito->id)->count(), 'Quedó la imputación del pago borrado.');

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(120000, $saldos['pesos']);
        $this->assertMonto(100, $saldos['dolares'], 'Borrar el pago cruzado movió la cuenta en dólares.');
        $this->assertMonto(100, $this->saldo_de($dolares));

        // Sin compensar_caja, la caja en dólares conserva lo que entró.
        $this->assertMonto(100, $this->saldo_de_caja_bd($this->caja_dolares));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla (6): con `compensar_caja = true`, borrar el pago cruzado (100 USD sobre deuda en pesos)
     * saca de la caja en dólares 100 (lo entregado, no los 120000 cotizados) y deja la caja en 0; la
     * caja en pesos no se mueve.
     *
     * @test
     */
    public function eliminar_un_pago_cruzado_compensando_caja_saca_de_la_caja_su_monto_en_su_moneda()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $venta = $this->vender($cliente, self::PESOS, 120000);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        $desde = $this->max_id_movimiento_caja();

        $this->eliminar_pago($pago, true)->assertStatus(200);

        $compensaciones = $this->movimientos_de_caja($this->caja_dolares, $desde);
        $this->assertCount(1, $compensaciones, 'Tendría que haber un movimiento compensatorio en la caja en dólares.');
        $this->assertMonto(100, $compensaciones[0]->egreso, 'La compensación tiene que ser por lo entregado (100 USD), no por el cotizado.');
        $this->assertNull($compensaciones[0]->ingreso);
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_dolares));

        $this->assertCount(0, $this->movimientos_de_caja($this->caja_pesos, $desde));
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_pesos));

        $this->assertMonto(120000, $this->saldo_de($pesos));
        $this->assertEquals('sin_pagar', $this->debito_de($venta, $pesos)->status);
    }

    /**
     * Regla (6, espejo): pago en PESOS sobre deuda en dólares, borrado compensando caja: la caja en
     * pesos devuelve 120000 (no los 100 USD cotizados) y la cuenta en dólares vuelve a 100.
     *
     * @test
     */
    public function eliminar_un_pago_en_pesos_de_una_deuda_en_dolares_compensando_caja()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200),
        ]);

        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertMonto(120000, $this->saldo_de_caja_bd($this->caja_pesos));

        $desde = $this->max_id_movimiento_caja();

        $this->eliminar_pago($pago, true)->assertStatus(200);

        $compensaciones = $this->movimientos_de_caja($this->caja_pesos, $desde);
        $this->assertCount(1, $compensaciones);
        $this->assertMonto(120000, $compensaciones[0]->egreso);
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_pesos));
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_dolares));

        $this->assertMonto(100, $this->saldo_de($dolares));
        $this->assertEquals('sin_pagar', $this->debito_de($venta, $dolares)->status);
        $this->assertMonto(100, $this->saldos_del_cliente($cliente)['dolares']);
        $this->assertMonto(0, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($dolares);
        $this->assertCuentaConsistente($pesos);
    }

    /**
     * Regla (6): un pago REPARTIDO entre las dos monedas (100 USD + 180000 ARS sobre una deuda de
     * 300000 ARS), borrado compensando caja: cada caja devuelve lo suyo en su moneda y las dos
     * quedan en 0; la deuda vuelve entera.
     *
     * @test
     */
    public function eliminar_un_pago_repartido_en_dos_monedas_compensa_cada_caja_en_su_moneda()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $venta = $this->vender($cliente, self::PESOS, 300000);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
            $this->fila_de_pago(self::PESOS, self::PESOS, 180000, $this->caja_pesos),
        ]);

        $desde = $this->max_id_movimiento_caja();

        $this->eliminar_pago($pago, true)->assertStatus(200);

        $mov_dolares = $this->movimientos_de_caja($this->caja_dolares, $desde);
        $mov_pesos = $this->movimientos_de_caja($this->caja_pesos, $desde);

        $this->assertCount(1, $mov_dolares);
        $this->assertCount(1, $mov_pesos);
        $this->assertMonto(100, $mov_dolares[0]->egreso);
        $this->assertMonto(180000, $mov_pesos[0]->egreso);
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_dolares));
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_pesos));

        $this->assertMonto(300000, $this->saldo_de($pesos));
        $this->assertEquals('sin_pagar', $this->debito_de($venta, $pesos)->status);
        $this->assertCuentaConsistente($pesos);
    }

    /**
     * Regla: si se pide compensar caja y una de las cajas involucradas está CERRADA, el borrado se
     * rechaza con 422 nombrando la caja y NO se borra nada (ni el pago, ni el saldo, ni la caja).
     *
     * @test
     */
    public function compensar_caja_con_una_caja_cerrada_rechaza_el_borrado_y_no_toca_nada()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::PESOS, 120000);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        // Se cierra la caja en dólares (como al final de la jornada).
        $caja = Caja::find($this->caja_dolares->id);
        $caja->abierta = 0;
        $caja->save();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->eliminar_pago($pago, true);

        $response->assertStatus(422);
        $this->assertStringContainsString('Caja CC Dolares Test', $response->json('message'));

        $this->assertNotNull(CurrentAcount::find($pago->id), 'Se borró el pago aunque la compensación se rechazó.');
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCount(0, $this->movimientos_de_caja($this->caja_dolares, $desde));
        $this->assertMonto(100, $this->saldo_de_caja_bd($this->caja_dolares));
    }

    /**
     * Regla: eliminar el PRIMERO de dos pagos (uno en pesos, uno cruzado en dólares) sobre la misma
     * deuda en pesos re-imputa el segundo y deja la cadena consistente: el saldo es la deuda menos
     * SOLO el pago que queda, y el débito queda `pagandose` con ese monto.
     *
     * @test
     */
    public function eliminar_un_pago_del_medio_recalcula_la_cadena_con_los_pagos_que_quedan()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 200000);
        $this->vender($cliente, self::DOLARES, 50);

        $p1 = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::PESOS, 50000, $this->caja_pesos),
        ]);
        $p2 = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 60, $this->caja_dolares, 1200),
        ]);

        $this->assertMonto(200000 - 50000 - 72000, $this->saldo_de($pesos));

        $this->eliminar_pago($p1)->assertStatus(200);

        $this->assertMonto(200000 - 72000, $this->saldo_de($pesos));
        $this->assertMonto(200000 - 72000, $p2->fresh()->saldo, 'El saldo del pago que quedó no se recalculó.');

        $debito = $this->debito_de($venta, $pesos);
        $this->assertEquals('pagandose', $debito->status);
        $this->assertMonto(72000, $debito->pagandose);

        $this->assertMonto(50, $this->saldo_de($dolares));
        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: eliminar un pago en dólares no puede dejar filas de medios de pago huérfanas: las
     * filas de `current_acount_current_acount_payment_method` del pago borrado desaparecen con él
     * (si no, quedan cobros "fantasma" con moneda y caja que ningún pago reclama).
     *
     * @group hallazgo-moneda
     * @test
     */
    public function eliminar_un_pago_no_deja_filas_de_medios_de_pago_huerfanas()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::PESOS, 120000);

        $pago = $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);

        $this->assertEquals(1, DB::table('current_acount_current_acount_payment_method')->where('current_acount_id', $pago->id)->count());

        $this->eliminar_pago($pago)->assertStatus(200);

        $this->assertEquals(
            0,
            DB::table('current_acount_current_acount_payment_method')->where('current_acount_id', $pago->id)->count(),
            'El pago se eliminó pero sus filas de medios de pago (moneda, cotización, caja) quedaron en la base.'
        );
    }
}
