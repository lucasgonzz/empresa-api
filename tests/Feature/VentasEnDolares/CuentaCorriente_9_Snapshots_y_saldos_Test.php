<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CreditAccountSnapshot;
use App\Models\DebtSnapshot;

/**
 * SNAPSHOTS de saldo (`debt:snapshot`) y COMPOSICIÓN de los saldos del cliente con las dos monedas.
 * Comercio 2R.
 *
 * Reglas:
 *  - `credit_account_snapshots` guarda UNA fila por cuenta (cliente x moneda) el día en que su saldo
 *    cambió, con `moneda_id` y el saldo en la moneda de la cuenta. Un pago cruzado mueve solo la
 *    cuenta pagada: la otra cuenta no genera fila nueva.
 *  - `debt_snapshots` agrega por dueño en DOS columnas separadas: `deuda_clientes` (cuentas en
 *    pesos) y `deuda_clientes_usd` (cuentas en dólares). Nunca se suman entre sí.
 *  - `clients.saldo` (el saldo "unificado") es una COLUMNA MUERTA: `CurrentAcountHelper` no la
 *    escribe desde que las cuentas se partieron por moneda; el saldo vivo es `saldo_pesos` +
 *    `saldo_dolares` (cada uno espejo de su `credit_accounts.saldo`). No hay fórmula que los
 *    componga en un solo número, y eso es coherente: sumar pesos y dólares sin cotizar no tiene
 *    sentido, y el listado de clientes suma las dos columnas por separado
 *    (`SearchController`).
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_9_Snapshots_y_saldos_Test extends CuentaCorriente_Base
{
    /** Día base de las pruebas de snapshot, lejos de cualquier "hoy" real. */
    const DIA_1 = '2031-03-01';

    /** Día siguiente. */
    const DIA_2 = '2031-03-02';

    /**
     * Corre `debt:snapshot` como si fuera el día pedido (a las 23:59, como en el Kernel).
     *
     * @param string $dia
     * @return void
     */
    protected function snapshot_del_dia($dia)
    {
        $this->fijar_reloj_en($dia.' 23:59:00');

        $this->artisan('debt:snapshot')->assertExitCode(0);
    }

    /**
     * El snapshot agregado del dueño en un día.
     *
     * @param string $dia
     * @return \App\Models\DebtSnapshot
     */
    protected function debt_snapshot($dia)
    {
        $snapshot = DebtSnapshot::where('user_id', $this->usuario->id)->where('date', $dia)->first();

        $this->assertNotNull($snapshot, 'debt:snapshot no escribió el snapshot agregado del '.$dia.'.');

        return $snapshot;
    }

    /**
     * Regla (10): `credit_account_snapshots` guarda una fila por cuenta con SU moneda y SU saldo: la
     * cuenta en pesos con el saldo en pesos y la cuenta en dólares con el saldo en dólares. Una
     * cuenta en 0 y sin historia no genera fila.
     *
     * @test
     */
    public function el_snapshot_por_cuenta_guarda_cada_moneda_con_su_saldo()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $cliente_solo_pesos = $this->cliente_nuevo();
        $dolares_en_cero = $this->cuenta($cliente_solo_pesos, self::DOLARES);

        $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);
        $this->vender($cliente_solo_pesos, self::PESOS, 5000);

        $this->snapshot_del_dia(self::DIA_1);

        $foto_pesos = CreditAccountSnapshot::where('credit_account_id', $pesos->id)->first();
        $foto_dolares = CreditAccountSnapshot::where('credit_account_id', $dolares->id)->first();

        $this->assertNotNull($foto_pesos, 'La cuenta en pesos con saldo no tiene snapshot.');
        $this->assertNotNull($foto_dolares, 'La cuenta en dólares con saldo no tiene snapshot.');

        $this->assertEquals(self::PESOS, (int) $foto_pesos->moneda_id);
        $this->assertMonto(120000, $foto_pesos->saldo);
        $this->assertEquals($cliente->id, (int) $foto_pesos->model_id);
        $this->assertEquals('client', $foto_pesos->model_name);

        $this->assertEquals(self::DOLARES, (int) $foto_dolares->moneda_id);
        $this->assertMonto(100, $foto_dolares->saldo, 'El snapshot de la cuenta en dólares no guardó el saldo en dólares.');

        $this->assertEquals(
            0,
            CreditAccountSnapshot::where('credit_account_id', $dolares_en_cero->id)->count(),
            'Una cuenta en dólares en 0 y sin historia no tiene que generar fila.'
        );
    }

    /**
     * Regla (10): un pago CRUZADO mueve solo la cuenta pagada. Al día siguiente el snapshot de la
     * cuenta en pesos cambia (nueva fila) y la cuenta en dólares NO agrega fila (su saldo no
     * cambió). Y el saldo al día (`scopeSaldoAlDia`) de cada cuenta es el de su moneda.
     *
     * @test
     */
    public function un_pago_cruzado_solo_genera_snapshot_nuevo_en_la_cuenta_pagada()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        $this->snapshot_del_dia(self::DIA_1);

        // 50 USD pagan 60000 de la deuda en pesos.
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 50, $this->caja_dolares, 1200),
        ]);

        $this->snapshot_del_dia(self::DIA_2);

        $this->assertEquals(2, CreditAccountSnapshot::where('credit_account_id', $pesos->id)->count(), 'La cuenta en pesos cambió de saldo y tendría que tener snapshot nuevo.');
        $this->assertEquals(1, CreditAccountSnapshot::where('credit_account_id', $dolares->id)->count(), 'La cuenta en dólares no cambió de saldo y no tendría que tener snapshot nuevo.');

        $pesos_dia_2 = CreditAccountSnapshot::where('credit_account_id', $pesos->id)->saldoAlDia(self::DIA_2)->first();
        $dolares_dia_2 = CreditAccountSnapshot::where('credit_account_id', $dolares->id)->saldoAlDia(self::DIA_2)->first();
        $pesos_dia_1 = CreditAccountSnapshot::where('credit_account_id', $pesos->id)->saldoAlDia(self::DIA_1)->first();

        $this->assertMonto(60000, $pesos_dia_2->saldo);
        $this->assertMonto(100, $dolares_dia_2->saldo, 'El saldo en dólares al día 2 tiene que seguir siendo el del día 1.');
        $this->assertMonto(120000, $pesos_dia_1->saldo);
    }

    /**
     * Regla (10): `debt_snapshots` separa la deuda de clientes en pesos (`deuda_clientes`) de la
     * deuda en dólares (`deuda_clientes_usd`): el delta entre dos días es exactamente lo movido en
     * cada moneda, sin mezclar. Se mide por diferencia entre el día 1 (antes) y el día 2 (después),
     * para no depender de lo que hubiera en la base.
     *
     * @test
     */
    public function el_snapshot_agregado_separa_la_deuda_en_pesos_de_la_deuda_en_dolares()
    {
        $this->snapshot_del_dia(self::DIA_1);
        $base = $this->debt_snapshot(self::DIA_1);

        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        // Un pago cruzado: 50 USD pagan 60000 de la deuda en pesos.
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 50, $this->caja_dolares, 1200),
        ]);

        $this->snapshot_del_dia(self::DIA_2);
        $despues = $this->debt_snapshot(self::DIA_2);

        $this->assertMonto(60000, (float) $despues->deuda_clientes - (float) $base->deuda_clientes, 'La deuda en pesos del agregado no refleja la venta menos el pago cruzado.');
        $this->assertMonto(100, (float) $despues->deuda_clientes_usd - (float) $base->deuda_clientes_usd, 'La deuda en dólares del agregado no es la de las cuentas en dólares.');
    }

    /**
     * Regla (10, `clients.saldo`): tras un historial con pagos cruzados y ventas en las dos monedas,
     * `clients.saldo` sigue sin escribirse y `saldo_pesos` / `saldo_dolares` son el espejo exacto de
     * `credit_accounts.saldo` de cada moneda. Ninguno de los tres se calcula como suma de los otros
     * dos (no hay fórmula "pesos + dólares x cotización").
     *
     * @test
     */
    public function los_saldos_del_cliente_son_el_espejo_de_cada_cuenta_y_el_unificado_no_existe()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 250000);
        $this->vender($cliente, self::DOLARES, 200);
        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 100, $this->caja_dolares, 1200),
        ]);
        $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 60000, $this->caja_pesos, 1200),
        ]);

        $saldos = $this->saldos_del_cliente($cliente);

        $this->assertMonto($this->saldo_de($pesos), $saldos['pesos']);
        $this->assertMonto($this->saldo_de($dolares), $saldos['dolares']);
        $this->assertMonto(250000 - 120000, $saldos['pesos']);
        $this->assertMonto(200 - 50, $saldos['dolares']);

        $this->assertNull(
            $saldos['saldo'],
            'clients.saldo tiene un valor ('.var_export($saldos['saldo'], true).'): alguien volvió a escribir la columna unificada y hay que definir cómo compone pesos y dólares.'
        );
    }
}
