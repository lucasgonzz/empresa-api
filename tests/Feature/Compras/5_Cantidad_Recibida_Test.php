<?php

namespace Tests\Feature\Compras;

use App\Models\Address;
use App\Models\ProviderOrder;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de cantidad efectiva (recibida vs. pedida) del modulo de compras (Grupo 184, Prompt 616).
 *
 * Cubre `NewProviderOrderHelper::get_cantidad_efectiva()`: se usa la cantidad RECIBIDA siempre
 * que este completada (incluido `0`: "pedi 10, recibi 0"), y se cae a la PEDIDA solo si la
 * recibida esta vacia/null. `0` es falsy en PHP — confundir "recibi 0" con "no cargue nada" (un
 * chequeo `if ($received)` en vez de `isset()`/`is_null()`) es el bug exacto que este archivo
 * ataja.
 *
 * Todos los tests usan `Pinza` (costo 1000, cantidad pedida 10) y se aisla el efecto de las
 * bonificaciones de proveedor (`quitar_bonificaciones_de_buenos_aires`) y de la actualizacion de
 * costo/proveedor (`update_prices=0`) para que el `total` de la orden dependa EXCLUSIVAMENTE de
 * la cantidad efectiva, sin otros factores mezclados.
 *
 * Nota de aislamiento (fixture compartido, MyISAM sin rollback real — ver docblock de
 * `ComprasTestCase`): se restauran `cost`/`stock`/`provider_id`/`costo_real` de Pinza en un
 * try/finally en cada test.
 */
class Cantidad_Recibida_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Test 1 — cantidad recibida vacia (`null`): se debe usar la cantidad PEDIDA (10).
     *
     * @group compras
     * @test
     */
    public function recibida_vacia_se_usa_la_pedida()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'update_prices'  => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['received' => null]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                10000,
                (float) $provider_order->total,
                self::DELTA,
                'recibida vacia (null): se debe usar la cantidad PEDIDA (10 x 1000 = 10000)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }

    /**
     * Test 2 — cantidad recibida cargada (8, distinta de la pedida 10): se debe usar la
     * RECIBIDA.
     *
     * @group compras
     * @test
     */
    public function recibida_cargada_se_usa_la_recibida()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'update_prices'  => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['received' => 8]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                8000,
                (float) $provider_order->total,
                self::DELTA,
                'recibida cargada (8, pedida 10): se debe usar la RECIBIDA (8 x 1000 = 8000)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }

    /**
     * Test 3 — cantidad recibida en CERO: se debe usar 0, NO la pedida (10). Este es el test
     * que justifica el archivo entero: `0` es falsy en PHP, y un chequeo con `if ($received)`
     * en vez de `isset()`/`is_null()` trataria "recibi 0" como "no cargue nada", cayendo
     * (incorrectamente) a la cantidad pedida.
     *
     * @group compras
     * @test
     */
    public function recibida_en_cero_se_usa_0_no_la_pedida()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            $payload = $this->payload_compra([
                'update_stock'   => 0,
                'update_prices'  => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['received' => 0]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $provider_order = ProviderOrder::find($response->json('model.id'));

            $this->assertEqualsWithDelta(
                0,
                (float) $provider_order->total,
                self::DELTA,
                'recibida en CERO (pedida 10): el total debe dar 0, NO 10000. Recibir 0 no es lo '.
                'mismo que no cargar la cantidad recibida — si esto da 10000, get_cantidad_efectiva() '.
                'esta tratando el 0 como "no cargado" (bug de falsy en PHP)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }

    /**
     * Test 4 — el stock generado por la compra sigue a la cantidad RECIBIDA (8), no a la pedida
     * (10): partiendo de un stock conocido de 10, debe quedar en 18, no en 20.
     *
     * Hallazgo (a dejar asentado, no a acomodar el test): `save_stock_movement()` resuelve la
     * cantidad con `$new_article['pivot']['received'] > 0`, una condicion DISTINTA de la que usa
     * `get_cantidad_efectiva()` (`isset()`/`is_null()`, que sí distingue 0 de "vacio"). Para
     * `received = 8` (> 0) ambas condiciones coinciden y el test no expone la diferencia; para
     * `received = 0` el chequeo de stock SI caeria (incorrectamente) a la cantidad pedida,
     * mientras que el costeo ya no lo hace. Ver resumen del prompt 616 para el detalle.
     *
     * Hallazgo adicional (infra, Prompt 616): Pinza tiene un deposito (`Principal`) asignado, y
     * para un articulo CON depositos `articles.stock` es un valor DERIVADO — lo recalcula
     * `ArticleHelper::setArticleStockFromAddresses()` sumando el `amount` del pivot
     * articulo-deposito — no la fuente de verdad. Forzar solo la columna `stock` a 10 (sin tocar
     * el pivot del deposito) queda pisado apenas corre el movimiento de stock de esta compra. Por
     * eso el punto de partida controlado se fija en el pivot del deposito, no en la columna.
     *
     * @group compras
     * @test
     */
    public function el_stock_sigue_a_la_cantidad_recibida()
    {
        $this->set_condicion_iva('RRII');
        $this->quitar_bonificaciones_de_buenos_aires();

        $pinza = $this->articulo('Pinza');
        $snapshot = $this->snapshot_articulo($pinza);

        try {
            // Punto de partida controlado: el stock real de Pinza es ACUMULATIVO entre corridas
            // (otros tests de esta suite confirman compras con update_stock=1 sobre el mismo
            // articulo) y por la deuda de MyISAM (ver ComprasTestCase) no hay rollback real que
            // lo revierta solo. Se fuerza a 10 para poder verificar el delta exacto (10 + 8 = 18)
            // y se restaura al valor original (columna + pivot de deposito) en el finally.
            $deposito = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();
            $pinza->addresses()->updateExistingPivot($deposito->id, ['amount' => 10]);

            $pinza->stock = 10;
            $pinza->timestamps = false;
            $pinza->save();

            $payload = $this->payload_compra([
                'update_stock'  => 1,
                'update_prices' => 0,
                'articles' => [
                    $this->item('Pinza', 1000, 10, ['received' => 8]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $pinza->refresh();

            $this->assertEqualsWithDelta(
                18,
                (float) $pinza->stock,
                self::DELTA,
                'el stock debe seguir la cantidad RECIBIDA (10 + 8 = 18), no la pedida (10 + 10 = 20)'
            );
        } finally {
            $this->restaurar_articulo($pinza, $snapshot);
        }
    }
}
