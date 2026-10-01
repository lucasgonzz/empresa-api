<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CurrentAcount;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Ventas a cuenta corriente en PESOS y en DÓLARES (comercio 2R: `ventas_en_dolares`, con
 * `cotizar_precios_en_dolares = 0`).
 *
 * Las cuentas corrientes están partidas por moneda: `CurrentAcountFromSaleHelper` busca la
 * `credit_account` por `client + moneda_id` (default 1 = pesos) y el movimiento entra en ESA cuenta.
 * `clients.saldo_pesos` y `clients.saldo_dolares` se sincronizan desde cada cuenta
 * (`CurrentAcountHelper::set_model_saldo()`), y una moneda nunca toca a la otra.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_Ventas_Test extends CuentaCorriente_Base
{
    /**
     * Regla (escenario 1): una venta a cuenta corriente EN PESOS crea un movimiento en la cuenta de
     * moneda 1 con `debe` = total y `status = sin_pagar`; `clients.saldo_pesos` sube; la cuenta en
     * dólares NO se toca. Se usa el cliente `CLIENTE_CC` del fixture midiendo contra la línea base.
     *
     * @test
     */
    public function una_venta_en_pesos_a_cuenta_corriente_entra_solo_a_la_cuenta_en_pesos()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta_pesos = $this->cuenta($cliente, self::PESOS);
        $cuenta_dolares = $this->cuenta($cliente, self::DOLARES);

        $base_pesos = $this->saldo_de($cuenta_pesos);
        $base_dolares = $this->saldo_de($cuenta_dolares);
        $movimientos_dolares_antes = $this->movimientos($cuenta_dolares)->count();

        $venta = $this->vender($cliente, self::PESOS, 120000);

        $debito = $this->debito_de($venta, $cuenta_pesos);

        $this->assertNotNull($debito, 'La venta en pesos no generó movimiento en la cuenta en pesos.');
        $this->assertMonto(120000, $debito->debe);
        $this->assertEquals('sin_pagar', $debito->status);
        $this->assertMonto($base_pesos + 120000, $debito->saldo, 'El saldo acumulado del movimiento no suma el debe al saldo previo.');

        $this->assertMonto($base_pesos + 120000, $this->saldo_de($cuenta_pesos));
        $this->assertMonto($base_pesos + 120000, $this->saldos_del_cliente($cliente)['pesos'], 'clients.saldo_pesos no acompañó la cuenta.');

        // La cuenta en dólares, intacta.
        $this->assertMonto($base_dolares, $this->saldo_de($cuenta_dolares));
        $this->assertEquals($movimientos_dolares_antes, $this->movimientos($cuenta_dolares)->count(), 'La venta en pesos dejó un movimiento en la cuenta en dólares.');

        $this->assertNull($this->debito_de($venta, $cuenta_dolares));

        $this->assertCuentaConsistente($cuenta_pesos);
        $this->assertCuentaConsistente($cuenta_dolares);
    }

    /**
     * Regla (escenario 2): una venta EN DÓLARES entra en la cuenta de moneda 2 con `debe` = total en
     * dólares (NO convertido a pesos); `clients.saldo_dolares` sube; la cuenta en pesos NO se toca.
     * La venta guarda su moneda y el dólar del día.
     *
     * @test
     */
    public function una_venta_en_dolares_a_cuenta_corriente_entra_solo_a_la_cuenta_en_dolares()
    {
        $cliente = $this->resolver_cliente_por_nombre(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta_pesos = $this->cuenta($cliente, self::PESOS);
        $cuenta_dolares = $this->cuenta($cliente, self::DOLARES);

        $base_pesos = $this->saldo_de($cuenta_pesos);
        $base_dolares = $this->saldo_de($cuenta_dolares);
        $movimientos_pesos_antes = $this->movimientos($cuenta_pesos)->count();

        // Un artículo de 120000 pesos vendido en dólares: la SPA manda 120000 / 1200 = 100.
        $venta = $this->vender($cliente, self::DOLARES, 120000 / self::DOLAR);

        // La venta guarda su moneda y la cotización del día.
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);
        $this->assertMonto(self::DOLAR, $venta->valor_dolar);
        $this->assertMonto(100, $venta->total);

        $debito = $this->debito_de($venta, $cuenta_dolares);

        $this->assertNotNull($debito, 'La venta en dólares no generó movimiento en la cuenta en dólares.');
        $this->assertMonto(100, $debito->debe, 'El debe tiene que estar en dólares, sin convertir a pesos.');
        $this->assertEquals('sin_pagar', $debito->status);
        $this->assertMonto($base_dolares + 100, $debito->saldo);
        $this->assertEquals(self::DOLARES, (int) $debito->moneda_id, 'El movimiento no resuelve la moneda de su cuenta.');

        $this->assertMonto($base_dolares + 100, $this->saldo_de($cuenta_dolares));
        $this->assertMonto($base_dolares + 100, $this->saldos_del_cliente($cliente)['dolares'], 'clients.saldo_dolares no acompañó la cuenta.');

        // La cuenta en pesos, intacta.
        $this->assertMonto($base_pesos, $this->saldo_de($cuenta_pesos));
        $this->assertEquals($movimientos_pesos_antes, $this->movimientos($cuenta_pesos)->count(), 'La venta en dólares dejó un movimiento en la cuenta en pesos.');

        $this->assertNull($this->debito_de($venta, $cuenta_pesos));

        $this->assertCuentaConsistente($cuenta_pesos);
        $this->assertCuentaConsistente($cuenta_dolares);
    }

    /**
     * Regla: dos ventas seguidas en monedas alternadas (ARS, USD, ARS, USD) llevan CADA UNA su propio
     * saldo acumulado: el `saldo` de cada movimiento suma solo los de su moneda, y los saldos del
     * cliente (`saldo_pesos`, `saldo_dolares`) son los de la última fila de cada cuenta.
     *
     * @test
     */
    public function ventas_en_monedas_alternadas_llevan_cada_una_su_propio_saldo_acumulado()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta_pesos = $this->cuenta($cliente, self::PESOS);
        $cuenta_dolares = $this->cuenta($cliente, self::DOLARES);

        $v1 = $this->vender($cliente, self::PESOS, 120000);
        $v2 = $this->vender($cliente, self::DOLARES, 100);
        $v3 = $this->vender($cliente, self::PESOS, 60000);
        $v4 = $this->vender($cliente, self::DOLARES, 50);

        $this->assertMonto(120000, $this->debito_de($v1, $cuenta_pesos)->saldo);
        $this->assertMonto(100, $this->debito_de($v2, $cuenta_dolares)->saldo, 'La primera venta en dólares arrastró el saldo en pesos.');
        $this->assertMonto(180000, $this->debito_de($v3, $cuenta_pesos)->saldo, 'La segunda venta en pesos no suma solo las ventas en pesos.');
        $this->assertMonto(150, $this->debito_de($v4, $cuenta_dolares)->saldo, 'La segunda venta en dólares no suma solo las ventas en dólares.');

        $this->assertMonto(180000, $this->saldo_de($cuenta_pesos));
        $this->assertMonto(150, $this->saldo_de($cuenta_dolares));

        $saldos = $this->saldos_del_cliente($cliente);
        $this->assertMonto(180000, $saldos['pesos']);
        $this->assertMonto(150, $saldos['dolares']);

        $this->assertCount(2, $this->debitos($cuenta_pesos));
        $this->assertCount(2, $this->debitos($cuenta_dolares));

        $this->assertCuentaConsistente($cuenta_pesos);
        $this->assertCuentaConsistente($cuenta_dolares);
    }

    /**
     * Regla: una venta sin `moneda_id` en el payload (integraciones, SPA vieja) es una venta en
     * pesos: `SaleController::store()` le pone moneda 1 y entra a la cuenta en pesos.
     *
     * @test
     */
    public function una_venta_sin_moneda_es_una_venta_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta_pesos = $this->cuenta($cliente, self::PESOS);
        $cuenta_dolares = $this->cuenta($cliente, self::DOLARES);

        $payload = $this->payload_de_venta($cliente->id, self::PESOS, 5000);
        unset($payload['moneda_id'], $payload['valor_dolar']);

        $this->avanzar_reloj_de_ventas();

        $response = $this->postJson('api/sale', $payload);
        $response->assertStatus(201);

        $venta_id = $response->json('model.id');
        $this->ventas_creadas_por_escenarios[] = $venta_id;

        $this->assertEquals(self::PESOS, (int) \App\Models\Sale::find($venta_id)->moneda_id);
        $this->assertMonto(5000, $this->saldo_de($cuenta_pesos));
        $this->assertMonto(0, $this->saldo_de($cuenta_dolares));
    }

    /**
     * Regla: el movimiento de cuenta corriente de una venta en dólares queda en la cuenta en dólares
     * AUNQUE el cliente ya tenga deuda en pesos (y viceversa): dos deudas de monedas distintas no se
     * mezclan ni se compensan entre sí. Cada `debe` conserva su monto original.
     *
     * @test
     */
    public function la_deuda_en_una_moneda_no_compensa_la_deuda_en_la_otra()
    {
        $cliente = $this->cliente_nuevo();
        $cuenta_pesos = $this->cuenta($cliente, self::PESOS);
        $cuenta_dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 240000);
        $this->vender($cliente, self::DOLARES, 200);

        $debitos_pesos = $this->debitos($cuenta_pesos);
        $debitos_dolares = $this->debitos($cuenta_dolares);

        $this->assertCount(1, $debitos_pesos);
        $this->assertCount(1, $debitos_dolares);
        $this->assertMonto(240000, $debitos_pesos[0]->debe);
        $this->assertMonto(200, $debitos_dolares[0]->debe);
        $this->assertEquals('sin_pagar', $debitos_pesos[0]->status);
        $this->assertEquals('sin_pagar', $debitos_dolares[0]->status);
        $this->assertMonto(0, $debitos_pesos[0]->pagandose);
        $this->assertMonto(0, $debitos_dolares[0]->pagandose);
    }

    /**
     * Regla (escenario 10, `clients.saldo`): `clients.saldo` es la columna VIEJA de saldo unificado.
     * `CurrentAcountHelper` ya no la escribe (el saldo vivo se sincroniza en `credit_accounts` y en
     * `clients.saldo_pesos` / `clients.saldo_dolares`; ver el docblock de
     * `ConsultasSistemaIaHelper::clientes()`), así que una venta en cualquiera de las dos monedas la
     * deja como estaba. Si algún día vuelve a escribirse, este test obliga a decidir la fórmula
     * (¿pesos + dólares × cotización?) en vez de mezclar monedas por accidente.
     *
     * @test
     */
    public function la_columna_vieja_clients_saldo_no_se_mueve_con_ninguna_moneda()
    {
        $cliente = $this->cliente_nuevo();

        $antes = $this->saldos_del_cliente($cliente)['saldo'];

        $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        $despues = $this->saldos_del_cliente($cliente);

        $this->assertSame($antes, $despues['saldo'], 'clients.saldo cambió: ya no es una columna muerta y hay que definir cómo compone pesos y dólares.');

        // Lo que sí está vivo: los dos saldos por moneda.
        $this->assertMonto(120000, $despues['pesos']);
        $this->assertMonto(100, $despues['dolares']);
    }

    /**
     * Regla: cada venta a cuenta corriente deja exactamente UN movimiento de débito (no uno por
     * moneda): las ventas en dólares no duplican su movimiento en la cuenta en pesos "convertido".
     *
     * @test
     */
    public function una_venta_deja_un_solo_movimiento_de_debito_en_todo_el_cliente()
    {
        $cliente = $this->cliente_nuevo();

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->assertEquals(
            1,
            CurrentAcount::where('sale_id', $venta->id)->count(),
            'La venta en dólares dejó más de un movimiento de cuenta corriente.'
        );
        $this->assertEquals(
            1,
            CurrentAcount::where('client_id', $cliente->id)->count()
        );
    }
}
