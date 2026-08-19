<?php

namespace Tests\Feature\Compras;

use App\Models\ArticleDiscount;
use App\Models\ProviderOrder;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de descuentos del modulo de compras (Grupo 184, Prompt 616).
 *
 * Cubre tres reglas de negocio de `NewProviderOrderHelper` (ver
 * `precargar_bonificaciones_proveedor()` y `materializar_descuentos_proveedor_en_articulos()`):
 *
 * 1. Las bonificaciones del proveedor (`provider_discounts`, dato maestro) se precargan como
 *    `provider_order_discounts` editables al crear la orden.
 * 2. Esos descuentos se aplican en CASCADA sobre el total de articulos (no se suman los
 *    porcentajes y se aplican de una sola vez): 10% y 5% en cascada da 8550, no 8500.
 * 3. Se materializan como `article_discounts` tagueados con el `provider_id` de la compra, con
 *    semantica OVERWRITE (no acumulacion): confirmar la misma compra dos veces no duplica.
 *
 * Los numeros esperados son la especificacion del prompt 616 — prohibido ajustar el valor
 * esperado para que coincida con lo que devuelve el sistema.
 *
 * Nota de aislamiento (fixture compartido, MyISAM sin rollback real — ver docblock de
 * `ComprasTestCase`): estos tests usan `Pinza` (Buenos Aires) A PROPOSITO CON sus bonificaciones
 * de catalogo intactas (10%/5%, sembradas por `TestingFerreteriaSeeder`), porque son justamente
 * lo que se esta probando. Se restauran `cost`/`stock`/`provider_id`/`costo_real` del articulo en
 * un try/finally (`snapshot_articulo`/`restaurar_articulo`) y se borran los `article_discounts`
 * tagueados que cada test crea, para no dejar residuo entre corridas ni contaminar otros archivos
 * de esta misma suite. `update_stock` se fuerza a 0 en todos estos tests (no es parte de lo que
 * se prueba acá y evita sumar más al stock, que es acumulativo, no absoluto).
 */
class Descuentos_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Limpia los `article_discounts` tagueados con Buenos Aires que la compra de este test haya
     * materializado, para dejar el articulo sin residuo de cara a otros tests/archivos.
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
     * Test 1 — al crear la orden sin mandar descuentos propios, las bonificaciones del
     * proveedor (10% y 5% de Buenos Aires, dato maestro) se precargan automaticamente como
     * `provider_order_discounts` editables de esta orden puntual.
     *
     * @group compras
     * @test
     */
    public function las_bonificaciones_del_proveedor_se_precargan_en_la_orden()
    {
        $this->set_condicion_iva('RRII');

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));
            $provider_order->load('provider_order_discounts');

            $this->assertCount(
                2,
                $provider_order->provider_order_discounts,
                'la orden debe quedar con 2 provider_order_discounts precargados (10% y 5% de Buenos Aires)'
            );

            $percentajes = $provider_order->provider_order_discounts
                                            ->pluck('percentage')
                                            ->map(function ($p) { return (float) $p; })
                                            ->sort()
                                            ->values()
                                            ->all();

            $this->assertEqualsWithDelta(
                [5.0, 10.0],
                $percentajes,
                self::DELTA,
                'los porcentajes precargados deben ser exactamente 5 y 10 (los de Buenos Aires en el fixture)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
            $this->limpiar_descuentos_tagueados($pinza->id);
        }
    }

    /**
     * Test 2 — la cascada, no la suma: 10% de 10000 = 1000 (queda 9000), 5% de 9000 = 450
     * (queda 8550). `descuentos_compra` debe ser 1450, NO 1500 (que seria sumar 10+5 y aplicar
     * de una sola vez). Se fuerza `total_with_iva=0` para aislar el efecto del descuento del
     * efecto del IVA sobre `total`.
     *
     * @group compras
     * @test
     */
    public function la_cascada_no_la_suma()
    {
        $this->set_condicion_iva('RRII');

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                10000,
                (float) $provider_order->sub_total,
                self::DELTA,
                'sub_total de la compra (Pinza 1000 x 10)'
            );

            $this->assertEqualsWithDelta(
                1450,
                (float) $provider_order->descuentos_compra,
                self::DELTA,
                'descuentos_compra en cascada: 10% de 10000 (1000) + 5% de 9000 (450) = 1450. '.
                'NO debe dar 1500 (que seria sumar 10%+5%=15% y aplicarlo de una sola vez)'
            );

            $this->assertEqualsWithDelta(
                8550,
                (float) $provider_order->total,
                self::DELTA,
                'total = 10000 - 1450 (sin IVA, total_with_iva=0). 8550 es la cascada real; '.
                '8500 seria el resultado (erroneo) de sumar los porcentajes'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
            $this->limpiar_descuentos_tagueados($pinza->id);
        }
    }

    /**
     * Test 3 — un proveedor sin bonificaciones de catalogo (Rosario) no precarga ni aplica
     * ningun descuento de compra: `descuentos_compra` = 0 y el total queda igual al subtotal.
     *
     * @group compras
     * @test
     */
    public function el_proveedor_sin_bonificaciones_no_descuenta_nada()
    {
        $this->set_condicion_iva('RRII');

        $pata_de_cama = $this->articulo('Pata de cama');
        $snapshot = $this->snapshot_articulo($pata_de_cama);
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        try {
            $payload = $this->payload_compra([
                'provider_id'    => $rosario->id,
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pata de cama', 50, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));
            $provider_order->load('provider_order_discounts');

            $this->assertCount(
                0,
                $provider_order->provider_order_discounts,
                'Rosario no tiene bonificaciones de catalogo: no debe precargarse ningun descuento'
            );

            $this->assertEqualsWithDelta(
                0,
                (float) $provider_order->descuentos_compra,
                self::DELTA,
                'descuentos_compra debe ser 0 sin bonificaciones de proveedor'
            );

            $this->assertEqualsWithDelta(
                500,
                (float) $provider_order->total,
                self::DELTA,
                'total (Pata de cama 50 x 10, sin descuentos, sin IVA)'
            );
        } finally {
            $this->restaurar_articulo($pata_de_cama, $snapshot);
        }
    }

    /**
     * Test 4 — al confirmar la compra a Buenos Aires, los `article_discounts` de Pinza quedan
     * materializados y tagueados con el `provider_id` de esa compra.
     *
     * @group compras
     * @test
     */
    public function los_descuentos_se_materializan_tagueados_con_el_proveedor()
    {
        $this->set_condicion_iva('RRII');

        $pinza = $this->articulo('Pinza');
        $bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $discounts = ArticleDiscount::where('article_id', $pinza->id)
                                        ->where('provider_id', $bsas->id)
                                        ->get();

            $this->assertCount(
                2,
                $discounts,
                'Pinza debe quedar con 2 article_discounts materializados y tagueados al proveedor de la compra (10% y 5%)'
            );

            foreach ($discounts as $discount) {
                $this->assertEquals(
                    $bsas->id,
                    $discount->provider_id,
                    'cada article_discount materializado debe estar tagueado con el provider_id de la compra que lo origino'
                );
            }
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
            $this->limpiar_descuentos_tagueados($pinza->id);
        }
    }

    /**
     * Test 5 — overwrite, no acumulacion: confirmar/actualizar dos veces la misma compra no
     * debe duplicar los `article_discounts` tagueados, y el costo real del articulo no debe
     * moverse entre la primera y la segunda confirmacion (mismos datos de entrada).
     *
     * @group compras
     * @test
     */
    public function overwrite_no_acumulacion()
    {
        $this->set_condicion_iva('RRII');

        $pinza = $this->articulo('Pinza');
        $bsas = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS);
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $order_id = $response->json('model.id');

            $cantidad_descuentos_1ra_confirmacion = ArticleDiscount::where('article_id', $pinza->id)
                                                                    ->where('provider_id', $bsas->id)
                                                                    ->count();

            $costo_real_1ra_confirmacion = (float) $this->articulo('Pinza')->costo_real;

            // Segunda confirmacion (update) de la MISMA compra, con los mismos datos de entrada.
            $response = $this->putJson('api/provider-order/'.$order_id, $payload);

            $response->assertStatus(200);

            $cantidad_descuentos_2da_confirmacion = ArticleDiscount::where('article_id', $pinza->id)
                                                                    ->where('provider_id', $bsas->id)
                                                                    ->count();

            $costo_real_2da_confirmacion = (float) $this->articulo('Pinza')->costo_real;

            $this->assertEquals(
                $cantidad_descuentos_1ra_confirmacion,
                $cantidad_descuentos_2da_confirmacion,
                'confirmar/actualizar la misma compra 2 veces NO debe duplicar los article_discounts tagueados (overwrite, no acumulacion)'
            );

            $this->assertEqualsWithDelta(
                $costo_real_1ra_confirmacion,
                $costo_real_2da_confirmacion,
                self::DELTA,
                'el costo real del articulo no debe moverse entre la 1ra y la 2da confirmacion (mismos datos de entrada)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
            $this->limpiar_descuentos_tagueados($pinza->id);
        }
    }

    /**
     * Test 6 — descuento individual de linea + descuento de compra: el descuento individual
     * (10% de linea) se resta primero, y la cascada del proveedor se aplica sobre el subtotal
     * YA NETEADO de ese descuento individual (no sobre el bruto), tal como lo hace
     * `NewProviderOrderHelper::set_totales()` (variable `$total_solo_con_descuentos_individuales`).
     *
     * @group compras
     * @test
     */
    public function descuento_individual_de_linea_mas_descuento_de_compra()
    {
        $this->set_condicion_iva('RRII');

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['discount' => 10]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                1000,
                (float) $provider_order->descuentos_individuales,
                self::DELTA,
                'descuento individual de linea: 10% de 10000 = 1000'
            );

            $this->assertEqualsWithDelta(
                1305,
                (float) $provider_order->descuentos_compra,
                self::DELTA,
                'cascada del proveedor sobre el subtotal ya neteado del descuento individual (9000, no 10000): '.
                '10% de 9000 = 900 (queda 8100), 5% de 8100 = 405 (queda 7695); 900 + 405 = 1305'
            );

            $this->assertEqualsWithDelta(
                2305,
                (float) $provider_order->total_descuento,
                self::DELTA,
                'total_descuento = individuales (1000) + compra (1305) = 2305'
            );

            $this->assertEqualsWithDelta(
                7695,
                (float) $provider_order->total,
                self::DELTA,
                'total = 10000 - 2305 (sin IVA, total_with_iva=0)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
            $this->limpiar_descuentos_tagueados($pinza->id);
        }
    }
}
