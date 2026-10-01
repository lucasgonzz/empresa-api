<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * NOTAS DE CRÉDITO y DEVOLUCIONES sobre ventas en dólares (y en pesos) a cuenta corriente, comercio
 * 2R.
 *
 * Reglas (de `DevolucionesController::store()` y `CurrentAcountHelper::notaCredito()`):
 *  - La devolución de una venta va a la cuenta corriente de LA MONEDA DE LA VENTA
 *    (`get_moneda_id($request)` mira `sales.moneda_id`): el `haber` de la nota de crédito está en
 *    dólares si la venta fue en dólares, sin convertir, y `current_acounts.moneda_id` de la NC es 2.
 *  - La NC se imputa DIRIGIDA al débito de la venta devuelta (`to_pay_id`); el sobrante cae en FIFO
 *    en los débitos de la MISMA cuenta.
 *  - La cuenta de la otra moneda no se toca.
 *  - La NC de monto libre (`POST api/current-acount/nota-credito`) cae en la cuenta que se le indica.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_7_Nota_de_credito_Test extends CuentaCorriente_Base
{
    /**
     * Payload de `POST api/devoluciones` (calcado de tests/Feature/Devoluciones/1).
     *
     * @param \App\Models\Sale|null $venta
     * @param \App\Models\Client $cliente
     * @param float $total
     * @param array $overrides
     * @return array
     */
    protected function payload_de_devolucion($venta, $cliente, $total, $overrides = [])
    {
        return array_merge([
            'sale_id'                   => $venta ? $venta->id : null,
            'client_id'                 => $cliente->id,
            'generar_current_acount'    => true,
            'total_devolucion'          => $total,
            'observaciones'             => 'Devolución de test de monedas',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ], $overrides);
    }

    /**
     * Postea una devolución y devuelve la nota de crédito creada.
     *
     * @param \App\Models\Sale|null $venta
     * @param \App\Models\Client $cliente
     * @param float $total
     * @return \App\Models\CurrentAcount
     */
    protected function devolver($venta, $cliente, $total)
    {
        $this->avanzar_reloj_de_ventas();

        $this->postJson('api/devoluciones', $this->payload_de_devolucion($venta, $cliente, $total))->assertStatus(201);

        $nota = CurrentAcount::where('status', 'nota_credito')
                            ->where('client_id', $cliente->id)
                            ->orderBy('id', 'DESC')
                            ->first();

        $this->assertNotNull($nota, 'La devolución no creó la nota de crédito.');

        $this->cobros_cc_creados_por_escenarios[] = $nota->id;

        return $nota;
    }

    /**
     * Regla (8): devolver TODA una venta en dólares deja la nota de crédito en la cuenta en dólares
     * por 100 USD (no por 120000), imputada a su débito (queda `pagado`), con `moneda_id = 2`; el
     * saldo en dólares y `saldo_dolares` quedan en 0; la cuenta en pesos no se toca.
     *
     * @test
     */
    public function la_devolucion_total_de_una_venta_en_dolares_cae_en_la_cuenta_en_dolares_por_el_monto_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 50000);
        $venta = $this->vender($cliente, self::DOLARES, 100);
        $debito = $this->debito_de($venta, $dolares);

        $nota = $this->devolver($venta, $cliente, 100);

        $this->assertEquals($dolares->id, (int) $nota->credit_account_id, 'La NC de una venta en dólares no cayó en la cuenta en dólares.');
        $this->assertEquals(self::DOLARES, (int) $nota->moneda_id);
        $this->assertMonto(100, $nota->haber, 'El haber de la NC tiene que estar en dólares.');
        $this->assertEquals('nota_credito', $nota->status);
        $this->assertEquals($venta->id, (int) $nota->sale_id);
        $this->assertEquals($debito->id, (int) $nota->to_pay_id, 'La NC no quedó dirigida al débito de la venta devuelta.');
        $this->assertMonto(0, $nota->fresh()->saldo);

        $debito = $debito->fresh();
        $this->assertEquals('pagado', $debito->status);
        $this->assertMonto(100, $debito->pagandose);

        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertMonto(0, $this->saldos_del_cliente($cliente)['dolares']);

        // La cuenta en pesos, con su venta sin tocar.
        $this->assertMonto(50000, $this->saldo_de($pesos));
        $this->assertMonto(50000, $this->saldos_del_cliente($cliente)['pesos']);
        $this->assertCount(0, $this->movimientos($pesos)->where('status', 'nota_credito'));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla (8): devolución PARCIAL de una venta en dólares: la NC por 30 USD deja el débito
     * `pagandose` por 30 y el saldo en dólares en 70.
     *
     * @test
     */
    public function la_devolucion_parcial_de_una_venta_en_dolares_deja_el_debito_pagandose()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);
        $pesos = $this->cuenta($cliente, self::PESOS);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $nota = $this->devolver($venta, $cliente, 30);

        $this->assertMonto(30, $nota->haber);
        $this->assertMonto(70, $nota->fresh()->saldo);

        $debito = $this->debito_de($venta, $dolares);
        $this->assertEquals('pagandose', $debito->status);
        $this->assertMonto(30, $debito->pagandose);

        $this->assertMonto(70, $this->saldo_de($dolares));
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: la devolución de una venta en PESOS no toca la cuenta en dólares, aunque el cliente
     * también deba dólares.
     *
     * @test
     */
    public function la_devolucion_de_una_venta_en_pesos_no_toca_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta_pesos = $this->vender($cliente, self::PESOS, 120000);
        $this->vender($cliente, self::DOLARES, 100);

        $nota = $this->devolver($venta_pesos, $cliente, 120000);

        $this->assertEquals($pesos->id, (int) $nota->credit_account_id);
        $this->assertEquals(self::PESOS, (int) $nota->moneda_id);
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertMonto(100, $this->saldo_de($dolares), 'La devolución en pesos movió la cuenta en dólares.');
        $this->assertCount(1, $this->movimientos($dolares), 'La cuenta en dólares recibió un movimiento de la devolución en pesos.');
        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: el SOBRANTE de una NC en dólares cae en FIFO en los débitos de la misma cuenta en
     * dólares, y nunca en los de la cuenta en pesos. Deuda: venta A en dólares 50, venta B en
     * dólares 100, venta C en pesos 60000. Devolución de 130 USD sobre B: 100 saldan B y 30 caen en A;
     * C sigue sin pagar.
     *
     * @test
     */
    public function el_sobrante_de_una_nota_de_credito_en_dolares_cae_solo_en_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta_a = $this->vender($cliente, self::DOLARES, 50);
        $venta_b = $this->vender($cliente, self::DOLARES, 100);
        $venta_c = $this->vender($cliente, self::PESOS, 60000);

        $this->devolver($venta_b, $cliente, 130);

        $this->assertEquals('pagado', $this->debito_de($venta_b, $dolares)->status);
        $this->assertEquals('pagandose', $this->debito_de($venta_a, $dolares)->status);
        $this->assertMonto(30, $this->debito_de($venta_a, $dolares)->pagandose);
        $this->assertEquals('sin_pagar', $this->debito_de($venta_c, $pesos)->status, 'El sobrante de la NC en dólares saldó una deuda en pesos.');
        $this->assertMonto(20, $this->saldo_de($dolares));
        $this->assertMonto(60000, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($dolares);
        $this->assertCuentaConsistente($pesos);
    }

    /**
     * Regla: una devolución por MÁS que el total de la venta (150 sobre una venta de 100 USD, sin
     * renglones) deja el excedente como saldo a favor en la cuenta en dólares. (`DevolucionesController`
     * solo valida cantidades cuando hay renglones que devolver; el monto libre no se acota.)
     *
     * @test
     */
    public function una_devolucion_mayor_a_la_venta_en_dolares_deja_saldo_a_favor_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);
        $pesos = $this->cuenta($cliente, self::PESOS);

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->devolver($venta, $cliente, 150);

        $this->assertEquals('pagado', $this->debito_de($venta, $dolares)->status);
        $this->assertMonto(-50, $this->saldo_de($dolares));
        $this->assertMonto(-50, $this->saldos_del_cliente($cliente)['dolares']);
        $this->assertMonto(0, $this->saldo_de($pesos));
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla (8, monto libre): una NOTA DE CRÉDITO de monto libre sobre la cuenta en dólares
     * (`POST api/current-acount/nota-credito`) deja el haber en dólares, con `moneda_id = 2`, y se
     * imputa (FIFO) al débito en dólares; la cuenta en pesos no se toca.
     *
     * @test
     */
    public function una_nota_de_credito_de_monto_libre_sobre_la_cuenta_en_dolares_baja_esa_cuenta()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 50000);
        $venta = $this->vender($cliente, self::DOLARES, 100);

        $this->avanzar_reloj_de_ventas();

        $response = $this->postJson('api/current-acount/nota-credito', [
            'credit_account_id' => $dolares->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'form'              => [
                'nota_credito' => 25,
                'description'  => 'Ajuste en dólares',
            ],
        ]);

        $response->assertStatus(201);

        $nota = CurrentAcount::find($response->json('current_acount.id'));
        $this->cobros_cc_creados_por_escenarios[] = $nota->id;

        $this->assertEquals($dolares->id, (int) $nota->credit_account_id);
        $this->assertEquals(self::DOLARES, (int) $nota->moneda_id);
        $this->assertMonto(25, $nota->haber);
        $this->assertMonto(75, $nota->fresh()->saldo);

        $debito = $this->debito_de($venta, $dolares);
        $this->assertEquals('pagandose', $debito->status);
        $this->assertMonto(25, $debito->pagandose);

        $this->assertMonto(75, $this->saldo_de($dolares));
        $this->assertMonto(50000, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($pesos);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Limitación conocida (no es un bug del cálculo): una devolución "desde cero", SIN venta de
     * origen, no lleva moneda en el request y `get_moneda_id()` cae SIEMPRE en pesos. Un cliente que
     * solo debe dólares no puede recibir una NC en dólares por ese camino: la NC entra a la cuenta en
     * pesos y le deja saldo a favor en pesos. Este test fija ese comportamiento para que un cambio
     * (agregar la moneda al request) sea una decisión y no un accidente.
     *
     * @test
     */
    public function una_devolucion_sin_venta_de_origen_siempre_cae_en_la_cuenta_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::DOLARES, 100);

        $nota = $this->devolver(null, $cliente, 40);

        $this->assertEquals($pesos->id, (int) $nota->credit_account_id);
        $this->assertMonto(-40, $this->saldo_de($pesos));
        $this->assertMonto(100, $this->saldo_de($dolares), 'La NC sin venta de origen no tendría por qué bajar la deuda en dólares.');
    }

    /**
     * Regla: eliminar una NC en dólares (`DELETE api/current-acount/client/{id}`) devuelve la deuda
     * en dólares y no toca la cuenta en pesos.
     *
     * @test
     */
    public function eliminar_una_nota_de_credito_en_dolares_devuelve_la_deuda_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $this->vender($cliente, self::PESOS, 50000);
        $venta = $this->vender($cliente, self::DOLARES, 100);

        $nota = $this->devolver($venta, $cliente, 100);
        $this->assertMonto(0, $this->saldo_de($dolares));

        $this->avanzar_reloj_de_ventas();
        $this->deleteJson('api/current-acount/client/'.$nota->id)->assertStatus(200);

        $this->assertNull(CurrentAcount::find($nota->id));
        $this->assertMonto(100, $this->saldo_de($dolares));
        $this->assertEquals('sin_pagar', $this->debito_de($venta, $dolares)->status);
        $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $nota->id)->count());
        $this->assertMonto(50000, $this->saldo_de($pesos));

        $this->assertCuentaConsistente($dolares);
        $this->assertCuentaConsistente($pesos);
    }
}
