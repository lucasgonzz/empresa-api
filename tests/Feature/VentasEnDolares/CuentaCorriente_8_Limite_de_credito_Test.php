<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;

/**
 * LÍMITE DE CRÉDITO POR MONEDA (`LimiteCreditoHelper`) en el comercio 2R: cada cuenta corriente
 * (`credit_accounts`, una por moneda) tiene su propio `limite_credito`, en la moneda de la cuenta.
 *
 * Reglas:
 *  - Una venta se compara contra la cuenta de SU moneda: saldo de esa cuenta + total de la venta (en
 *    la moneda de la venta) contra el límite de esa cuenta. Nunca se compara pesos contra dólares ni
 *    se convierte.
 *  - Un cliente con tope en pesos y sin tope en dólares: una venta en dólares no se controla; una
 *    venta en pesos sí (y al revés).
 *  - El 422 nombra la moneda (`moneda_name`, `moneda_id`) y trae los números en esa moneda.
 *  - Un pago (incluso cruzado) baja el saldo de la cuenta pagada y libera límite en ESA moneda.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 * @group limite_credito
 */
class CuentaCorriente_8_Limite_de_credito_Test extends CuentaCorriente_Base
{
    /**
     * Fija el `limite_credito` de la cuenta del cliente en la moneda dada.
     *
     * @param \App\Models\Client $cliente
     * @param int $moneda
     * @param float|null $limite
     * @return \App\Models\CreditAccount
     */
    protected function fijar_limite($cliente, $moneda, $limite)
    {
        $cuenta = $this->cuenta($cliente, $moneda);
        $cuenta->limite_credito = $limite;
        $cuenta->save();

        return $cuenta->fresh();
    }

    /**
     * Postea una venta a cuenta corriente SIN exigir el 201 (para poder mirar el 422).
     *
     * @param \App\Models\Client $cliente
     * @param int $moneda
     * @param float $total
     * @param array $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function intentar_vender($cliente, $moneda, $total, $overrides = [])
    {
        $this->avanzar_reloj_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_de_venta($cliente->id, $moneda, $total, $overrides));

        if ($response->getStatusCode() == 201) {
            $this->ventas_creadas_por_escenarios[] = $response->json('model.id');
        }

        return $response;
    }

    /**
     * Regla (9): con tope en PESOS y sin tope en dólares, una venta en dólares enorme (500 USD, que
     * al cambio son 600000 pesos, seis veces el tope) NO se controla; una venta en pesos que supera
     * el tope, sí: 422 nombrando la moneda Peso y con los números en pesos.
     *
     * @test
     */
    public function con_tope_en_pesos_y_sin_tope_en_dolares_solo_se_controlan_las_ventas_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->fijar_limite($cliente, self::PESOS, 100000);
        $dolares = $this->fijar_limite($cliente, self::DOLARES, null);

        $this->intentar_vender($cliente, self::DOLARES, 500)->assertStatus(201);

        $this->assertMonto(500, $this->saldo_de($dolares));
        $this->assertMonto(0, $this->saldo_de($pesos));

        $response = $this->intentar_vender($cliente, self::PESOS, 150000);

        $response->assertStatus(422);
        $this->assertTrue($response->json('error_limite_credito'));
        $this->assertEquals(self::PESOS, $response->json('limite_credito.moneda_id'));
        $this->assertEquals('Peso', $response->json('limite_credito.moneda_name'));
        $this->assertEquals($pesos->id, $response->json('limite_credito.credit_account_id'));
        $this->assertMonto(0, $response->json('limite_credito.saldo_actual'), 'El saldo actual tiene que ser el de la cuenta en pesos, no el de dólares.');
        $this->assertMonto(150000, $response->json('limite_credito.total_venta'));
        $this->assertMonto(100000, $response->json('limite_credito.limite_credito'));
        $this->assertStringContainsString('Peso', $response->json('message'));

        // La venta rechazada no dejó nada.
        $this->assertCount(0, $this->debitos($pesos));
        $this->assertMonto(0, $this->saldo_de($pesos));
    }

    /**
     * Regla (9, espejo): con tope en DÓLARES y sin tope en pesos, una venta en pesos enorme no se
     * controla; una venta en dólares que supera el tope, sí: 422 nombrando la moneda Dólar, con los
     * números en dólares.
     *
     * @test
     */
    public function con_tope_en_dolares_y_sin_tope_en_pesos_solo_se_controlan_las_ventas_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->fijar_limite($cliente, self::PESOS, null);
        $dolares = $this->fijar_limite($cliente, self::DOLARES, 100);

        $this->intentar_vender($cliente, self::PESOS, 5000000)->assertStatus(201);

        $response = $this->intentar_vender($cliente, self::DOLARES, 150);

        $response->assertStatus(422);
        $this->assertEquals(self::DOLARES, $response->json('limite_credito.moneda_id'));
        $this->assertEquals('Dolar', $response->json('limite_credito.moneda_name'));
        $this->assertEquals($dolares->id, $response->json('limite_credito.credit_account_id'));
        $this->assertMonto(0, $response->json('limite_credito.saldo_actual'), 'El saldo actual tiene que ser el de la cuenta en dólares, no los 5 millones de pesos.');
        $this->assertMonto(150, $response->json('limite_credito.total_venta'));
        $this->assertMonto(100, $response->json('limite_credito.limite_credito'));
        $this->assertMonto(150, $response->json('limite_credito.saldo_resultante'));

        $this->assertMonto(5000000, $this->saldo_de($pesos));
        $this->assertMonto(0, $this->saldo_de($dolares));
        $this->assertCount(0, $this->debitos($dolares));
    }

    /**
     * Regla: el tope en dólares acumula SOLO las ventas en dólares: 80 USD entran, y otros 30 USD
     * superarían el tope de 100 (saldo actual 80, resultante 110), aunque el cliente tenga mucha deuda
     * en pesos (que no cuenta).
     *
     * @test
     */
    public function el_tope_en_dolares_acumula_solo_las_ventas_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $this->fijar_limite($cliente, self::DOLARES, 100);
        $this->fijar_limite($cliente, self::PESOS, null);

        $this->intentar_vender($cliente, self::PESOS, 900000)->assertStatus(201);
        $this->intentar_vender($cliente, self::DOLARES, 80)->assertStatus(201);

        $response = $this->intentar_vender($cliente, self::DOLARES, 30);

        $response->assertStatus(422);
        $this->assertMonto(80, $response->json('limite_credito.saldo_actual'));
        $this->assertMonto(30, $response->json('limite_credito.total_venta'));
        $this->assertMonto(110, $response->json('limite_credito.saldo_resultante'));
        $this->assertMonto(10, $response->json('limite_credito.excedente'));

        // Justo hasta el tope entra (tolerancia de un centavo).
        $this->intentar_vender($cliente, self::DOLARES, 20)->assertStatus(201);
    }

    /**
     * Regla: un pago CRUZADO libera límite en la moneda de la cuenta pagada. Tope en pesos de
     * 100000 con 90000 de deuda: no entran 60000 más; después de pagar 50 USD (= 60000 ARS) la deuda
     * es 30000 y esos mismos 60000 sí entran.
     *
     * @test
     */
    public function un_pago_cruzado_en_dolares_libera_el_tope_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->fijar_limite($cliente, self::PESOS, 100000);

        $this->intentar_vender($cliente, self::PESOS, 90000)->assertStatus(201);
        $this->intentar_vender($cliente, self::PESOS, 60000)->assertStatus(422);

        $this->pagar($cliente, $pesos, [
            $this->fila_de_pago(self::PESOS, self::DOLARES, 50, $this->caja_dolares, 1200),
        ]);

        $this->assertMonto(30000, $this->saldo_de($pesos));

        $this->intentar_vender($cliente, self::PESOS, 60000)->assertStatus(201);
        $this->assertMonto(90000, $this->saldo_de($pesos));
    }

    /**
     * Regla: la EDICIÓN de una venta en dólares se controla contra el tope en dólares (con el
     * movimiento actual descontado del saldo): 50 USD entran con tope 100; subirla a 150 da 422 y el
     * rollback deja el movimiento original y el total original; bajarla a 90 entra.
     *
     * @test
     */
    public function la_edicion_de_una_venta_en_dolares_se_controla_contra_el_tope_en_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->fijar_limite($cliente, self::PESOS, 1);
        $dolares = $this->fijar_limite($cliente, self::DOLARES, 100);

        $this->assertNotNull($pesos);

        $venta = $this->vender($cliente, self::DOLARES, 50);
        $movimiento = $this->debito_de($venta, $dolares);

        $this->avanzar_reloj_de_ventas();

        $payload = [
            'client_id'                  => $venta->client_id,
            'save_current_acount'        => $venta->save_current_acount,
            'omitir_en_cuenta_corriente' => $venta->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => 150,
            'total'                      => 150,
            'items'                      => [],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ];

        $response = $this->putJson('api/sale/'.$venta->id, $payload);

        $response->assertStatus(422);
        $this->assertEquals(self::DOLARES, $response->json('limite_credito.moneda_id'));
        $this->assertMonto(0, $response->json('limite_credito.saldo_actual'), 'El saldo base de una edición no incluye el movimiento de la venta que se edita.');
        $this->assertMonto(150, $response->json('limite_credito.saldo_resultante'));

        $this->assertEquals($movimiento->id, $this->debito_de($venta, $dolares)->id, 'El rechazo no restauró el movimiento original.');
        $this->assertMonto(50, Sale::find($venta->id)->total);
        $this->assertMonto(50, $this->saldo_de($dolares));

        // Bajarla sí entra.
        $payload['total'] = 90;
        $payload['sub_total'] = 90;
        $this->avanzar_reloj_de_ventas();
        $this->putJson('api/sale/'.$venta->id, $payload)->assertStatus(200);
        $this->assertMonto(90, $this->saldo_de($dolares));
    }

    /**
     * Regla: una venta con `moneda_id = 0` (dato residual de instalaciones viejas) se guarda en
     * la cuenta en PESOS: `CurrentAcountFromSaleHelper` trata el 0 como 1. Entonces el tope de la
     * cuenta en pesos tiene que aplicarle. `LimiteCreditoHelper::validar_venta_nueva()` usa
     * `(int) $request->moneda_id` sin normalizar el 0, busca una cuenta de moneda 0 (que no existe) y
     * la venta esquiva el tope.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_venta_con_moneda_cero_no_esquiva_el_tope_de_la_cuenta_en_pesos_donde_se_guarda()
    {
        $cliente = $this->cliente_nuevo();
        $pesos = $this->fijar_limite($cliente, self::PESOS, 1000);

        $response = $this->intentar_vender($cliente, 0, 999999, ['moneda_id' => 0, 'valor_dolar' => null]);

        if ($response->getStatusCode() == 201) {

            $this->assertMonto(
                0,
                $this->saldo_de($pesos),
                'Una venta con moneda_id = 0 de 999.999 se guardó (201) en la cuenta en pesos (saldo '.$this->saldo_de($pesos).') con un tope de 1.000: el límite no se controló porque buscó la cuenta de moneda 0.'
            );
        }

        $response->assertStatus(422);
    }

    /**
     * Regla: la venta sin `moneda_id` en el payload se controla contra el tope en pesos (default 1
     * en el helper y en `store()`).
     *
     * @test
     */
    public function una_venta_sin_moneda_se_controla_contra_el_tope_en_pesos()
    {
        $cliente = $this->cliente_nuevo();
        $this->fijar_limite($cliente, self::PESOS, 1000);
        $this->fijar_limite($cliente, self::DOLARES, null);

        $payload = $this->payload_de_venta($cliente->id, self::PESOS, 5000);
        unset($payload['moneda_id'], $payload['valor_dolar']);

        $this->avanzar_reloj_de_ventas();

        $this->postJson('api/sale', $payload)->assertStatus(422);
    }

    /**
     * Regla: sin tope en ninguna moneda (`limite_credito` null) el comportamiento es el de siempre:
     * las dos ventas entran, sin importar el monto ni la moneda.
     *
     * @test
     */
    public function sin_tope_en_ninguna_moneda_las_ventas_entran_siempre()
    {
        $cliente = $this->cliente_nuevo();
        $this->fijar_limite($cliente, self::PESOS, null);
        $this->fijar_limite($cliente, self::DOLARES, null);

        $this->intentar_vender($cliente, self::PESOS, 99999999)->assertStatus(201);
        $this->intentar_vender($cliente, self::DOLARES, 99999)->assertStatus(201);

        $this->assertEquals(1, CurrentAcount::where('client_id', $cliente->id)->where('credit_account_id', $this->cuenta($cliente, self::PESOS)->id)->count());
        $this->assertEquals(1, CurrentAcount::where('client_id', $cliente->id)->where('credit_account_id', $this->cuenta($cliente, self::DOLARES)->id)->count());
    }
}
