<?php

namespace Tests\Feature\Compras;

use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Models\StockMovement;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Test de NO REGRESION del contrato de fabrica del modulo de compras (Prompt 609, contradice del
 * audio `4.0` en `multimedia/contraste/S4.md`).
 *
 * 🔴 De fabrica, una compra **solo** mueve la cuenta corriente del proveedor. No toca el stock, no
 * toca el costo, no toca los precios, no materializa descuentos ni recargos en los articulos.
 *
 * Por que esto es un contrato y no un accidente: `update_stock` y `update_prices` vienen apagados
 * (`value: 0` en `src/models/provider_order.js` de `empresa-spa`, que es el default EFECTIVO — ver
 * abajo) y llevan `no_se_puede_desactivar: true`, o sea que prenderlas es de ida. Hay
 * instalaciones que dependen justamente de eso: llevan el stock por afuera del sistema, y una
 * compra que empezara a moverlo les romperia el inventario.
 *
 * ⚠️ Dato medido el 22/8/2026, contraintuitivo, y la razon por la que este test existe:
 * la migracion `2022_06_02_172623_create_provider_orders_table.php` declara `default(1)` en las
 * tres banderas — pero **ese default no se aplica NUNCA**, porque
 * `ProviderOrderController::store()` (lineas 71-75) siempre escribe el valor que viene del
 * request. El default real lo pone el formulario de la SPA. Si alguna vez alguien "arregla" la
 * contradiccion prendiendo las banderas en el lugar equivocado, este test se pone rojo y eso es
 * exactamente lo que tiene que pasar.
 *
 * Decision de producto de Lucas (22/8/2026): las banderas **no se tocan**, se quedan apagadas por
 * default. Lo unico que se hizo del defecto es este test.
 *
 * Nota de aislamiento (fixture compartido, MyISAM sin rollback real — ver docblock de
 * `ComprasTestCase`): se snapshotea y restaura el articulo tocado en un `finally`.
 */
class Banderas_Apagadas_Test extends ComprasTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca `assertSame` sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Con `update_stock = 0` y `update_prices = 0`, una compra confirmada — con descuentos y con un
     * costo extra de un tipo que SI prorratearia — no deja ningun rastro en el articulo: ni costo,
     * ni costo real, ni precio, ni stock, ni recargos, ni descuentos tagueados. Pero si genera el
     * movimiento en la cuenta corriente del proveedor por el total final.
     *
     * El descuento y el costo extra estan a proposito: son exactamente las dos cosas que
     * `update_prices` materializaria en el articulo. Sin ellos, el test daria verde por vacio.
     *
     * @group compras
     * @test
     */
    public function con_las_banderas_apagadas_la_compra_solo_mueve_la_cuenta_corriente()
    {
        $this->set_condicion_iva('RRII');

        // Rosario no tiene bonificaciones sembradas (las del fixture son de Buenos Aires), asi que
        // no hace falta neutralizarlas: lo unico que puede tocar al articulo es esta compra.
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $marco    = $this->articulo('Marco para cama');
        $snapshot = $this->snapshot_articulo($marco);

        try {

            /*
             * El costo de la compra (80) es distinto del costo de catalogo del fixture (50) A
             * PROPOSITO: si `update_prices` se prendiera, `articles.cost` pasaria a 80 y la
             * asercion 1 se pondria roja. Con el mismo numero de los dos lados el test no probaria
             * nada.
             *
             * `update_provider => 0` porque `update_article_provider()` linkea el proveedor al
             * articulo si esa tilde del pivot viene en 1 (y `item()` la manda en 1 por default),
             * aunque `update_prices` este apagado. No es parte de lo que se mide aca.
             */
            $payload = $this->payload_compra([
                'provider_id'    => $rosario->id,
                'update_prices'  => 0,
                'update_stock'   => 0,
                'total_with_iva' => 0,
                'articles' => [
                    $this->item('Marco para cama', 80, 5, ['update_provider' => 0]),
                ],
            ]);

            $response = $this->postJson('api/provider-order', $payload);

            $response->assertStatus(201);

            $order_id = $response->json('model.id');

            // Se crean por Eloquent y no por sus endpoints, mismo criterio que
            // `4_Costos_Extra_Test`: la logica bajo prueba corre en `NewProviderOrderHelper`,
            // disparada por el PUT que reconfirma la compra.
            ProviderOrderDiscount::create([
                'provider_order_id' => $order_id,
                'description'       => 'Descuento de prueba',
                'percentage'        => 10,
            ]);

            ProviderOrderExtraCost::create([
                'provider_order_id' => $order_id,
                'description'       => 'Flete',
                'value'             => 100,
                'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => false,
                'en_factura_compra' => true,
            ]);

            $response = $this->putJson('api/provider-order/'.$order_id, $payload);

            $response->assertStatus(200);

            $order = ProviderOrder::find($order_id);

            /*
             * Asercion 0 — guard anti-verde-falso. Antes de afirmar que la compra NO hizo nada en
             * el articulo, hay que probar que la compra SI se proceso: subtotal bruto 80*5 = 400,
             * menos el 10% de descuento (40), mas el costo extra (100) = 460. `total_with_iva = 0`,
             * asi que el IVA de la factura no se suma al total.
             */
            $this->assertEqualsWithDelta(
                400,
                (float) $order->sub_total,
                self::DELTA,
                'guard: la compra tiene que haberse procesado (subtotal bruto 80 x 5 = 400)'
            );

            $this->assertEqualsWithDelta(
                460,
                (float) $order->total,
                self::DELTA,
                'guard: total = 400 de subtotal - 40 de descuento + 100 de costo extra = 460'
            );

            $marco->refresh();

            // 1 — el costo del articulo no se toca.
            $this->assertEqualsWithDelta(
                50,
                (float) $marco->cost,
                self::DELTA,
                'con update_prices=0 el costo del articulo sigue en 50 (el del catalogo), no en los 80 de la compra'
            );

            // 2 — el costo real tampoco.
            $this->assertEqualsWithDelta(
                (float) $snapshot['costo_real'],
                (float) $marco->costo_real,
                self::DELTA,
                'con update_prices=0 el costo real del articulo queda igual que antes de la compra'
            );

            // 3 — ni el precio.
            if (is_null($snapshot['price'])) {
                $this->assertNull(
                    $marco->price,
                    'con update_prices=0 el precio del articulo queda igual que antes de la compra'
                );
            } else {
                $this->assertEqualsWithDelta(
                    (float) $snapshot['price'],
                    (float) $marco->price,
                    self::DELTA,
                    'con update_prices=0 el precio del articulo queda igual que antes de la compra'
                );
            }

            // 4 — ningun movimiento de stock atado a esta compra.
            $this->assertCount(
                0,
                StockMovement::where('provider_order_id', $order_id)->get(),
                'con update_stock=0 la compra no debe dejar ningun StockMovement'
            );

            // 5 — y el stock del articulo, intacto.
            $this->assertEqualsWithDelta(
                (float) $snapshot['stock'],
                (float) $marco->stock,
                self::DELTA,
                'con update_stock=0 el stock del articulo queda igual que antes de la compra'
            );

            // 6 — el costo extra de transporte NO se prorratea: sin update_prices no hay recargo.
            $this->assertCount(
                0,
                ArticleSurchage::where('article_id', $marco->id)->get(),
                'con update_prices=0 el costo extra de transporte no se materializa como recargo del articulo'
            );

            // 7 — ni el descuento de la compra se materializa como descuento del articulo.
            $this->assertCount(
                0,
                ArticleDiscount::where('article_id', $marco->id)
                                ->where('provider_id', $rosario->id)
                                ->get(),
                'con update_prices=0 el descuento de la compra no se materializa como article_discount'
            );

            /*
             * 8 — lo unico que una compra SI hace de fabrica: el movimiento en la cuenta corriente
             * del proveedor, por el total final.
             *
             * ⚠️ Se assertea `debe` y NO `saldo`: el saldo es acumulativo y, sin rollback real de
             * MyISAM, arrastra lo que hayan dejado otras corridas. `debe` es absoluto por orden.
             */
            $current_acount = CurrentAcount::where('provider_order_id', $order_id)->first();

            $this->assertNotNull(
                $current_acount,
                'generate_current_acount viene prendido de fabrica: la compra tiene que dejar su movimiento de cuenta corriente'
            );

            $this->assertEqualsWithDelta(
                460,
                (float) $current_acount->debe,
                self::DELTA,
                'el movimiento de cuenta corriente va por el total final de la compra (460)'
            );

        } finally {
            $this->restaurar_articulo($marco, $snapshot);
        }
    }
}
