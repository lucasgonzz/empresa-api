<?php

namespace Tests\Feature\Puntos;

use App\Models\CurrentAcount;
use App\Models\MovimientoPuntoConsumo;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 8 — la REVERSIÓN: devoluciones, notas de crédito, borrado y edición de la venta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL AGUJERO QUE ESTO TAPA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una nota de crédito es un `haber` que se imputa DIRIGIDO al débito de la venta (`to_pay_id`,
 *  CurrentAcountHelper.php:199-213). O sea que una venta devuelta entera llega a
 *  `status = 'pagado'` SIN QUE EL CLIENTE HAYA PAGADO UN PESO. Con la regla ingenua ("el débito
 *  está pagado, dale los puntos"), devolver una venta REGALA puntos.
 *
 *  Los tapones son dos y los dos se prueban acá:
 *    1. `article_sale.returned_amount` por renglón, que cubre las devoluciones con items;
 *    2. el factor por nota de crédito, para las NC de monto libre SIN items.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 Y EL INVARIANTE DE LA REVERSIÓN
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Se revierte EL TOTAL OTORGADO, no el remanente, aunque el cliente ya haya canjeado esos puntos
 *  y el saldo quede NEGATIVO. Perdonar la diferencia sería un agujero: se le anula la venta al que
 *  ya se llevó el descuento y el negocio paga dos veces.
 */
class Reversion_Test extends PuntosTestCase
{
    /** Total con IVA de la venta de referencia. Neto $100.000 -> 100 puntos. */
    const TOTAL_VENTA = 121000;

    /**
     * (a) DEVOLUCIÓN PARCIAL por renglón: `returned_amount` baja la base y el MISMO lote se
     * recalcula, no se duplica.
     *
     * Es el tapón que cubre las devoluciones con items, que son las de los flujos de venta y del
     * módulo de devoluciones.
     *
     * @group puntos
     * @test
     */
    public function devolver_unidades_baja_los_puntos_en_el_mismo_lote()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // Dos unidades a $60.500: neto $100.000 -> 100 puntos.
        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 60500,
                    'amount'       => 2,
                ],
            ],
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote);
        $this->assertEqualsWithDelta(100, (float) $lote->puntos, self::DELTA);

        // Se devuelve una de las dos unidades.
        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA, [
            'items' => [
                [
                    'is_article'      => true,
                    'id'              => $this->articulo_de_puntos()->id,
                    'price_vender'    => 60500,
                    'amount'          => 2,
                    'returned_amount' => 1,
                ],
            ],
        ]))->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados, 'La devolución no puede duplicar el lote.');
        $this->assertEquals($lote->id, $ganados->first()->id, 'El lote se borró y se recreó en vez de recalcularse.');
        $this->assertEqualsWithDelta(
            50,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'Devolver una de las dos unidades tiene que dejar la mitad de los puntos.'
        );
        $this->assertEqualsWithDelta(50000, (float) $ganados->first()->monto_base, self::DELTA);

        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta, 'revertidos'),
            'El lote sigue vivo (bajó, no se anuló): no puede tener un reverso.'
        );
    }

    /**
     * Devolver TODAS las unidades deja la base en cero, y ahí sí el lote se anula y aparece su
     * reverso por el total otorgado.
     *
     * @group puntos
     * @test
     */
    public function devolver_todas_las_unidades_anula_el_lote_y_escribe_su_reverso()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => 60500,
                    'amount'       => 2,
                ],
            ],
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote);

        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA, [
            'items' => [
                [
                    'is_article'      => true,
                    'id'              => $this->articulo_de_puntos()->id,
                    'price_vender'    => 60500,
                    'amount'          => 2,
                    'returned_amount' => 2,
                ],
            ],
        ]))->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');
        $revertidos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $ganados);
        $this->assertEquals($lote->id, $ganados->first()->id);
        $this->assertNotNull($ganados->first()->anulado_at, 'Con todo devuelto el lote tiene que quedar anulado.');

        $this->assertCount(1, $revertidos, 'Tiene que aparecer UN movimiento revertidos.');
        $this->assertEqualsWithDelta(-100, (float) $revertidos->first()->puntos, self::DELTA);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Con la venta devuelta entera el saldo del cliente tiene que volver a cero.'
        );
    }

    /**
     * 🔴 (b) NOTA DE CRÉDITO DE MONTO LIBRE, SIN ITEMS.
     *
     * `POST api/devoluciones` con `items` vacío y sin actualizar unidades devueltas crea una NC
     * atada a la venta que no toca ningún `returned_amount`. El primer tapón no la ve; la tapa el
     * factor por nota de crédito: `base_final = min(base_neta, base_bruta * (1 - nc_total/total))`.
     *
     * Con una NC por la mitad del total, la base se parte al medio y los puntos también.
     *
     * @group puntos
     * @test
     */
    public function una_nota_de_credito_sin_items_baja_los_puntos_por_el_factor()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote);
        $this->assertEqualsWithDelta(100, (float) $lote->puntos, self::DELTA);

        // NC por la mitad del total, sin un solo item.
        $this->postJson('api/devoluciones', [
            'sale_id'                   => $venta->id,
            'client_id'                 => $cliente->id,
            'total_devolucion'          => self::TOTAL_VENTA / 2,
            'observaciones'             => 'Nota de crédito de monto libre (test de puntos)',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'generar_current_acount'    => 1,
            'update_unidades_devueltas' => 0,
            'regresar_stock'            => 0,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        $nc = CurrentAcount::where('sale_id', $venta->id)->where('status', 'nota_credito')->first();

        $this->assertNotNull($nc, 'La nota de crédito tiene que quedar atada a la venta (si no, el factor no la ve).');

        $pivots = \Illuminate\Support\Facades\DB::table('article_sale')->where('sale_id', $venta->id)->get();

        foreach ($pivots as $pivot) {
            $this->assertTrue(
                is_null($pivot->returned_amount) || (float) $pivot->returned_amount == 0,
                'La NC de monto libre no puede haber tocado returned_amount: si lo tocó, este test mide el OTRO tapón.'
            );
        }

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados);
        $this->assertEqualsWithDelta(
            50,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'Una NC por la mitad del total tiene que dejar la mitad de los puntos.'
        );
    }

    /**
     * Y una NC por el TOTAL de la venta deja la base en cero: el lote se anula y aparece su reverso.
     * Es el caso que, sin tapón, habría REGALADO puntos (el débito llega a `pagado` por la NC, sin
     * que el cliente haya pagado un peso).
     *
     * @group puntos
     * @test
     */
    public function una_nota_de_credito_por_el_total_revierte_los_puntos()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();
        $this->assertNotNull($lote);

        $this->postJson('api/devoluciones', [
            'sale_id'                   => $venta->id,
            'client_id'                 => $cliente->id,
            'total_devolucion'          => self::TOTAL_VENTA,
            'observaciones'             => 'Devolución total (test de puntos)',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'generar_current_acount'    => 1,
            'update_unidades_devueltas' => 0,
            'regresar_stock'            => 0,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');
        $revertidos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $ganados);
        $this->assertNotNull($ganados->first()->anulado_at, 'Con la venta devuelta entera el lote tiene que quedar anulado.');
        $this->assertCount(1, $revertidos);
        $this->assertEqualsWithDelta(-100, (float) $revertidos->first()->puntos, self::DELTA);

        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * 🔴 (c) BORRAR LA VENTA revierte LOS DOS LADOS: los puntos que otorgó y los que se canjearon
     * en ella.
     *
     * El canje se deshace devolviendo los puntos a los lotes EXACTOS de los que salieron —para eso
     * existe `movimiento_punto_consumos`— y el lote de la propia venta se anula con su reverso.
     * El saldo del cliente tiene que volver al valor que tenía antes de la venta.
     *
     * @group puntos
     * @test
     */
    public function borrar_la_venta_revierte_los_puntos_ganados_y_deshace_el_canje()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa([
            'valor_punto'     => 100,
            'minimo_canje'    => 10,
            'tope_porcentaje' => 20,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $lote_previo = $this->sembrar_lote($cliente, $sistema, 30, Carbon::now()->addMonths(12), Carbon::now()->subDays(20));

        $saldo_antes = $this->saldo_de_puntos($cliente);

        $this->assertEqualsWithDelta(30, $saldo_antes, self::DELTA);

        // Venta bruta $121.000 con canje de 15 puntos ($1.500). Tope: 20% de 121.000 = 24.200.
        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA - 1500, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => 15,
            'descuento_puntos'           => 1500,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_VENTA,
                    'amount'       => 1,
                ],
            ],
        ]));

        $canje = $this->movimientos_de_la_venta($venta, 'canjeados')->first();
        $ganado = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($canje, 'La venta tiene que haber registrado su canje.');
        $this->assertNotNull($ganado, 'La venta tiene que haber otorgado sus propios puntos.');

        $lote_previo->refresh();
        $this->assertEqualsWithDelta(15, (float) $lote_previo->consumido, self::DELTA, 'El canje tiene que haber consumido el lote previo.');

        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(200);

        // El canje se deshizo: sin movimiento, sin consumos, y el lote previo volvió a estar entero.
        $this->assertCount(
            0,
            $this->movimientos_de_la_venta($venta, 'canjeados'),
            'Al borrar la venta el movimiento de canje tiene que desaparecer.'
        );
        $this->assertEquals(
            0,
            MovimientoPuntoConsumo::where('movimiento_consumo_id', $canje->id)->count(),
            'Las filas de consumo del canje tienen que borrarse.'
        );

        $lote_previo->refresh();
        $this->assertEqualsWithDelta(
            0,
            (float) $lote_previo->consumido,
            self::DELTA,
            'Los puntos tienen que volver al lote EXACTO del que salieron.'
        );

        // Y los puntos que la venta otorgó se revirtieron.
        $ganado->refresh();

        $this->assertNotNull($ganado->anulado_at, 'El lote de una venta borrada tiene que quedar anulado.');
        $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'revertidos'));

        $this->assertEqualsWithDelta(
            $saldo_antes,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Después de borrar la venta, el saldo tiene que volver exactamente al que había antes de ella.'
        );
    }

    /**
     * (d) EDITAR la venta bajándole el total recalcula el lote en el lugar: mismo id, puntos nuevos,
     * ni una fila de más.
     *
     * @group puntos
     * @test
     */
    public function editar_la_venta_bajando_el_total_recalcula_el_lote_sin_duplicarlo()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote);
        $this->assertEqualsWithDelta(100, (float) $lote->puntos, self::DELTA);

        // Se le baja el precio del renglón a la mitad.
        $this->actualizar_venta($venta, $this->payload_actualizar($venta, 60500))->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados, 'Editar la venta no puede dejar dos lotes.');
        $this->assertEquals($lote->id, $ganados->first()->id, 'El lote tiene que ser el MISMO: se actualiza en el lugar.');
        $this->assertEqualsWithDelta(
            50,
            (float) $ganados->first()->puntos,
            self::DELTA,
            'Bajar el total a la mitad tiene que dejar la mitad de los puntos.'
        );
        $this->assertEqualsWithDelta(50000, (float) $ganados->first()->monto_base, self::DELTA);
        $this->assertNull($ganados->first()->anulado_at);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'revertidos'));

        $this->assertEqualsWithDelta(50, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * Editar una venta SACÁNDOLE el cliente revierte todo: sin cliente no hay a quién acreditarle
     * los puntos, y los que ya estaban acreditados dejaron de corresponder.
     *
     * Es el espejo exacto de `la_venta_sin_cliente_no_acumula_nada` (archivo 4): si una venta sin
     * cliente no puede otorgar puntos, una venta que se queda sin cliente no puede conservarlos.
     *
     * @group puntos
     * @test
     */
    public function sacarle_el_cliente_a_la_venta_revierte_sus_puntos()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();
        $this->assertNotNull($lote);

        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA, [
            'client_id' => null,
        ]))->assertStatus(200);

        $lote->refresh();

        $this->assertNotNull(
            $lote->anulado_at,
            'Una venta que se quedó sin cliente no puede seguir teniendo puntos otorgados.'
        );
        $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA);
    }

    /**
     * Editar una venta CAMBIÁNDOLE el cliente mueve los puntos al cliente nuevo.
     *
     * Es el mismo hecho que el test de arriba visto desde el otro lado: la venta ya no es del
     * cliente A, así que A no puede seguir con esos puntos en el saldo.
     *
     * @group puntos
     * @test
     */
    public function cambiarle_el_cliente_a_la_venta_mueve_los_puntos_al_cliente_nuevo()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente_a = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);
        $cliente_b = $this->cliente(TestingFerreteriaSeeder::CLIENTE_EXENTO);

        $venta = $this->crear_venta($this->payload_venta($cliente_a->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertEqualsWithDelta(100, $this->saldo_de_puntos($cliente_a), self::DELTA);

        $this->actualizar_venta($venta, $this->payload_actualizar($venta, self::TOTAL_VENTA, [
            'client_id' => $cliente_b->id,
        ]))->assertStatus(200);

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente_a),
            self::DELTA,
            'La venta dejó de ser del cliente A: A no puede conservar los puntos que otorgaba.'
        );
        $this->assertEqualsWithDelta(
            100,
            $this->saldo_de_puntos($cliente_b),
            self::DELTA,
            'Los puntos de la venta tienen que quedar en el cliente al que ahora pertenece.'
        );
    }

    /**
     * 🔴 Se revierte EL TOTAL OTORGADO, aunque el cliente ya haya canjeado esos puntos, y el saldo
     * puede quedar NEGATIVO.
     *
     * Perdonar la diferencia sería un agujero: se le anula la venta al que ya se llevó el descuento
     * y el negocio paga dos veces. Un saldo negativo bloquea el canje solo (nunca llega al mínimo)
     * y se ve en la ficha del cliente.
     *
     * @group puntos
     * @test
     */
    public function revertir_una_venta_cuyos_puntos_ya_se_canjearon_puede_dejar_el_saldo_negativo()
    {
        $this->dar_extencion();

        $this->crear_programa([
            'valor_punto'     => 100,
            'minimo_canje'    => 10,
            'tope_porcentaje' => 20,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // Venta 1: otorga 100 puntos.
        $venta_uno = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertEqualsWithDelta(100, $this->saldo_de_puntos($cliente), self::DELTA);

        // Venta 2: canjea 20 de esos 100 puntos ($2.000 de descuento sobre $121.000).
        $venta_dos = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_VENTA - 2000, [
            'omitir_en_cuenta_corriente' => 1,
            'puntos_canjeados'           => 20,
            'descuento_puntos'           => 2000,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_VENTA,
                    'amount'       => 1,
                ],
            ],
        ]));

        $puntos_de_la_dos = (float) $this->movimientos_de_la_venta($venta_dos, 'ganados')->sum('puntos');

        $saldo_con_canje = $this->saldo_de_puntos($cliente);

        $this->assertEqualsWithDelta(100 - 20 + $puntos_de_la_dos, $saldo_con_canje, self::DELTA);

        // Ahora se anula la venta 1, cuyos puntos ya se usaron en parte.
        $this->deleteJson('api/sale/'.$venta_uno->id)->assertStatus(200);

        $this->assertEqualsWithDelta(
            $saldo_con_canje - 100,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'La reversión tiene que descontar los 100 puntos ENTEROS, no solo los que quedaban sin usar.'
        );
    }
}
