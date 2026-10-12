<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Models\Sale;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;

/**
 * Editar la MONEDA y la COTIZACIÓN del dólar de una venta ya guardada (misión
 * edicion-completa-ventas-presupuestos, 8/10/2026). Pedido de Lucas: "que se pueda editar la
 * cotización del dólar que se utilizó en la venta [...] y que se pueda cambiar la moneda también".
 *
 * El contrato del `PUT api/sale/{id}` (ver `CotizacionDeVentaHelper::resolver_moneda_y_cotizacion_de_update()`):
 *  - 🔴 LA SEÑAL ES `moneda_id`, NO `valor_dolar`. La SPA manda `valor_dolar` en todos los PUT desde
 *    hace meses (también la vieja, con el campo bloqueado, y con el `owner.dollar` si la venta no tenía
 *    cotización). Sin `moneda_id` en el request, ni la moneda ni la cotización se tocan: es la SPA
 *    vieja y cualquier otro llamador, idéntico a antes de esta misión.
 *  - Con `moneda_id`: la moneda queda la del request; la cotización, la del request si es válida
 *    (> 0); null, vacío o 0 preservan la guardada.
 *  - 422 `sin_cotizacion_dolar` si la venta PASA a dólares sin cotización válida (ni en el request ni
 *    guardada), o si el request trae una cotización inválida (texto, negativa) y la venta queda en
 *    dólares. Nunca se rechaza editar una venta que YA estaba en dólares sin cotización.
 *  - Decisión 2 de Lucas: cambiar solo la cotización no toca los renglones; cambiar la moneda llega
 *    con los renglones ya convertidos por la SPA (`reexpresar_comprobante.js`, se convierte lo
 *    guardado, no se recalcula del catálogo).
 *  - La cuenta corriente está partida por moneda: cambiar la moneda de una venta a cuenta corriente
 *    mueve su débito a la cuenta de la otra moneda del mismo cliente (o del cliente nuevo).
 *
 * @group edicion-completa-venta
 * @group ventas-en-dolares
 */
class Cambio_de_moneda_y_cotizacion_Test extends EdicionCompletaVentaTestCase
{
    /**
     * Caso 5. Venta a cuenta corriente en PESOS pasada a DÓLARES con una cotización nueva (1250): sale
     * de la cuenta en pesos de A y entra en la de dólares de A con el total nuevo (120000 / 1250 = 96);
     * `moneda_id` y `valor_dolar` quedan guardados; los renglones con el precio y el costo convertidos
     * que mandó la SPA; los saldos de las dos cuentas, correctos.
     *
     * @test
     */
    public function pasar_una_venta_a_cuenta_corriente_de_pesos_a_dolares_mueve_el_debito_a_la_cuenta_en_dolares()
    {
        $cliente = $this->cliente_nuevo('zz Moneda A');

        // El renglón viaja con el costo del artículo (2000 en pesos), como lo manda la SPA, para que
        // la venta nazca con costo y se pueda ver que la conversión llega al pivot.
        $articulo = $this->articulo(\Database\Seeders\testing\TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta = $this->vender($cliente, self::PESOS, 120000, [
            'items' => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => 120000,
                'amount'       => 1,
                'cost'         => 2000,
                'costo_real'   => 2000,
            ]],
        ]);
        $posterior = $this->vender($cliente, self::PESOS, 50000);

        $this->assertMonto(2000, DB::table('article_sale')->where('sale_id', $venta->id)->value('cost'), 'Precondición: el renglón nace con el costo en pesos.');

        $this->assert_saldo_independiente($cliente, self::PESOS, 170000, 'Pesos antes de editar');

        $items = $this->reexpresar($this->items_de($venta), 1 / 1250);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1250,
            'items'       => $items,
            'sub_total'   => 96,
            'total'       => 96,
        ]));

        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id, 'La moneda de la venta no se guardó.');
        $this->assertMonto(1250, $venta->valor_dolar, 'La cotización nueva no se guardó.');
        $this->assertMonto(96, $venta->total);

        $renglon = DB::table('article_sale')->where('sale_id', $venta->id)->first();
        $this->assertMonto(96, $renglon->price, 'El precio del renglón tiene que ser el convertido que mandó la SPA.');
        $this->assertMonto(1.6, $renglon->cost, 'El costo del renglón tiene que ser el convertido que mandó la SPA.');

        // El débito se mudó de cuenta, no se duplicó.
        $debitos = $this->debitos_de_la_venta($venta);
        $this->assertCount(1, $debitos);
        $this->assertEquals($this->cuenta($cliente, self::DOLARES)->id, (int) $debitos[0]->credit_account_id, 'El débito no pasó a la cuenta en dólares.');
        $this->assertMonto(96, $debitos[0]->debe);

        $this->assert_saldo_independiente($cliente, self::PESOS, 50000, 'Cuenta en pesos (de la que salió)');
        $this->assertMonto(50000, $this->debito_de($posterior, $this->cuenta($cliente, self::PESOS))->saldo, 'La venta posterior en pesos no se recalculó.');
        $this->assert_saldo_independiente($cliente, self::DOLARES, 96, 'Cuenta en dólares (a la que entró)');
    }

    /**
     * Caso 5 con COBROS IMPUTADOS (decisión 1 de Lucas, variante de moneda; pedido de la revisión
     * independiente, 8/10/2026). La venta en pesos de A estaba pagándose con un cobro de 30000 y A tiene
     * otra deuda en pesos: al pasarla a dólares, el cobro QUEDA en la cuenta en pesos de A y se imputa a
     * esa otra deuda; la venta entra en la cuenta en dólares de A como pendiente.
     *
     * @test
     */
    public function pasar_a_dolares_una_venta_con_cobros_deja_el_cobro_en_la_cuenta_en_pesos()
    {
        $cliente = $this->cliente_nuevo('zz Moneda con cobros');
        $pesos = $this->cuenta($cliente, self::PESOS);
        $dolares = $this->cuenta($cliente, self::DOLARES);

        $venta = $this->vender($cliente, self::PESOS, 120000);

        $cobro = $this->pagar($cliente, $pesos, [$this->fila_de_pago(self::PESOS, self::PESOS, 30000, $this->caja_pesos)]);

        $otra_deuda = $this->vender($cliente, self::PESOS, 50000);

        $this->assertEquals('pagandose', $this->debito_de($venta, $pesos)->status, 'Precondición: la venta tiene que estar pagándose.');
        $debito_viejo_id = $this->debito_de($venta, $pesos)->id;

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1200,
            'items'       => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'   => 100,
            'total'       => 100,
        ]));

        // El cobro sigue en la cuenta en pesos, imputado a la otra deuda.
        $this->assertEquals($pesos->id, (int) \App\Models\CurrentAcount::find($cobro->id)->credit_account_id, 'El cobro se fue de la cuenta en pesos.');
        $debito_otra = $this->debito_de($otra_deuda, $pesos);
        $this->assertEquals('pagandose', $debito_otra->status, 'El cobro tiene que imputarse a la otra deuda en pesos.');
        $this->assertMonto(30000, $debito_otra->pagandose);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $debito_viejo_id)->count(), 'Quedaron imputaciones del débito que se mudó.');

        // La venta entra pendiente en dólares.
        $debito_usd = $this->debito_de($venta, $dolares);
        $this->assertNotNull($debito_usd, 'La venta no entró en la cuenta en dólares.');
        $this->assertEquals('sin_pagar', $debito_usd->status);
        $this->assertMonto(100, $debito_usd->debe);
        $this->assertCount(1, $this->debitos_de_la_venta($venta));

        // Pesos: 50000 − 30000. Dólares: 100.
        $this->assert_saldo_independiente($cliente, self::PESOS, 20000, 'Cuenta en pesos (de la que salió)');
        $this->assert_saldo_independiente($cliente, self::DOLARES, 100, 'Cuenta en dólares (a la que entró)');
    }

    /**
     * Caso 6. Cliente Y moneda a la vez: la venta en pesos de A pasa a B en dólares. Sale de la cuenta
     * en pesos de A y entra en la de dólares de B; las otras dos cuentas no se tocan.
     *
     * @test
     */
    public function cambiar_cliente_y_moneda_a_la_vez_mueve_el_debito_a_la_cuenta_en_dolares_de_b()
    {
        $cliente_a = $this->cliente_nuevo('zz Moneda A');
        $cliente_b = $this->cliente_nuevo('zz Moneda B');

        $venta = $this->vender($cliente_a, self::PESOS, 120000);
        $this->vender($cliente_b, self::PESOS, 7000);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'client_id'   => $cliente_b->id,
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1200,
            'items'       => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'   => 100,
            'total'       => 100,
        ]));

        $this->assertEquals($cliente_b->id, (int) $venta->client_id);
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);

        $debitos = $this->debitos_de_la_venta($venta);
        $this->assertCount(1, $debitos);
        $this->assertEquals($this->cuenta($cliente_b, self::DOLARES)->id, (int) $debitos[0]->credit_account_id);
        $this->assertMonto(100, $debitos[0]->debe);

        $this->assert_saldo_independiente($cliente_a, self::PESOS, 0, 'A en pesos (de donde salió)');
        $this->assert_saldo_independiente($cliente_a, self::DOLARES, 0, 'A en dólares');
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 7000, 'B en pesos (no se toca)');
        $this->assert_saldo_independiente($cliente_b, self::DOLARES, 100, 'B en dólares (a donde entró)');
    }

    /**
     * Caso 8 (decisión 2 de Lucas). Cambiar SOLO la cotización de una venta en dólares: se guarda el
     * `valor_dolar` nuevo y nada más; el total, los precios y los costos de los renglones quedan como
     * estaban, y el débito de la cuenta en dólares también.
     *
     * @test
     */
    public function cambiar_solo_la_cotizacion_guarda_el_valor_dolar_y_no_toca_renglones_ni_total()
    {
        $cliente = $this->cliente_nuevo('zz Cotizacion');

        // Artículo con costo en pesos (2000): la venta en dólares lo guarda convertido con 1200
        // (1,67). Si la edición re-cotizara, con 1350 quedaría en 1,48.
        $articulo = $this->articulo(\Database\Seeders\testing\TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $venta = $this->vender($cliente, self::DOLARES, 100, [
            'items' => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => 100,
                'amount'       => 1,
                'cost'         => 2000,
                'costo_real'   => 2000,
            ]],
        ]);

        $this->assertMonto(2000 / 1200, DB::table('article_sale')->where('sale_id', $venta->id)->value('cost'), 'Precondición: el renglón nace con el costo convertido con la cotización de la venta.');

        $renglones_antes = DB::table('article_sale')->where('sale_id', $venta->id)->get(['article_id', 'price', 'cost', 'amount'])->map(function ($f) { return (array) $f; })->all();

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => 1350]));

        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);
        $this->assertMonto(1350, $venta->valor_dolar, 'La cotización nueva no se guardó.');
        $this->assertMonto(100, $venta->total, 'Cambiar la cotización no puede cambiar el total.');

        $renglones_despues = DB::table('article_sale')->where('sale_id', $venta->id)->get(['article_id', 'price', 'cost', 'amount'])->map(function ($f) { return (array) $f; })->all();
        $this->assertEquals($renglones_antes, $renglones_despues, 'Cambiar la cotización no puede tocar precio, costo ni cantidad de los renglones.');

        $this->assertMonto(100, $this->debito_de($venta, $this->cuenta($cliente, self::DOLARES))->debe);
        $this->assert_saldo_independiente($cliente, self::DOLARES, 100, 'Dólares');
        $this->assert_saldo_independiente($cliente, self::PESOS, 0, 'Pesos');
    }

    /**
     * Caso 9a (compatibilidad, SPA vieja). PUT SIN `moneda_id` y con un `valor_dolar` distinto: ni la
     * moneda ni la cotización cambian, en una venta en dólares y en una en pesos que no tenía
     * cotización (la SPA vieja manda el `owner.dollar` que `Moneda.vue` cargó al abrirla).
     *
     * @test
     */
    public function un_put_sin_moneda_id_no_toca_ni_la_moneda_ni_la_cotizacion()
    {
        $cliente = $this->cliente_nuevo('zz SPA vieja');

        $en_dolares = $this->vender($cliente, self::DOLARES, 100);

        $en_dolares = $this->editar_ok($en_dolares, $this->sin_moneda($this->payload_de_edicion($en_dolares, ['valor_dolar' => 1500])));

        $this->assertEquals(self::DOLARES, (int) $en_dolares->moneda_id);
        $this->assertMonto(self::DOLAR, $en_dolares->valor_dolar, 'Sin moneda_id la cotización guardada no se puede pisar.');

        $en_pesos = $this->vender($cliente, self::PESOS, 30000);
        DB::table('sales')->where('id', $en_pesos->id)->update(['valor_dolar' => null]);

        $en_pesos = $this->editar_ok($en_pesos, $this->sin_moneda($this->payload_de_edicion($en_pesos, ['valor_dolar' => 1000])));

        $this->assertEquals(self::PESOS, (int) $en_pesos->moneda_id);
        $this->assertNull($en_pesos->valor_dolar, 'Sin moneda_id una venta sin cotización tiene que seguir sin cotización.');

        $this->assert_saldo_independiente($cliente, self::DOLARES, 100, 'Dólares');
        $this->assert_saldo_independiente($cliente, self::PESOS, 30000, 'Pesos');
    }

    /**
     * Caso 9b. PUT CON `moneda_id` y la cotización en null o en 0: se preserva la guardada (no se
     * escribe un cero ni un null encima de una cotización buena).
     *
     * @test
     */
    public function con_moneda_id_una_cotizacion_null_o_cero_preserva_la_guardada()
    {
        $cliente = $this->cliente_nuevo('zz Cotizacion preservada');

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => null]));
        $this->assertMonto(self::DOLAR, $venta->valor_dolar, 'Una cotización null en el PUT no puede borrar la guardada.');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => 0]));
        $this->assertMonto(self::DOLAR, $venta->valor_dolar, 'Una cotización 0 en el PUT no puede pisar la guardada.');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => '']));
        $this->assertMonto(self::DOLAR, $venta->valor_dolar, 'Una cotización vacía en el PUT no puede pisar la guardada.');

        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);
    }

    /**
     * Caso 9c. Una venta que YA estaba en dólares con `valor_dolar` NULL (2R tiene 44 así) se edita sin
     * 422, con y sin `moneda_id`, y sigue sin cotización.
     *
     * @test
     */
    public function una_venta_en_dolares_sin_cotizacion_se_edita_sin_422()
    {
        $cliente = $this->cliente_nuevo('zz Dolares sin cotizacion');

        $venta = $this->vender($cliente, self::DOLARES, 100);
        DB::table('sales')->where('id', $venta->id)->update(['valor_dolar' => null]);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => null]));
        $this->assertEquals(self::DOLARES, (int) $venta->moneda_id);
        $this->assertNull($venta->valor_dolar);

        $venta = $this->editar_ok($venta, $this->sin_moneda($this->payload_de_edicion($venta, ['valor_dolar' => null])));
        $this->assertNull($venta->valor_dolar);

        $this->assert_saldo_independiente($cliente, self::DOLARES, 100, 'Dólares');
    }

    /**
     * Contrato con la SPA nueva (ajuste del 8/10/2026): manda `moneda_id` NORMALIZADO (nunca null:
     * NULL → 1) y `valor_dolar` null cuando la venta no tenía cotización, no cambia de moneda y el
     * vendedor no tocó el campo. Eso es "no tocar": una venta en dólares sin cotización sigue sin
     * cotización (200, sin 422), y una venta sin moneda y sin cotización queda en pesos sin cotización.
     *
     * @test
     */
    public function la_spa_nueva_con_moneda_normalizada_y_cotizacion_null_preserva_lo_guardado()
    {
        $cliente = $this->cliente_nuevo('zz Contrato SPA');

        $en_dolares = $this->vender($cliente, self::DOLARES, 100);
        DB::table('sales')->where('id', $en_dolares->id)->update(['valor_dolar' => null]);

        $en_dolares = $this->editar_ok($en_dolares, $this->payload_de_edicion($en_dolares, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => null,
        ]));

        $this->assertEquals(self::DOLARES, (int) $en_dolares->moneda_id);
        $this->assertNull($en_dolares->valor_dolar, 'Una venta en dólares sin cotización tiene que seguir sin cotización.');
        $this->assertNotNull($this->debito_de($en_dolares, $this->cuenta($cliente, self::DOLARES)));

        $sin_moneda = $this->vender($cliente, self::PESOS, 30000);
        DB::table('sales')->where('id', $sin_moneda->id)->update(['moneda_id' => null, 'valor_dolar' => null]);

        $sin_moneda = $this->editar_ok($sin_moneda, $this->payload_de_edicion($sin_moneda, [
            'moneda_id'   => self::PESOS,
            'valor_dolar' => null,
        ]));

        $this->assertEquals(self::PESOS, (int) $sin_moneda->moneda_id);
        $this->assertNull($sin_moneda->valor_dolar);

        $this->assert_saldo_independiente($cliente, self::DOLARES, 100, 'Dólares');
        $this->assert_saldo_independiente($cliente, self::PESOS, 30000, 'Pesos');
    }

    /**
     * Caso 10. Pasar a dólares una venta en pesos que no tiene cotización guardada, sin mandar una
     * válida: 422 `sin_cotizacion_dolar` y NADA cambió (foto cruda de la venta, sus renglones y la
     * cuenta corriente). También 422 con una cotización inválida explícita (texto, negativa) si la venta
     * queda en dólares; y 200 (preservando) si queda en pesos.
     *
     * @test
     */
    public function pasar_a_dolares_sin_cotizacion_valida_da_422_sin_escribir_nada()
    {
        $cliente = $this->cliente_nuevo('zz 422 cotizacion');

        $venta = $this->vender($cliente, self::PESOS, 120000);
        DB::table('sales')->where('id', $venta->id)->update(['valor_dolar' => null]);

        $antes = $this->foto_cruda($venta, [$cliente]);

        $items_en_dolares = $this->reexpresar($this->items_de($venta), 1 / 1200);

        foreach ([null, 0, 'abc', -5] as $cotizacion) {

            $response = $this->put_venta($venta, $this->payload_de_edicion($venta, [
                'moneda_id'   => self::DOLARES,
                'valor_dolar' => $cotizacion,
                'items'       => $items_en_dolares,
                'sub_total'   => 100,
                'total'       => 100,
            ]));

            $this->assertEquals(422, $response->getStatusCode(), 'Pasar a dólares con cotización '.var_export($cotizacion, true).' tenía que dar 422. Cuerpo: '.substr($response->getContent(), 0, 500));
            $this->assertTrue((bool) $response->json('sin_cotizacion_dolar'));
            $this->assertNotEmpty($response->json('message'));

            $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente]), 'El 422 con cotización '.var_export($cotizacion, true).' escribió algo.');
        }

        // En pesos, una cotización inválida no rechaza nada: se preserva la guardada (null).
        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['valor_dolar' => 'abc']));
        $this->assertEquals(self::PESOS, (int) $venta->moneda_id);
        $this->assertNull($venta->valor_dolar);
    }

    /**
     * Caso 10, lado dólares: una venta que ya está en dólares y recibe una cotización inválida
     * explícita (negativa) da 422 y no escribe nada. Mismo criterio que `BudgetController::update()`.
     *
     * @test
     */
    public function una_cotizacion_negativa_en_una_venta_en_dolares_da_422()
    {
        $cliente = $this->cliente_nuevo('zz 422 negativa');

        $venta = $this->vender($cliente, self::DOLARES, 100);

        $antes = $this->foto_cruda($venta, [$cliente]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['valor_dolar' => -1200]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('sin_cotizacion_dolar'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente]));
    }

    /**
     * Caso 12. La comisión del vendedor se recrea en la moneda que queda: una venta en pesos con
     * vendedor al 10 % pasada a dólares deja su comisión en dólares, sobre el total en dólares, y
     * ninguna en pesos.
     *
     * @test
     */
    public function la_comision_del_vendedor_se_recrea_en_la_moneda_que_queda()
    {
        // El dueño del fixture usa el motor de Distri Creo; para el genérico por porcentaje
        // (ComisionPorcentajeGeneral) se lo apaga, como en tests/Feature/Comisiones/1.
        DB::table('users')->where('id', $this->usuario->id)->update(['comision_funcion' => null]);

        $vendedor = Seller::create([
            'num'                       => 910001,
            'name'                      => 'zz Vendedor edicion completa',
            'commission_after_pay_sale' => 0,
            'percentage_commission'     => 10,
            'commission_with_iva'       => 1,
            'user_id'                   => $this->usuario->id,
        ]);

        $cliente = $this->cliente_nuevo('zz Comision');

        $venta = $this->vender($cliente, self::PESOS, 120000, ['seller_id' => $vendedor->id]);

        $comisiones = DB::table('seller_commissions')->where('sale_id', $venta->id)->whereNull('haber')->get();
        $this->assertCount(1, $comisiones, 'Precondición: la venta tiene que nacer con su comisión.');
        $this->assertEquals(self::PESOS, (int) $comisiones[0]->moneda_id);
        $this->assertMonto(12000, $comisiones[0]->debe);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1200,
            'items'       => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'   => 100,
            'total'       => 100,
        ]));

        $comisiones = DB::table('seller_commissions')->where('sale_id', $venta->id)->whereNull('haber')->get();
        $this->assertCount(1, $comisiones, 'La comisión se duplicó o desapareció.');
        $this->assertEquals(self::DOLARES, (int) $comisiones[0]->moneda_id, 'La comisión no quedó en la moneda nueva de la venta.');
        $this->assertMonto(10, $comisiones[0]->debe, 'La comisión tiene que ser el 10 % del total en dólares.');
    }
}
