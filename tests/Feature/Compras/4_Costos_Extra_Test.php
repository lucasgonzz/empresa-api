<?php

namespace Tests\Feature\Compras;

use App\Models\ArticleSurchage;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderExtraCost;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de costos extra (flete/transporte) del modulo de compras (Grupo 184, Prompt 616).
 *
 * Cubre `NewProviderOrderHelper::aplicar_costos_extra_a_recargos_articulos()` (prorrateo entre
 * articulos, materializado como `article_surchage` unitario) y
 * `ModoFacturacionHelper::sincronizar_tickets_costos_extra_aparte()` (factura aparte idempotente
 * para un costo extra `facturado` con `en_factura_compra = false`).
 *
 * Los costos extra se crean directo por Eloquent (no via `POST api/provider-order-extra-cost`)
 * para no depender de `sendAddModelNotification()` (canales de notificacion no configurados en
 * el entorno de testing) — la logica bajo prueba corre en `NewProviderOrderHelper`/
 * `ModoFacturacionHelper`, disparada por el `PUT api/provider-order/{id}` que reconfirma la
 * compra, no por el controller del costo extra en si.
 *
 * Nota de aislamiento (fixture compartido, MyISAM sin rollback real — ver docblock de
 * `ComprasTestCase`): se restauran `cost`/`stock`/`provider_id`/`costo_real` de los articulos
 * tocados y se borran los `article_surchage` de tipo transporte que cada test materializa, para
 * no dejar residuo entre corridas. `update_stock` se fuerza a 0 (no es parte de lo que se prueba
 * acá). Se llama `quitar_bonificaciones_de_buenos_aires()` en todos estos tests para aislar el
 * efecto de los costos extra del efecto de las bonificaciones de proveedor (Prompt 616, archivo
 * de Descuentos), que de otro modo contaminarian el `total`/`sub_total` esperado.
 */
class Costos_Extra_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Borra los `article_surchage` de tipo transporte que un test haya materializado, para dejar
     * el articulo sin residuo de cara a otros tests/archivos.
     *
     * @param int $articulo_id
     * @return void
     */
    protected function limpiar_surchage_transporte($articulo_id)
    {
        ArticleSurchage::where('article_id', $articulo_id)
                        ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                        ->delete();
    }

    /**
     * Arma y confirma una compra de 2 items (Pinza 1000x10 + Alicate 300x10, subtotal 13000)
     * a Buenos Aires, sin bonificaciones de proveedor (aisladas con
     * `quitar_bonificaciones_de_buenos_aires`), y sin IVA sumado al total (foco en costos extra).
     *
     * @return int Id de la orden creada.
     */
    protected function crear_compra_base_2_items()
    {
        $payload = $this->payload_compra([
            'update_stock'   => 0,
            'total_with_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
                $this->item('Alicate', 300, 10),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        return $response->json('model.id');
    }

    /**
     * Test 1 — costo extra de transporte facturado DENTRO de la factura de la compra
     * (`en_factura_compra = true`): el total de la orden debe incluirlo, y no se debe generar
     * ninguna factura aparte (sigue habiendo un solo comprobante).
     *
     * @group compras
     * @test
     */
    public function flete_dentro_de_la_factura_de_la_compra()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $order_id = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Flete',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            // Reconfirma la compra para que procesar_pedido()/check_modo_facturacion() vean el
            // costo extra recien creado y recalculen.
            $response = $this->putJson('api/provider-order/'.$order_id, $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                    $this->item('Alicate', 300, 10),
                ],
            ]));

            $response->assertStatus(200);

            $provider_order = ProviderOrder::find($order_id);

            $this->assertEqualsWithDelta(
                14300,
                (float) $provider_order->total,
                self::DELTA,
                'el total de la compra debe incluir el costo extra (13000 + 1300 = 14300)'
            );

            $tickets = ProviderOrderAfipTicket::where('provider_order_id', $order_id)->get();

            $this->assertCount(
                1,
                $tickets,
                'con en_factura_compra=true no debe generarse factura aparte: solo el comprobante principal de la compra'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
        }
    }

    /**
     * Test 2 — el prorrateo del costo extra reparte por PESO (subtotal bruto de cada item sobre
     * el subtotal de la orden) y no pierde centavos: la suma de los `article_surchage` generados
     * (monto unitario x cantidad de cada item) debe dar el valor total del costo extra. El monto
     * guardado en cada recargo es UNITARIO (se divide por la cantidad del item), como hace
     * `aplicar_costos_extra_a_recargos_articulos()`.
     *
     * @group compras
     * @test
     */
    public function el_prorrateo_reparte_por_peso_y_no_pierde_centavos()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $order_id = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Flete',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $response = $this->putJson('api/provider-order/'.$order_id, $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                    $this->item('Alicate', 300, 10),
                ],
            ]));

            $response->assertStatus(200);

            $surchage_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $surchage_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $this->assertNotNull($surchage_pinza, 'debe existir un article_surchage de transporte para Pinza');
            $this->assertNotNull($surchage_alicate, 'debe existir un article_surchage de transporte para Alicate');

            // Peso de Pinza: 10000/13000 del costo extra (1300) = 1000, dividido por su cantidad
            // (10) = 100 unitario. Peso de Alicate: 3000/13000 de 1300 = 300, dividido por 10 = 30.
            $this->assertEqualsWithDelta(
                100,
                (float) $surchage_pinza->amount,
                self::DELTA,
                'recargo unitario de Pinza: 1300 * (10000/13000) / 10 = 100'
            );

            $this->assertEqualsWithDelta(
                30,
                (float) $surchage_alicate->amount,
                self::DELTA,
                'recargo unitario de Alicate: 1300 * (3000/13000) / 10 = 30'
            );

            $total_prorrateado = ((float) $surchage_pinza->amount * 10) + ((float) $surchage_alicate->amount * 10);

            $this->assertEqualsWithDelta(
                1300,
                $total_prorrateado,
                0.05,
                'el prorrateo entre articulos debe sumar el total del costo extra (1300), sin perder centavos'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
        }
    }

    /**
     * Test 3 — costo extra facturado APARTE (`en_factura_compra = false`, con emisor cargado):
     * se generan DOS comprobantes (el de la compra y el de la factura aparte), y el total de la
     * factura aparte es exactamente el valor del costo extra.
     *
     * @group compras
     * @test
     */
    public function flete_como_factura_aparte()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $order_id = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id'   => $order_id,
                'description'         => 'Flete tercerizado',
                'value'               => 1300,
                'tipo'                => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'           => true,
                'en_factura_compra'   => false,
                'emisor_razon_social' => 'Transportista SRL',
                'emisor_cuit'         => '30-12345678-9',
            ]);

            $response = $this->putJson('api/provider-order/'.$order_id, $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                    $this->item('Alicate', 300, 10),
                ],
            ]));

            $response->assertStatus(200);

            $tickets = ProviderOrderAfipTicket::where('provider_order_id', $order_id)->get();

            $this->assertCount(
                2,
                $tickets,
                'con en_factura_compra=false debe haber 2 comprobantes: el de la compra + el aparte del costo extra'
            );

            $ticket_aparte = ProviderOrderAfipTicket::where('provider_order_id', $order_id)
                                                        ->whereNotNull('provider_order_extra_cost_id')
                                                        ->first();

            $this->assertNotNull($ticket_aparte, 'debe existir el comprobante aparte referenciado al costo extra');

            $this->assertEqualsWithDelta(
                1300,
                (float) $ticket_aparte->total,
                self::DELTA,
                'el total de la factura aparte debe ser exactamente el valor del costo extra (1300)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
        }
    }

    /**
     * Test 4 — idempotencia de la factura aparte: confirmar/actualizar la misma compra dos
     * veces no debe duplicar el comprobante aparte (sigue habiendo uno solo).
     *
     * @group compras
     * @test
     */
    public function idempotencia_de_la_factura_aparte()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $order_id = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id'   => $order_id,
                'description'         => 'Flete tercerizado',
                'value'               => 1300,
                'tipo'                => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'           => true,
                'en_factura_compra'   => false,
                'emisor_razon_social' => 'Transportista SRL',
                'emisor_cuit'         => '30-12345678-9',
            ]);

            $payload_update = $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                    $this->item('Alicate', 300, 10),
                ],
            ]);

            // Primera confirmacion (genera el comprobante aparte).
            $response = $this->putJson('api/provider-order/'.$order_id, $payload_update);
            $response->assertStatus(200);

            $cantidad_aparte_1ra_vez = ProviderOrderAfipTicket::where('provider_order_id', $order_id)
                                                                ->whereNotNull('provider_order_extra_cost_id')
                                                                ->count();

            // Segunda confirmacion, mismos datos: no debe duplicar el comprobante aparte.
            $response = $this->putJson('api/provider-order/'.$order_id, $payload_update);
            $response->assertStatus(200);

            $cantidad_aparte_2da_vez = ProviderOrderAfipTicket::where('provider_order_id', $order_id)
                                                                ->whereNotNull('provider_order_extra_cost_id')
                                                                ->count();

            $this->assertEquals(
                1,
                $cantidad_aparte_1ra_vez,
                'despues de la 1ra confirmacion debe haber exactamente 1 comprobante aparte'
            );

            $this->assertEquals(
                1,
                $cantidad_aparte_2da_vez,
                'despues de confirmar 2 veces la misma compra debe seguir habiendo 1 solo comprobante aparte (idempotente, no duplicado)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
        }
    }

    /**
     * Test 5 — sin costos extra tipados en la compra no se genera ningun `article_surchage`
     * nuevo, y sigue habiendo un solo comprobante (el de la compra).
     *
     * @group compras
     * @test
     */
    public function sin_costos_extra_no_se_genera_nada()
    {
        $this->set_condicion_iva('RRII');

        $marco = $this->articulo('Marco para cama');
        $snapshot = $this->snapshot_articulo($marco);
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        try {
            $payload = $this->payload_compra([
                'provider_id'    => $rosario->id,
                'update_stock'   => 0,
                'articles' => [
                    $this->item('Marco para cama', 50, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $order_id = $response->json('model.id');

            $surchages = ArticleSurchage::where('article_id', $marco->id)->get();

            $this->assertCount(
                0,
                $surchages,
                'sin costos extra tipados en la compra no debe crearse ningun article_surchage'
            );

            $tickets = ProviderOrderAfipTicket::where('provider_order_id', $order_id)->get();

            $this->assertCount(
                1,
                $tickets,
                'sin costos extra debe seguir habiendo un solo comprobante (el de la compra)'
            );
        } finally {
            $this->restaurar_articulo($marco, $snapshot);
        }
    }
}
