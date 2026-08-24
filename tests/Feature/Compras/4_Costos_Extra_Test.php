<?php

namespace Tests\Feature\Compras;

use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderDiscount;
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
     * Igual que `limpiar_surchage_transporte()` pero para los TRES tipos materializables. Lo
     * necesitan los tests de la mision `costos-extra-mismo-tipo-se-pisan` (24/8/2026), que ademas
     * del transporte generan recargos de tipo `seguro`: con el helper viejo esos quedaban colgados
     * en el fixture y contaminaban los demas archivos.
     *
     * El helper de arriba se deja intacto porque lo usan seis tests anteriores.
     *
     * @param int $articulo_id
     * @return void
     */
    protected function limpiar_surchages_tipados($articulo_id)
    {
        ArticleSurchage::where('article_id', $articulo_id)
                        ->whereIn('tipo', [
                            ProviderOrderExtraCost::TIPO_TRANSPORTE,
                            ProviderOrderExtraCost::TIPO_SEGURO,
                            ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                        ])
                        ->delete();
    }

    /**
     * Limpia los `article_discounts` tagueados con Buenos Aires que una compra de este archivo haya
     * materializado, para dejar el articulo sin residuo de cara a otros tests/archivos. Misma
     * implementacion que el helper homonimo de `3_Descuentos_Test` (Prompt 609: lo necesita el test
     * del prorrateo sobre una compra CON descuentos, que con `update_prices = 1` los materializa).
     *
     * @param int $articulo_id
     * @return void
     */
    protected function limpiar_descuentos_tagueados($articulo_id)
    {
        $bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);

        ArticleDiscount::where('article_id', $articulo_id)
                        ->where('provider_id', $bsas->id)
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
     * Extendido por el Prompt 609: ademas de que el recargo EXISTA con el monto correcto, ahora se
     * verifica que LLEGUE al costo real del articulo. Es el punto del audio `4.2` del volcado
     * (`multimedia/contraste/S4.md`): sin prorrateo, el margen que el dueño cree tener es ficticio.
     * Un recargo materializado que no impactara el costo real seria exactamente el mismo problema
     * con otra cara, y las tres aserciones originales lo dejaban pasar.
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

            /*
             * Prompt 609 — el recargo tiene que LLEGAR al costo real, que es la base sobre la que
             * despues se calculan el margen y el precio final:
             * `aplicar_costos_extra_a_recargos_articulos()` termina llamando a
             * `ArticleHelper::setFinalPrice()`, que via `aplicar_descuentos_e_iva()` llega a
             * `ArticlePricesHelper::aplicar_recargos()`, y ahi un recargo por monto hace
             * `$price += $surchage->amount`.
             *
             * 🔴 Se assertea `costo_real` y NO `final_price`, y el motivo no es de estilo:
             * `restaurar_articulo()` restaura `costo_real` pero no `final_price`. Una asercion
             * sobre `final_price` daria verde en la primera corrida y roja en la segunda, porque el
             * "antes" de la segunda ya seria el valor bumpeado por la primera. Ademas `final_price`
             * depende del margen del articulo, de los margenes de proveedor/categoria, de las
             * `sale_taxes` del fixture (IIBB con `apply_to_all`) y de las listas de precio: nada de
             * eso es lo que este test mide.
             *
             * Que `costo_real` sea exactamente `cost + amount` vale aca porque el usuario del
             * fixture tiene `usar_condicion_fiscal_en_costeo = 1` (el IVA no entra al costo) y las
             * bonificaciones de Buenos Aires estan neutralizadas al inicio del test.
             */
            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(
                1100,
                (float) $pinza->costo_real,
                self::DELTA,
                'el recargo prorrateado tiene que llegar al costo real de Pinza: 1000 de costo + 100 de flete unitario = 1100'
            );

            $this->assertEqualsWithDelta(
                330,
                (float) $alicate->costo_real,
                self::DELTA,
                'el recargo prorrateado tiene que llegar al costo real de Alicate: 300 de costo + 30 de flete unitario = 330'
            );

            // Cierre de la cadena: el costo real es exactamente el costo mas el recargo guardado,
            // y no un numero parecido que salio de otro lado.
            $this->assertEqualsWithDelta(
                (float) $pinza->cost + (float) $surchage_pinza->amount,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo_real de Pinza = articles.cost + article_surchage.amount'
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

    /**
     * Test 6 (Prompt 609) — un costo extra de tipo `otro` NO crea ningun recargo, ni siquiera con
     * `update_prices = 1`. Es la contracara exacta del test 2: mismo escenario, mismo costo extra,
     * unico cambio el `tipo`.
     *
     * 🔴 Esto CONSERVA el comportamiento actual, no lo cambia. La opcion B del prompt 609 —hacer
     * que `otro` tambien prorratee— quedo descartada por decision de Lucas (22/8/2026): `otro`
     * existe justamente para el costo que NO se quiere repartir entre los articulos (una comision
     * bancaria, por ejemplo). Lo que se arreglo es que la pantalla lo AVISE y que el default del
     * formulario deje de ser `otro`, no que `otro` cambie de significado.
     *
     * Si alguna vez alguien hace que `otro` prorratee, este test se pone rojo, y eso es lo correcto.
     *
     * @group compras
     * @test
     */
    public function el_tipo_otro_no_crea_ningun_recargo_aunque_update_prices_este_en_1()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            // `crear_compra_base_2_items()` usa `payload_compra()`, que manda `update_prices => 1`
            // por default. Que la bandera este PRENDIDA es la mitad del punto de este test: lo que
            // frena el prorrateo es el tipo, no la bandera.
            $order_id = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Comision bancaria',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_OTRO,
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

            $order = ProviderOrder::find($order_id);

            /*
             * Guard anti-verde-falso: sin esto, el test pasaria igual si el costo extra ni siquiera
             * se hubiera guardado. Un costo extra de tipo `otro` SI suma al total de la compra
             * (13000 + 1300 = 14300); lo unico que no hace es prorratearse.
             */
            $this->assertEqualsWithDelta(
                14300,
                (float) $order->total,
                self::DELTA,
                'guard: un costo extra de tipo "otro" SI suma al total de la compra (13000 + 1300)'
            );

            $this->assertCount(
                0,
                ArticleSurchage::where('article_id', $pinza->id)->get(),
                'un costo extra de tipo "otro" no debe crear ningun article_surchage en Pinza, de ningun tipo'
            );

            $this->assertCount(
                0,
                ArticleSurchage::where('article_id', $alicate->id)->get(),
                'un costo extra de tipo "otro" no debe crear ningun article_surchage en Alicate, de ningun tipo'
            );

            $pinza->refresh();

            $this->assertEqualsWithDelta(
                (float) $snap_pinza['costo_real'],
                (float) $pinza->costo_real,
                self::DELTA,
                'sin prorrateo, el costo real del articulo queda igual que antes de la compra: el flete no llego a ningun lado'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            // No deberia haber ninguno, pero se limpia igual: cuesta nada y si el test se pone rojo
            // porque SI se creo un recargo, evita que el residuo contamine los demas archivos.
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
        }
    }

    /**
     * Test 7 (Prompt 609) — la base del prorrateo es el subtotal BRUTO de la compra, antes de todo
     * descuento, no el neto.
     *
     * Por que el escenario es este y no uno mas simple: un descuento de compra
     * (`provider_order_discount`) solo NO distingue nada, porque escalaria numerador y denominador
     * por igual y los recargos unitarios darian identicos. Hace falta un descuento de RENGLON
     * (`pivot->discount`, porcentual, ver `get_total_article()`) sobre UN SOLO articulo, que es lo
     * que cambia el peso relativo de cada item. Se agrega ademas un descuento de compra para cubrir
     * la otra dimension.
     *
     *                Costo  Cant.  Desc. renglon   Subtotal BRUTO   Subtotal NETO
     *   Pinza         1000     10        50%           10.000            5.000
     *   Alicate        300     10         -             3.000            3.000
     *                                                  13.000            8.000
     *
     * Con flete de 1.300 y base BRUTA (lo correcto): Pinza 1300*(10000/13000)/10 = 100 unitario,
     * Alicate 1300*(3000/13000)/10 = 30 unitario, y cierra 1.300 exacto.
     * Con base NETA (lo que este test descarta): daria 81,25 y 48,75.
     * 100 != 81,25 y 30 != 48,75, o sea que el test distingue de verdad los dos casos.
     *
     * Referencia cruzada: el pipeline de descuentos en si lo cubre `3_Descuentos_Test`.
     *
     * @group compras
     * @test
     */
    public function el_prorrateo_usa_el_subtotal_bruto_aunque_la_compra_tenga_descuentos()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    // 50% de descuento de renglon SOLO en Pinza: es lo que hace que el peso bruto y
                    // el peso neto de cada item difieran.
                    $this->item('Pinza', 1000, 10, ['discount' => 50]),
                    $this->item('Alicate', 300, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $order_id = $response->json('model.id');

            ProviderOrderDiscount::create([
                'provider_order_id' => $order_id,
                'description'       => 'Descuento de compra',
                'percentage'        => 20,
            ]);

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Flete',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $response = $this->putJson('api/provider-order/'.$order_id, $payload);

            $response->assertStatus(200);

            $order = ProviderOrder::find($order_id);

            // Los totales, para dejar constancia de que la compra SI tiene descuentos aplicados y
            // el prorrateo de abajo no da 100/30 por casualidad de que los descuentos se perdieron.
            $this->assertEqualsWithDelta(
                13000,
                (float) $order->sub_total,
                self::DELTA,
                'sub_total es el subtotal BRUTO, antes de todo descuento: 1000x10 + 300x10 = 13000'
            );

            $this->assertEqualsWithDelta(
                5000,
                (float) $order->descuentos_individuales,
                self::DELTA,
                'descuentos de renglon: 50% de los 10000 de Pinza = 5000'
            );

            $this->assertEqualsWithDelta(
                1600,
                (float) $order->descuentos_compra,
                self::DELTA,
                'el descuento de compra va en cascada, sobre 13000 - 5000 = 8000: 20% de 8000 = 1600'
            );

            $this->assertEqualsWithDelta(
                7700,
                (float) $order->total,
                self::DELTA,
                'total = 13000 - 5000 - 1600 + 1300 de costo extra = 7700'
            );

            $surchage_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $surchage_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $this->assertNotNull($surchage_pinza, 'debe existir un article_surchage de transporte para Pinza');
            $this->assertNotNull($surchage_alicate, 'debe existir un article_surchage de transporte para Alicate');

            $this->assertEqualsWithDelta(
                100,
                (float) $surchage_pinza->amount,
                self::DELTA,
                'la base del prorrateo es el subtotal BRUTO (13000): 1300 * (10000/13000) / 10 = 100. Si usara el neto (8000) daria 81,25'
            );

            $this->assertEqualsWithDelta(
                30,
                (float) $surchage_alicate->amount,
                self::DELTA,
                'la base del prorrateo es el subtotal BRUTO (13000): 1300 * (3000/13000) / 10 = 30. Si usara el neto (8000) daria 48,75'
            );

            $total_prorrateado = ((float) $surchage_pinza->amount * 10) + ((float) $surchage_alicate->amount * 10);

            $this->assertEqualsWithDelta(
                1300,
                $total_prorrateado,
                0.05,
                'aun con descuentos de por medio, el prorrateo tiene que sumar el total del costo extra (1300)'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchage_transporte($pinza->id);
            $this->limpiar_surchage_transporte($alicate->id);
            // 🔴 Con update_prices = 1, el descuento de compra del 20% se materializa como
            // ArticleDiscount tagueado con Buenos Aires (materializar_descuentos_proveedor_en_
            // articulos). Sin limpiarlo, el fixture queda con descuentos que no le corresponden y
            // 3_Descuentos_Test se puede poner rojo por un motivo falso.
            $this->limpiar_descuentos_tagueados($pinza->id);
            $this->limpiar_descuentos_tagueados($alicate->id);
        }
    }

    /**
     * Test 8 (mision `costos-extra-mismo-tipo-se-pisan`) — dos costos extra del MISMO tipo en una
     * misma compra se SUMAN, no se pisan.
     *
     * Es el test del arreglo. Hasta el 24/8/2026 el segundo costo extra del mismo tipo sobreescribia
     * al primero y al costo del articulo llegaba solo el ultimo, en silencio. El caso era casi
     * inalcanzable mientras el default del formulario era `otro`; dejo de serlo el 22/8/2026, cuando
     * ese default paso a `transporte` y cargar "Flete" + "Seguro de carga" sin tocar el tipo se
     * volvio el camino natural.
     *
     * Los numeros distinguen los tres escenarios posibles, no solo dos:
     *   suma (correcto): 1300 + 2600 = 3900 -> Pinza 300/u, Alicate 90/u
     *   gana el ultimo:               2600 -> Pinza 200/u, Alicate 60/u
     *   gana el primero:              1300 -> Pinza 100/u, Alicate 30/u
     *
     * @group compras
     * @test
     */
    public function dos_costos_extra_del_mismo_tipo_se_suman()
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

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Seguro de carga',
                'value'             => 2600,
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

            $order = ProviderOrder::find($order_id);

            // Guard anti-verde-falso: los dos costos extra existen y la compra se proceso.
            $this->assertEqualsWithDelta(
                16900,
                (float) $order->total,
                self::DELTA,
                'guard: el total suma los dos costos extra (13000 + 1300 + 2600 = 16900)'
            );

            $surchages_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $surchages_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            // Se suman en UNA fila, no se crean dos filas del mismo tipo en el mismo articulo.
            $this->assertCount(
                1,
                $surchages_pinza,
                'los dos costos extra de transporte se suman en un solo article_surchage, no crean dos filas'
            );

            $this->assertCount(1, $surchages_alicate, 'idem para Alicate');

            $this->assertEqualsWithDelta(
                300,
                (float) $surchages_pinza->first()->amount,
                self::DELTA,
                '1300 + 2600 = 3900 de transporte; 3900 * (10000/13000) / 10 = 300. Si diera 200, el segundo costo extra piso al primero'
            );

            $this->assertEqualsWithDelta(
                90,
                (float) $surchages_alicate->first()->amount,
                self::DELTA,
                '3900 * (3000/13000) / 10 = 90. Si diera 60, el segundo costo extra piso al primero'
            );

            $total_prorrateado = ((float) $surchages_pinza->first()->amount * 10) + ((float) $surchages_alicate->first()->amount * 10);

            $this->assertEqualsWithDelta(
                3900,
                $total_prorrateado,
                0.05,
                'el prorrateo tiene que sumar el total de los DOS costos extra (3900), sin perder centavos'
            );

            // Y que llegue al costo real, que es lo que despues arma el margen y el precio final.
            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(
                1300,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo real de Pinza: 1000 de costo + 300 de recargo sumado = 1300 (con el bug daba 1200)'
            );

            $this->assertEqualsWithDelta(
                390,
                (float) $alicate->costo_real,
                self::DELTA,
                'costo real de Alicate: 300 de costo + 90 de recargo sumado = 390 (con el bug daba 360)'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchages_tipados($pinza->id);
            $this->limpiar_surchages_tipados($alicate->id);
        }
    }

    /**
     * Test 9 (mision `costos-extra-mismo-tipo-se-pisan`) — reconfirmar la compra NO duplica el
     * recargo sumado.
     *
     * 🔴 Es la trampa del arreglo y por eso tiene test propio: la compra se reconfirma con cada
     * `PUT api/provider-order/{id}`, y la forma "obvia" de arreglar el pisado —acumular con `+=`
     * sobre el `amount` que ya esta en la base— dejaria el recargo en 600 despues del segundo PUT,
     * en 900 despues del tercero, y asi. El arreglo correcto agrega ANTES de tocar la base y sigue
     * guardando con una asignacion, asi que el numero es siempre el mismo.
     *
     * @group compras
     * @test
     */
    public function reconfirmar_la_compra_no_duplica_el_recargo_sumado()
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

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Seguro de carga',
                'value'             => 2600,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $payload_update = $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                    $this->item('Alicate', 300, 10),
                ],
            ]);

            // Primera confirmacion.
            $this->putJson('api/provider-order/'.$order_id, $payload_update)->assertStatus(200);

            $surchage_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $this->assertNotNull($surchage_pinza, 'guard: la primera confirmacion tiene que dejar el recargo');

            $this->assertEqualsWithDelta(
                300,
                (float) $surchage_pinza->amount,
                self::DELTA,
                'guard: despues del primer PUT, el recargo de Pinza vale 300'
            );

            // Segunda confirmacion, con el MISMO payload. Es lo que hace el usuario cada vez que
            // vuelve a guardar la compra desde la pantalla.
            $this->putJson('api/provider-order/'.$order_id, $payload_update)->assertStatus(200);

            $surchages_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $surchages_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $this->assertCount(1, $surchages_pinza, 'la segunda confirmacion no agrega una fila nueva');
            $this->assertCount(1, $surchages_alicate, 'idem para Alicate');

            $this->assertEqualsWithDelta(
                300,
                (float) $surchages_pinza->first()->amount,
                self::DELTA,
                'reconfirmar la compra no duplica: sigue en 300. Si acumulara sobre el amount de la base daria 600'
            );

            $this->assertEqualsWithDelta(
                90,
                (float) $surchages_alicate->first()->amount,
                self::DELTA,
                'reconfirmar la compra no duplica: sigue en 90. Si acumulara daria 180'
            );

            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(
                1300,
                (float) $pinza->costo_real,
                self::DELTA,
                'el costo real tampoco se duplica: 1300, no 1600'
            );

            $this->assertEqualsWithDelta(
                390,
                (float) $alicate->costo_real,
                self::DELTA,
                'el costo real tampoco se duplica: 390, no 480'
            );

            $order = ProviderOrder::find($order_id);

            $this->assertEqualsWithDelta(
                16900,
                (float) $order->total,
                self::DELTA,
                'el total de la compra tampoco se duplica con la reconfirmacion'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchages_tipados($pinza->id);
            $this->limpiar_surchages_tipados($alicate->id);
        }
    }

    /**
     * Test 9 bis (mision `costos-extra-mismo-tipo-se-pisan`) — dos costos extra de tipos DISTINTOS
     * siguen generando dos recargos separados, cada uno con su monto. No regresion.
     *
     * ⚠️ Ojo al leerlo: el `costo_real` y el `total` dan los MISMOS numeros que el test de la suma
     * (la plata total es la misma: 1300 + 2600). O sea que el costo real NO distingue este caso del
     * anterior. Lo que distingue es la ESTRUCTURA de filas, por eso la asercion del `count() == 2`
     * es la importante de este test y no se puede sacar.
     *
     * @group compras
     * @test
     */
    public function dos_costos_extra_de_tipos_distintos_generan_dos_recargos_separados()
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

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Seguro',
                'value'             => 2600,
                'tipo'              => ProviderOrderExtraCost::TIPO_SEGURO,
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

            $order = ProviderOrder::find($order_id);

            $this->assertEqualsWithDelta(
                16900,
                (float) $order->total,
                self::DELTA,
                'guard: 13000 + 1300 + 2600 = 16900'
            );

            $tipos = [
                ProviderOrderExtraCost::TIPO_TRANSPORTE,
                ProviderOrderExtraCost::TIPO_SEGURO,
            ];

            // 🔴 La asercion que distingue este test del de la suma: DOS filas, una por tipo.
            $this->assertCount(
                2,
                ArticleSurchage::where('article_id', $pinza->id)->whereIn('tipo', $tipos)->get(),
                'tipos distintos generan recargos SEPARADOS: dos filas en Pinza, una de transporte y una de seguro'
            );

            $this->assertCount(
                2,
                ArticleSurchage::where('article_id', $alicate->id)->whereIn('tipo', $tipos)->get(),
                'idem para Alicate'
            );

            $transporte_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $seguro_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                            ->where('tipo', ProviderOrderExtraCost::TIPO_SEGURO)
                                            ->first();

            $this->assertEqualsWithDelta(
                100,
                (float) $transporte_pinza->amount,
                self::DELTA,
                'transporte de Pinza: 1300 * (10000/13000) / 10 = 100'
            );

            $this->assertEqualsWithDelta(
                200,
                (float) $seguro_pinza->amount,
                self::DELTA,
                'seguro de Pinza: 2600 * (10000/13000) / 10 = 200'
            );

            $transporte_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                    ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                    ->first();

            $seguro_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_SEGURO)
                                                ->first();

            $this->assertEqualsWithDelta(30, (float) $transporte_alicate->amount, self::DELTA, 'transporte de Alicate: 1300 * (3000/13000) / 10 = 30');
            $this->assertEqualsWithDelta(60, (float) $seguro_alicate->amount, self::DELTA, 'seguro de Alicate: 2600 * (3000/13000) / 10 = 60');

            // Los DOS recargos llegan al costo real con una sola llamada a setFinalPrice().
            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(
                1300,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo real de Pinza: 1000 + 100 de transporte + 200 de seguro = 1300'
            );

            $this->assertEqualsWithDelta(
                390,
                (float) $alicate->costo_real,
                self::DELTA,
                'costo real de Alicate: 300 + 30 + 60 = 390'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchages_tipados($pinza->id);
            $this->limpiar_surchages_tipados($alicate->id);
        }
    }

    /**
     * Test 10 (mision `costos-extra-mismo-tipo-se-pisan`) — el criterio "ultimo costo" ENTRE
     * compras se CONSERVA.
     *
     * 🔴 Este es el test que blinda lo que el arreglo NO tenia que romper. Son dos comportamientos
     * distintos y solo uno era un bug: dentro de una misma compra los costos extra del mismo tipo
     * se SUMAN (test 8), pero una compra POSTERIOR PISA el recargo que dejo la anterior, no lo
     * acumula. Eso ultimo esta documentado a proposito en el docblock del metodo y es lo que evita
     * que el costo del articulo crezca compra tras compra.
     *
     * @group compras
     * @test
     */
    public function el_criterio_ultimo_costo_entre_compras_se_conserva()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        $payload_items = $this->payload_compra([
            'update_stock'   => 0,
            'total_with_iva' => 0,
            'articles' => [
                $this->item('Pinza', 1000, 10),
                $this->item('Alicate', 300, 10),
            ],
        ]);

        try {
            // --- Compra A: flete de 1300 ---
            $order_a = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_a,
                'description'       => 'Flete compra A',
                'value'             => 1300,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $this->putJson('api/provider-order/'.$order_a, $payload_items)->assertStatus(200);

            $surchage_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $this->assertNotNull($surchage_pinza, 'guard: la compra A tiene que dejar el recargo');

            $this->assertEqualsWithDelta(
                100,
                (float) $surchage_pinza->amount,
                self::DELTA,
                'guard: despues de la compra A el recargo de Pinza vale 100. Si esto falla, el resto del test no prueba nada'
            );

            // --- Compra B, posterior: flete de 2600 ---
            $order_b = $this->crear_compra_base_2_items();

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_b,
                'description'       => 'Flete compra B',
                'value'             => 2600,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $this->putJson('api/provider-order/'.$order_b, $payload_items)->assertStatus(200);

            $order = ProviderOrder::find($order_b);

            $this->assertEqualsWithDelta(
                15600,
                (float) $order->total,
                self::DELTA,
                'guard: total de la compra B = 13000 + 2600 = 15600'
            );

            $surchages_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $surchages_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $this->assertCount(
                1,
                $surchages_pinza,
                'la compra nueva REEMPLAZA el recargo de la anterior, no agrega una fila'
            );

            $this->assertCount(1, $surchages_alicate, 'idem para Alicate');

            $this->assertEqualsWithDelta(
                200,
                (float) $surchages_pinza->first()->amount,
                self::DELTA,
                'criterio ultimo costo: la compra B PISA el recargo de la compra A. 2600 * (10000/13000) / 10 = 200. Si diera 300 (100 + 200) se rompio la semantica documentada entre compras'
            );

            $this->assertEqualsWithDelta(
                60,
                (float) $surchages_alicate->first()->amount,
                self::DELTA,
                'criterio ultimo costo: 2600 * (3000/13000) / 10 = 60. Acumular daria 90'
            );

            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(
                1200,
                (float) $pinza->costo_real,
                self::DELTA,
                'costo real de Pinza tras la compra B: 1000 + 200 = 1200 (acumulando daria 1300)'
            );

            $this->assertEqualsWithDelta(
                360,
                (float) $alicate->costo_real,
                self::DELTA,
                'costo real de Alicate tras la compra B: 300 + 60 = 360 (acumulando daria 390)'
            );

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchages_tipados($pinza->id);
            $this->limpiar_surchages_tipados($alicate->id);
        }
    }

    /**
     * Test 11 (mision `costos-extra-mismo-tipo-se-pisan`) — el back-out de IVA se hace POR COSTO
     * EXTRA, antes de sumar.
     *
     * Es el punto fino del arreglo: dos costos extra del mismo tipo pueden tener alicuotas
     * distintas, o uno venir facturado y el otro no. Si la agregacion sumara los brutos y neteara
     * el total con una sola alicuota, el numero saldria mal.
     *
     * Flete facturado 1210 al 21% -> neto 1000. Seguro de carga sin facturar 1600 -> entero 1600.
     * Total del tipo = 2600. Pinza = 2600 * (10000/13000) / 10 = 200.
     *
     * Los caminos equivocados dan numeros distintos, y por eso el test sirve:
     *   sumar brutos y netear todo al 21%: 2810 / 1,21 = 2322,31 -> Pinza 178,64
     *   no netear nada:                    2810                 -> Pinza 216,15
     *
     * ⚠️ El `total` de la orden suma el value BRUTO de cada costo extra (13000 + 1210 + 1600 =
     * 15810): `set_totales()` no netea nada, el back-out vive solo en el prorrateo al costo del
     * articulo.
     *
     * @group compras
     * @test
     */
    public function el_backout_de_iva_se_hace_por_costo_extra_antes_de_sumar()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $alicate = $this->articulo('Alicate');
        $snap_pinza = $this->snapshot_articulo($pinza);
        $snap_alicate = $this->snapshot_articulo($alicate);

        try {
            $order_id = $this->crear_compra_base_2_items();

            $iva_21 = Iva::where('percentage', '21')->first();

            $this->assertNotNull($iva_21, 'el fixture tiene que tener el IVA del 21%');

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Flete facturado',
                'value'             => 1210,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => true,
                'iva_id'            => $iva_21->id,
                'en_factura_compra' => true,
            ]);

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Seguro de carga',
                'value'             => 1600,
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

            $order = ProviderOrder::find($order_id);

            $this->assertEqualsWithDelta(
                15810,
                (float) $order->total,
                self::DELTA,
                'guard: el total de la orden suma el valor BRUTO de cada costo extra (13000 + 1210 + 1600)'
            );

            $surchages_pinza = ArticleSurchage::where('article_id', $pinza->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->get();

            $this->assertCount(1, $surchages_pinza, 'los dos costos extra de transporte se suman en una sola fila');

            $this->assertEqualsWithDelta(
                200,
                (float) $surchages_pinza->first()->amount,
                self::DELTA,
                'back-out individual y despues sumar: (1210/1,21) + 1600 = 2600; 2600 * (10000/13000) / 10 = 200. Sumar brutos y netear al 21% daria 178,64; no netear nada daria 216,15'
            );

            $surchage_alicate = ArticleSurchage::where('article_id', $alicate->id)
                                                ->where('tipo', ProviderOrderExtraCost::TIPO_TRANSPORTE)
                                                ->first();

            $this->assertEqualsWithDelta(
                60,
                (float) $surchage_alicate->amount,
                self::DELTA,
                '2600 * (3000/13000) / 10 = 60'
            );

            $pinza->refresh();
            $alicate->refresh();

            $this->assertEqualsWithDelta(1200, (float) $pinza->costo_real, self::DELTA, 'costo real de Pinza: 1000 + 200 = 1200');
            $this->assertEqualsWithDelta(360, (float) $alicate->costo_real, self::DELTA, 'costo real de Alicate: 300 + 60 = 360');

        } finally {
            $this->restaurar_articulo($pinza, $snap_pinza);
            $this->restaurar_articulo($alicate, $snap_alicate);
            $this->limpiar_surchages_tipados($pinza->id);
            $this->limpiar_surchages_tipados($alicate->id);
        }
    }
}
