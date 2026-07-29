<?php

namespace Tests\Feature\Iva;

use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderExtraCost;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Tests de la factura aparte por costo extra facturado con `en_factura_compra = false` (Grupo
 * 244, Prompt 02): el caso del grupo 183 que ninguna suite tocaba.
 *
 * ⚠️ Cobertura que YA existe en `--group compras` (`tests/Feature/Compras/4_Costos_Extra_Test.php`)
 * y que este archivo NO repite (ver `## Qué NO va acá` en cada docblock de test salteado):
 * - "costo extra con `en_factura_compra = true` suma al total y no genera factura aparte"
 *   ya lo prueba `Costos_Extra_Test::flete_dentro_de_la_factura_de_la_compra()`.
 * - "confirmar la compra dos veces no duplica la factura aparte" (idempotencia) ya lo prueba
 *   `Costos_Extra_Test::idempotencia_de_la_factura_aparte()`.
 * - "factura aparte con 2 comprobantes en total y total bruto correcto" ya lo prueba
 *   `Costos_Extra_Test::flete_como_factura_aparte()`. OJO: esa prueba CARGA `emisor_cuit`/
 *   `emisor_razon_social` en el costo extra pero NUNCA los assertea sobre el ticket, y tampoco
 *   carga `iva_id` — no cubre ni la propagacion del emisor ni el desglose de IVA por alicuota.
 *   El Test 1 de acá cubre las dos cosas.
 *
 * Usa el proveedor Rosario (sin bonificaciones) para no tener que aislarlas con
 * `quitar_bonificaciones_de_buenos_aires()`: simplifica los numeros esperados sin perder cobertura.
 *
 * @group iva-condicion
 */
class Costo_Extra_Factura_Aparte_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Arma y confirma una compra de un solo item (Marco para cama, Rosario, sin bonificaciones)
     * con la que arman los 4 tests de este archivo.
     *
     * Marco para cama (21%), costo 200 x 5 = 1000 neto. Con `precios_incluyen_iva = 0` (default)
     * y condicion RRII: IVA = 210, total de la compra SIN costo extra = 1210.
     *
     * @return int Id de la orden creada.
     */
    protected function crear_compra_base()
    {
        $payload = $this->payload_compra([
            'provider_id'    => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'update_stock'   => 0,
            'articles' => [
                $this->item('Marco para cama', 200, 5),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        return $response->json('model.id');
    }

    /**
     * Reconfirma (PUT) la compra base con el costo extra ya creado, para que
     * `check_modo_facturacion()`/`procesar_pedido()` lo vean y generen/actualicen la factura aparte.
     *
     * @param int $order_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function reconfirmar_compra_base($order_id)
    {
        return $this->putJson('api/provider-order/'.$order_id, $this->payload_compra([
            'provider_id'    => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'update_stock'   => 0,
            'articles' => [
                $this->item('Marco para cama', 200, 5),
            ],
        ]));
    }

    /**
     * Test 1 — costo extra facturado aparte CON alicuota configurada (`iva_id` cargado): la
     * factura aparte trae el desglose de IVA con esa alicuota, no solo el total bruto (que es
     * todo lo que ya prueba `Costos_Extra_Test::flete_como_factura_aparte()`, sin `iva_id`).
     *
     * @group iva-condicion
     * @test
     */
    public function factura_aparte_con_alicuota_configurada_trae_el_desglose_de_iva()
    {
        $this->set_condicion_iva('RRII');

        // Inicializado antes del try para que el finally siempre tenga una variable definida,
        // incluso si crear_compra_base() lanzara antes de completarse.
        $order_id = null;

        try {

            $order_id = $this->crear_compra_base();

            $iva_21 = Iva::where('percentage', '21')->first();

            // Bruto 605 al 21%: neto = 605 / 1.21 = 500.00 (exacto), iva = 605 - 500 = 105.00.
            $extra_cost = ProviderOrderExtraCost::create([
                'provider_order_id'   => $order_id,
                'description'         => 'Despachante de aduana',
                'value'               => 605,
                'tipo'                => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                'facturado'           => true,
                'en_factura_compra'   => false,
                'iva_id'              => $iva_21->id,
                'emisor_razon_social' => 'Despachante SRL',
                'emisor_cuit'         => '30-99999999-1',
            ]);

            $response = $this->reconfirmar_compra_base($order_id);

            $response->assertStatus(200);

            $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_extra_cost_id', $extra_cost->id)->first();

            $this->assertNotNull($ticket_aparte, 'tiene que existir el comprobante aparte referenciado al costo extra');

            $this->assertEquals('30-99999999-1', $ticket_aparte->emisor_cuit, 'la factura aparte tiene que traer el CUIT del emisor cargado en el costo extra');
            $this->assertEquals('Despachante SRL', $ticket_aparte->emisor_razon_social, 'la factura aparte tiene que traer la razon social del emisor cargada en el costo extra');

            $ticket_aparte->load('provider_order_afip_ticket_ivas');

            $this->assertCount(
                1,
                $ticket_aparte->provider_order_afip_ticket_ivas,
                'la factura aparte con iva_id cargado tiene que traer una fila de desglose de IVA'
            );

            $fila = $ticket_aparte->provider_order_afip_ticket_ivas->first();

            $this->assertEquals((int) $iva_21->id, (int) $fila->iva_id, 'la fila de desglose tiene que usar la alicuota propia del costo extra, no la de los articulos de la compra (21% tambien, a proposito, para que este chequeo no sea ambiguo)');
            $this->assertEqualsWithDelta(500.00, (float) $fila->neto, self::DELTA, 'neto de la fila (literal: 605 / 1.21 = 500.00)');
            $this->assertEqualsWithDelta(105.00, (float) $fila->iva_importe, self::DELTA, 'IVA de la fila (literal: 605 - 500 = 105.00)');

            $this->assertEqualsWithDelta(105.00, (float) $ticket_aparte->total_iva, self::DELTA, 'total_iva del comprobante aparte');
            $this->assertEqualsWithDelta(605.00, (float) $ticket_aparte->total, self::DELTA, 'total del comprobante aparte (el bruto tal cual se cargo)');

        } finally {

            $this->borrar_costos_extra_y_facturas_aparte($order_id);
        }
    }

    /**
     * Test 2 — el total de la COMPRA (`provider_order.total`) con un costo extra de factura
     * aparte, comparado contra la misma compra sin ese costo extra.
     *
     * 🔴 Hallazgo (no se ajusta la aserción ni se toca produccion, se reporta): leyendo
     * `NewProviderOrderHelper::set_totales()`, el bloque que suma `total_costos_extra` recorre
     * TODOS los `provider_order_extra_costs` de la orden y los suma al total sin filtrar por
     * `en_factura_compra` ni por `facturado`. La especificacion del grupo 183 (prompt 610, tarea
     * 3) dice que un costo extra con `en_factura_compra = false` "no entra en la factura de la
     * compra" — pero no dice explicitamente que quede afuera del total ADEUDADO de la orden (que
     * es lo que representa `provider_order.total`, la cuenta corriente que se le abre al
     * proveedor). Este test assertea el comportamiento REAL del codigo (el costo extra SI se suma
     * al total de la orden, aparezca en la factura principal o en una aparte), documentado tal
     * cual está, no lo que se podria haber asumido leyendo el prompt 244/02 en aislado.
     *
     * Matiz que este test deja registrado (candidato a correctivo, no un bug de este prompt):
     * `provider_order.total` alimenta la cuenta corriente que se le abre al PROVEEDOR de la
     * compra (`NewProviderOrderHelper::set_credit_account()`/`crear_current_acount()`). Un costo
     * extra facturado por un emisor DISTINTO (ej. un despachante de aduana con su propio CUIT, no
     * el proveedor de la mercaderia) hoy infla esa cuenta corriente igual, aunque su documento
     * fiscal quede separado. Es defendible leerlo como "total adeudado de la operacion completa",
     * pero es exactamente el caso que el prompt 610 separo a nivel documento (factura aparte) y
     * no a nivel cuenta corriente — si el negocio quiere la deuda del despachante separada de la
     * del proveedor, esto es lo que habria que revisar.
     *
     * @group iva-condicion
     * @test
     */
    public function el_total_de_la_orden_incluye_el_costo_extra_aunque_su_factura_sea_aparte()
    {
        $this->set_condicion_iva('RRII');

        // Inicializado antes del try para que el finally siempre tenga una variable definida,
        // incluso si crear_compra_base() de la compra de control (la primera del método) lanzara
        // antes de llegar a la compra con costo extra.
        $order_id = null;

        try {

            // Compra de control, SIN costo extra: total = 1210 (1000 neto + 210 de IVA al 21%).
            $order_sin_costo_extra_id = $this->crear_compra_base();
            $provider_order_sin_costo_extra = ProviderOrder::find($order_sin_costo_extra_id);

            $this->assertEqualsWithDelta(
                1210.00,
                (float) $provider_order_sin_costo_extra->total,
                self::DELTA,
                'total de la compra de control, sin costo extra (literal: 1000 + 210 de IVA)'
            );

            // Compra con costo extra de factura aparte (bruto 605, sin iva_id: se guarda directo
            // como bruto, sin desglose, para aislar este test del Test 1).
            $order_id = $this->crear_compra_base();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Despachante de aduana',
                'value'             => 605,
                'tipo'              => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                'facturado'         => true,
                'en_factura_compra' => false,
            ]);

            $response = $this->reconfirmar_compra_base($order_id);

            $response->assertStatus(200);

            $provider_order = ProviderOrder::find($order_id);

            $this->assertEqualsWithDelta(
                1815.00,
                (float) $provider_order->total,
                self::DELTA,
                'total de la orden CON el costo extra de factura aparte: 1210 (compra) + 605 (costo extra) = 1815 — el costo extra SI suma al total de la orden aunque su documento fiscal sea aparte (ver docblock del test)'
            );

        } finally {

            $this->borrar_costos_extra_y_facturas_aparte($order_id);
        }
    }

    /**
     * Test 3 — costo extra facturado aparte SIN CUIT ni razon social del emisor.
     *
     * La especificacion del grupo 183 (prompt 610, tarea 3) no define explicitamente que pasa si
     * faltan esos datos: dice que la factura aparte se genera "con los datos del emisor... para
     * que el usuario despues la complete", lo que sugiere que pueden faltar al momento de generarse.
     * Leyendo el codigo (`ModoFacturacionHelper::sincronizar_tickets_costos_extra_aparte()`): no
     * valida ni rechaza, guarda `emisor_cuit`/`emisor_razon_social` en NULL si vienen vacios y
     * genera el comprobante igual. Este test documenta ESE comportamiento (no es un hueco de
     * especificacion que haya que resolver con un correctivo: el codigo ya tiene una respuesta
     * razonable y consistente con "completar despues").
     *
     * @group iva-condicion
     * @test
     */
    public function factura_aparte_sin_cuit_ni_razon_social_se_genera_igual_con_esos_campos_en_null()
    {
        $this->set_condicion_iva('RRII');

        // Inicializado antes del try para que el finally siempre tenga una variable definida,
        // incluso si crear_compra_base() lanzara antes de completarse.
        $order_id = null;

        try {

            $order_id = $this->crear_compra_base();

            $extra_cost = ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Despachante de aduana',
                'value'             => 605,
                'tipo'              => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                'facturado'         => true,
                'en_factura_compra' => false,
                // Sin emisor_cuit ni emisor_razon_social: caso que la especificacion no define
                // explicitamente (ver docblock de arriba).
            ]);

            $response = $this->reconfirmar_compra_base($order_id);

            $response->assertStatus(200, 'sin datos del emisor la compra se tiene que poder confirmar igual (no hay validacion que lo bloquee, ver docblock)');

            $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_extra_cost_id', $extra_cost->id)->first();

            $this->assertNotNull($ticket_aparte, 'la factura aparte se genera igual sin datos del emisor');

            $this->assertNull($ticket_aparte->emisor_cuit, 'sin emisor_cuit cargado en el costo extra, el comprobante aparte lo guarda en NULL (para completarlo despues)');
            $this->assertNull($ticket_aparte->emisor_razon_social, 'sin emisor_razon_social cargado en el costo extra, el comprobante aparte lo guarda en NULL (para completarlo despues)');

            $this->assertEqualsWithDelta(605.00, (float) $ticket_aparte->total, self::DELTA, 'el total del comprobante aparte no depende de que el emisor este cargado');

        } finally {

            $this->borrar_costos_extra_y_facturas_aparte($order_id);
        }
    }

    /**
     * Test 4 — costo extra NO facturado (`facturado = false`): no genera factura aparte, sin
     * importar el valor de `en_factura_compra`. Caso distinto del que ya prueba
     * `Costos_Extra_Test::sin_costos_extra_no_se_genera_nada()` (que prueba la ausencia total de
     * costos extra, no un costo extra existente con `facturado = false`).
     *
     * @group iva-condicion
     * @test
     */
    public function costo_extra_no_facturado_no_genera_factura_aparte()
    {
        $this->set_condicion_iva('RRII');

        // Inicializado antes del try para que el finally siempre tenga una variable definida,
        // incluso si crear_compra_base() lanzara antes de completarse.
        $order_id = null;

        try {

            $order_id = $this->crear_compra_base();

            $extra_cost = ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Despachante de aduana (todavia sin factura)',
                'value'             => 605,
                'tipo'              => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                'facturado'         => false,
                'en_factura_compra' => false,
            ]);

            $response = $this->reconfirmar_compra_base($order_id);

            $response->assertStatus(200);

            $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_extra_cost_id', $extra_cost->id)->first();

            $this->assertNull($ticket_aparte, 'facturado = false: no se tiene que generar ninguna factura aparte para este costo extra');

            $tickets = ProviderOrderAfipTicket::where('provider_order_id', $order_id)->get();

            $this->assertCount(1, $tickets, 'sigue habiendo un solo comprobante: el principal de la compra');

        } finally {

            $this->borrar_costos_extra_y_facturas_aparte($order_id);
        }
    }

    /**
     * Borra los `provider_order_extra_costs` y las facturas aparte (con su desglose de IVA) que un
     * test de este archivo haya generado, identificados por `provider_order_id`, para no dejar
     * residuo de cara a otros tests/archivos (motor MyISAM, sin rollback real — ver docblock de
     * `ComprasTestCase`). Las compras (`provider_order`) y su comprobante principal se dejan: son
     * filas nuevas por test (no mutan el fixture sembrado) y no interfieren con otras suites.
     *
     * @param int|null $order_id
     * @return void
     */
    protected function borrar_costos_extra_y_facturas_aparte($order_id)
    {
        if (is_null($order_id)) {
            return;
        }

        $tickets_aparte = ProviderOrderAfipTicket::where('provider_order_id', $order_id)
                                                    ->whereNotNull('provider_order_extra_cost_id')
                                                    ->get();

        foreach ($tickets_aparte as $ticket_aparte) {
            $ticket_aparte->provider_order_afip_ticket_ivas()->delete();
            $ticket_aparte->delete();
        }

        ProviderOrderExtraCost::where('provider_order_id', $order_id)->delete();
    }
}
