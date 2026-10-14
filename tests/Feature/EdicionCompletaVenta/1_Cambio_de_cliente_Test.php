<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;

/**
 * Editar una venta a cuenta corriente cambiándole el CLIENTE (misión edicion-completa-ventas-presupuestos,
 * 8/10/2026, pedido de Lucas): "en el caso de cambiar el cliente en una venta que ya fue la cuenta
 * corriente, debería de sacarse ese movimiento de la cuenta corriente del cliente original y crearse el
 * nuevo movimiento en la cuenta corriente del nuevo cliente. Y obviamente chequear que el saldo del
 * cliente original quede bien luego de haber sacado esa venta."
 *
 * La API ya lo hacía desde el 23/9/2026 (`SaleHelper::updateCurrentAcountsAndCommissions()`: borra el
 * débito de la cuenta vieja, lo crea en la nueva y recalcula ENTERA la cuenta de la que salió), pero
 * ningún test lo probaba de punta a punta. Estos lo fijan, con la decisión 1 de Lucas para los cobros:
 * **el pago queda en la cuenta del cliente original** (se imputa a otra deuda suya o queda a favor) y la
 * venta entra en la cuenta del cliente nuevo como pendiente.
 *
 * Cada caso verifica el saldo de los DOS clientes de forma independiente (ver
 * `EdicionCompletaVentaTestCase::assert_saldo_independiente()`), con el valor esperado escrito a mano.
 *
 * @group edicion-completa-venta
 * @group cuenta-corriente
 */
class Cambio_de_cliente_Test extends EdicionCompletaVentaTestCase
{
    /**
     * Caso 1. Venta de A sin pagos, en el MEDIO de la cadena de A (hay una venta antes y otra
     * después), pasa a B, que ya tenía una venta POSTERIOR a ella: el débito desaparece de A y aparece
     * en B con el mismo total, la venta posterior de A queda con el saldo recalculado y la de B
     * también (el débito movido conserva la fecha de la venta, así que entra ANTES en la cadena de B).
     *
     * @test
     */
    public function una_venta_sin_pagos_pasa_de_la_cuenta_de_a_a_la_de_b_y_los_dos_saldos_quedan_bien()
    {
        $cliente_a = $this->cliente_nuevo('zz Edicion A');
        $cliente_b = $this->cliente_nuevo('zz Edicion B');

        $this->vender($cliente_a, self::PESOS, 50000);
        $venta = $this->vender($cliente_a, self::PESOS, 80000);
        $posterior_de_a = $this->vender($cliente_a, self::PESOS, 30000);
        $posterior_de_b = $this->vender($cliente_b, self::PESOS, 20000);

        $this->assert_saldo_independiente($cliente_a, self::PESOS, 160000, 'A antes de editar');
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 20000, 'B antes de editar');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertEquals($cliente_b->id, (int) $venta->client_id, 'La venta no quedó con el cliente nuevo.');
        $this->assertMonto(80000, $venta->total);

        // Un solo débito de la venta, en la cuenta en pesos de B, por el mismo total.
        $debitos = $this->debitos_de_la_venta($venta);
        $this->assertCount(1, $debitos, 'La venta tiene que tener UN solo débito, en la cuenta del cliente nuevo.');
        $this->assertEquals($this->cuenta($cliente_b, self::PESOS)->id, (int) $debitos[0]->credit_account_id);
        $this->assertEquals($cliente_b->id, (int) $debitos[0]->client_id);
        $this->assertMonto(80000, $debitos[0]->debe);
        $this->assertEquals('sin_pagar', $debitos[0]->status);

        // A: 50000 + 30000. La venta posterior de A se recalculó (antes acumulaba 160000).
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 80000, 'A (cliente original)');
        $this->assertMonto(80000, $this->debito_de($posterior_de_a, $this->cuenta($cliente_a, self::PESOS))->saldo, 'La venta posterior de A no quedó con el saldo recalculado.');

        // B: 80000 (entra antes) + 20000.
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 100000, 'B (cliente nuevo)');
        $this->assertMonto(100000, $this->debito_de($posterior_de_b, $this->cuenta($cliente_b, self::PESOS))->saldo, 'La venta de B posterior al débito movido no se recalculó.');

        // Las cuentas en dólares de los dos, intactas.
        $this->assert_saldo_independiente($cliente_a, self::DOLARES, 0, 'A en dólares');
        $this->assert_saldo_independiente($cliente_b, self::DOLARES, 0, 'B en dólares');
    }

    /**
     * Caso 2. Venta de A PAGADA EN PARTE con un pago común (imputado por orden) y otro DIRIGIDO a ella
     * (`to_pay_id`), con otra venta pendiente de A: al pasar a B, los dos pagos quedan en A y se
     * imputan a la otra venta de A; el pago dirigido queda liberado (`to_pay_id` null); la venta entra
     * en B sin pagar.
     *
     * @test
     */
    public function una_venta_pagada_en_parte_deja_los_pagos_en_a_imputados_a_su_otra_deuda_y_entra_en_b_sin_pagar()
    {
        $cliente_a = $this->cliente_nuevo('zz Edicion A');
        $cliente_b = $this->cliente_nuevo('zz Edicion B');
        $pesos_a = $this->cuenta($cliente_a, self::PESOS);

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        // Pago común: por orden va a la única deuda, la venta.
        $pago_comun = $this->pagar($cliente_a, $pesos_a, [$this->fila_de_pago(self::PESOS, self::PESOS, 20000, $this->caja_pesos)]);

        $otra_de_a = $this->vender($cliente_a, self::PESOS, 50000);

        // Pago dirigido a la venta (no a la otra, que también está pendiente).
        $pago_dirigido = $this->pagar(
            $cliente_a,
            $pesos_a,
            [$this->fila_de_pago(self::PESOS, self::PESOS, 10000, $this->caja_pesos)],
            ['to_pay' => ['id' => $this->debito_de($venta, $pesos_a)->id]]
        );

        $debito_viejo = $this->debito_de($venta, $pesos_a);
        $this->assertEquals('pagandose', $debito_viejo->status, 'Precondición: la venta tiene que estar pagándose.');
        $this->assertMonto(30000, $debito_viejo->pagandose);
        $this->assertEquals($debito_viejo->id, (int) $pago_dirigido->fresh()->to_pay_id, 'Precondición: el pago tiene que estar dirigido a la venta.');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        // Los pagos siguen en A, sin dirección, imputados a la otra venta de A.
        $this->assertNotNull(CurrentAcount::find($pago_comun->id), 'El pago común desapareció.');
        $this->assertNotNull(CurrentAcount::find($pago_dirigido->id), 'El pago dirigido desapareció.');
        $this->assertEquals($pesos_a->id, (int) CurrentAcount::find($pago_comun->id)->credit_account_id);
        $this->assertEquals($pesos_a->id, (int) CurrentAcount::find($pago_dirigido->id)->credit_account_id);
        $this->assertNull(CurrentAcount::find($pago_dirigido->id)->to_pay_id, 'El pago dirigido a la venta que se fue tiene que quedar liberado.');

        $debito_otra = $this->debito_de($otra_de_a, $pesos_a);
        $this->assertEquals('pagandose', $debito_otra->status, 'Los cobros de A tienen que imputarse a su otra deuda.');
        $this->assertMonto(30000, $debito_otra->pagandose);
        $this->assertMonto(30000, DB::table('pagado_por')->where('debe_id', $debito_otra->id)->sum('pagado'));
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $debito_viejo->id)->count(), 'Quedaron imputaciones colgando del débito borrado.');

        // A: 50000 − 20000 − 10000.
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 20000, 'A (cliente original)');

        // B: la venta entera, pendiente.
        $debito_b = $this->debito_de($venta, $this->cuenta($cliente_b, self::PESOS));
        $this->assertNotNull($debito_b, 'La venta no entró en la cuenta de B.');
        $this->assertEquals('sin_pagar', $debito_b->status);
        $this->assertMonto(0, $debito_b->pagandose);
        $this->assertCount(1, $this->debitos_de_la_venta($venta));
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 80000, 'B (cliente nuevo)');
    }

    /**
     * Caso 3. Venta de A PAGADA ENTERA, sin otra deuda en A: al pasar a B, A queda con saldo a favor
     * igual al pago y B debe el total.
     *
     * @test
     */
    public function una_venta_pagada_entera_deja_a_con_saldo_a_favor_y_b_debe_el_total()
    {
        $cliente_a = $this->cliente_nuevo('zz Edicion A');
        $cliente_b = $this->cliente_nuevo('zz Edicion B');
        $pesos_a = $this->cuenta($cliente_a, self::PESOS);

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $pago = $this->pagar($cliente_a, $pesos_a, [$this->fila_de_pago(self::PESOS, self::PESOS, 80000, $this->caja_pesos)]);

        $this->assertEquals('pagado', $this->debito_de($venta, $pesos_a)->status, 'Precondición: la venta tiene que estar pagada.');
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 0, 'A antes de editar');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertNotNull(CurrentAcount::find($pago->id), 'El pago desapareció.');
        $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $pago->id)->count(), 'El pago quedó imputado a un débito que ya no está en A.');

        $this->assert_saldo_independiente($cliente_a, self::PESOS, -80000, 'A (cliente original, saldo a favor)');

        $debito_b = $this->debito_de($venta, $this->cuenta($cliente_b, self::PESOS));
        $this->assertNotNull($debito_b);
        $this->assertEquals('sin_pagar', $debito_b->status);
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 80000, 'B (cliente nuevo)');
    }

    /**
     * Caso 4. B tenía SALDO A FAVOR: la venta entra en B y queda imputada por ese saldo (acá en parte:
     * `pagandose`), y A queda sin la deuda.
     *
     * @test
     */
    public function si_b_tenia_saldo_a_favor_la_venta_entra_imputada_por_ese_saldo()
    {
        $cliente_a = $this->cliente_nuevo('zz Edicion A');
        $cliente_b = $this->cliente_nuevo('zz Edicion B');
        $pesos_b = $this->cuenta($cliente_b, self::PESOS);

        // B paga por adelantado, sin deuda: queda 50000 a favor.
        $anticipo = $this->pagar($cliente_b, $pesos_b, [$this->fila_de_pago(self::PESOS, self::PESOS, 50000, $this->caja_pesos)]);
        $this->assert_saldo_independiente($cliente_b, self::PESOS, -50000, 'B antes de editar');

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $debito_b = $this->debito_de($venta, $pesos_b);
        $this->assertNotNull($debito_b, 'La venta no entró en la cuenta de B.');
        $this->assertEquals('pagandose', $debito_b->status, 'El saldo a favor de B tiene que imputarse a la venta que entra.');
        $this->assertMonto(50000, $debito_b->pagandose);
        $this->assertMonto(50000, DB::table('pagado_por')->where('debe_id', $debito_b->id)->where('haber_id', $anticipo->id)->sum('pagado'));

        $this->assert_saldo_independiente($cliente_b, self::PESOS, 30000, 'B (cliente nuevo)');
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 0, 'A (cliente original)');
        $this->assertCount(0, $this->movimientos($this->cuenta($cliente_a, self::PESOS)), 'A tiene que quedar sin movimientos.');
    }
}
