<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * `--aplicar` sobre artículos CON variantes que reparten por sucursal (misión
 * sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Con variantes el pivot del artículo (`address_article`) es DERIVADO: lo reconstruye
 * `ArticleHelper::setArticleStockFromAddresses()` (`sync([])` + un `attach` por sucursal del
 * dueño) sumando las filas de las variantes. Los fantasmas de `address_article_variant` no suman
 * en `articles.stock` (la relación `addresses` de la variante es un INNER JOIN), pero ensucian el
 * pivot y, sobre todo, nadie los borra: `AddressController::destroy()` nunca toca ese pivot.
 *
 * Lo que el saneo tiene que dejar:
 *
 *   1. los fantasmas de variante y los del artículo borrados, y las filas vivas de las variantes
 *      intactas (mismo id, mismo amount);
 *   2. el pivot del artículo reconstruido desde las variantes (suma por sucursal);
 *   3. `article_variants.stock` = suma de sus filas vivas y `articles.stock` = suma de las
 *      variantes;
 *   4. una variante SIN filas vivas aporta su propio `article_variants.stock` (no se la pisa);
 *   5. si el stock global estaba desviado, el movimiento lleva el desvío completo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Con_variantes_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un artículo con dos variantes que reparten por sucursal y fantasmas en los dos pivots.
     *
     *  - V1: vive S1 = 6 y S2 = 4 (stock 10); fantasmas de variante en D de −1 y −1.
     *  - V2: vive S1 = 5 (stock 5);           fantasma de variante en D de +1.
     *  - fantasma del artículo en D de −2.
     *
     * El motor dejó el pivot del artículo en S1 = 11 y S2 = 4 y `articles.stock` en 15.
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno('variantes');
        $s1 = $this->sucursal($dueno, 'zz Centro');
        $s2 = $this->sucursal($dueno, 'zz Norte');
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_variantes(
            $dueno,
            'Con variantes',
            [
                ['vivas' => [$s1->id => 6, $s2->id => 4], 'fantasmas' => [[$muerta, -1], [$muerta, -1]]],
                ['vivas' => [$s1->id => 5], 'fantasmas' => [[$muerta, 1]]],
            ],
            [[$muerta, -2]]
        );

        return array_merge($e, compact('dueno', 's1', 's2', 'muerta'));
    }

    /**
     * Las filas del pivot del artículo, address_id => amount (suma por sucursal).
     *
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function pivot_por_sucursal($articulo)
    {
        $por_sucursal = [];

        foreach ($this->pivot($articulo) as $fila) {
            $address_id = (int) $fila['address_id'];

            $por_sucursal[$address_id] = (isset($por_sucursal[$address_id]) ? $por_sucursal[$address_id] : 0.0) + (float) $fila['amount'];
        }

        ksort($por_sucursal);

        return $por_sucursal;
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_borra_los_fantasmas_de_los_dos_pivots_y_reconstruye_el_pivot_del_articulo()
    {
        $e = $this->escenario();

        list($v1, $v2) = $e['variantes'];

        // El escenario es el que dice el docblock: el motor dejó 15 y los fantasmas están.
        $this->assertEquals(15.0, $this->stock($e['articulo']), 'El escenario no quedó armado: el motor tenía que dejar articles.stock en 15.');
        $this->assertSame(3, DB::table('address_article_variant')->where('address_id', $e['muerta'])->whereIn('article_variant_id', [$v1->id, $v2->id])->count(), 'Faltan los fantasmas de variante.');
        $this->assertSame(1, $this->filas_en($e['articulo'], $e['muerta']), 'Falta el fantasma del artículo.');

        // Las filas vivas de las variantes, tal cual.
        $vivas_antes = [
            $v1->id => array_values(array_filter($this->pivot_de_variante($v1), function ($fila) use ($e) {
                return (int) $fila['address_id'] !== $e['muerta'];
            })),
            $v2->id => array_values(array_filter($this->pivot_de_variante($v2), function ($fila) use ($e) {
                return (int) $fila['address_id'] !== $e['muerta'];
            })),
        ];

        $codigo = $this->aplicar($e['dueno']);

        $this->assertSame(0, $codigo, 'Salida:' . "\n" . $this->salida);

        // 1. Los fantasmas se fueron de los dos pivots.
        $this->assertSame(0, DB::table('address_article_variant')->where('address_id', $e['muerta'])->whereIn('article_variant_id', [$v1->id, $v2->id])->count(), 'Quedaron fantasmas en address_article_variant: AddressController::destroy nunca los borra y el saneo tiene que hacerlo.');
        $this->assertSame(0, $this->filas_en($e['articulo'], $e['muerta']), 'Quedó el fantasma de address_article del artículo.');

        // Y las filas vivas de las variantes, intactas.
        $this->assertSame($vivas_antes[$v1->id], $this->pivot_de_variante($v1), 'Las filas vivas de la variante V1 tienen que quedar intactas.');
        $this->assertSame($vivas_antes[$v2->id], $this->pivot_de_variante($v2), 'Las filas vivas de la variante V2 tienen que quedar intactas.');

        // 2. El pivot del artículo, derivado de las variantes.
        $this->assertEquals(
            [$e['s1']->id => 11.0, $e['s2']->id => 4.0],
            $this->pivot_por_sucursal($e['articulo']),
            'El pivot del artículo se reconstruye sumando las variantes: S1 = 6 + 5, S2 = 4.'
        );

        // 3. Los stocks.
        $this->assertEquals(10.0, (float) DB::table('article_variants')->where('id', $v1->id)->value('stock'), 'article_variants.stock de V1 = suma de sus filas vivas (6 + 4).');
        $this->assertEquals(5.0, (float) DB::table('article_variants')->where('id', $v2->id)->value('stock'), 'article_variants.stock de V2 = su fila viva.');
        $this->assertEquals(15.0, $this->stock($e['articulo']), 'articles.stock = suma de las variantes.');

        // El stock global no cambió: no hay nada que explicar con un movimiento.
        $this->assertCount(0, $this->movimientos($e['articulo']), 'Con el stock global igual no tiene que haber movimiento.');

        $this->assertStringContainsString('pivot reconstruido en 1', $this->salida, 'El resultado tiene que decir que se reconstruyó el pivot del artículo.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_el_stock_global_desviado_el_movimiento_lleva_el_desvio_completo()
    {
        $e = $this->escenario();

        // Un stock cargado a mano o arrastrado de antes: 20 cuando la suma de las variantes es 15.
        DB::table('articles')->where('id', $e['articulo']->id)->update(['stock' => 20]);

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(15.0, $this->stock($e['articulo']), 'articles.stock tiene que quedar en la suma de las variantes.');

        $movimientos = $this->movimientos($e['articulo']);

        $this->assertCount(1, $movimientos, 'Tiene que haber UN movimiento con el desvío.');

        $movimiento = $movimientos[0];

        $this->assertEquals(-5.0, (float) $movimiento->amount, 'amount = 15 − 20.');
        $this->assertEquals(20.0, (float) $movimiento->stock_anterior);
        $this->assertEquals(15.0, (float) $movimiento->stock_resultante);
        $this->assertSame((int) $e['dueno']->id, (int) $movimiento->user_id);
        $this->assertNull($movimiento->article_variant_id, 'El movimiento es del artículo, no de una variante.');

        // La foto por depósito es la del pivot del artículo: el fantasma de −2 figura en "anterior".
        $foto = json_decode($movimiento->stock_por_deposito, true);

        $this->assertIsArray($foto);

        $renglones = [];
        foreach ($foto['articulo'] as $renglon) {
            $renglones[(int) $renglon['address_id']] = $renglon;
        }

        $this->assertEquals(-2.0, $renglones[$e['muerta']]['anterior'], 'El fantasma del artículo (−2) tiene que estar en "anterior".');
        $this->assertEquals(0.0, $renglones[$e['muerta']]['resultante']);
    }

    /**
     * Una variante SIN filas vivas no reparte por sucursal: el motor suma su propio
     * `article_variants.stock` y no la toca. El saneo tiene que respetarlo (y proyectarlo igual,
     * porque `--ver` calcula el desfase con esa misma regla).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_variante_sin_filas_vivas_aporta_su_propio_stock_y_no_se_la_pisa()
    {
        $dueno = $this->dueno('variante-global');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // V3 lleva stock global 7 (sin filas vivas, solo un fantasma); V4 reparte: S1 = 3.
        $e = $this->articulo_con_variantes(
            $dueno,
            'Variante global',
            [
                ['stock' => 7, 'fantasmas' => [[$muerta, -1]]],
                ['vivas' => [$s1->id => 3]],
            ]
        );

        list($v3, $v4) = $e['variantes'];

        $this->assertEquals(10.0, $this->stock($e['articulo']), 'El escenario no quedó armado: el motor suma 7 de V3 + 3 de V4.');

        // Un stock global desviado (12 en vez de 10) para que haya desfase que medir.
        DB::table('articles')->where('id', $e['articulo']->id)->update(['stock' => 12]);

        // --ver: el desfase se calcula con el aporte de V3 (7) y no sin él (que daría 12 − 3 = 9).
        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);
        $this->assertEquals(2.0, $this->fila_del_reporte($dueno->id)['desfase'], 'El desfase es 12 − (7 + 3): una variante sin filas vivas aporta su article_variants.stock.');
        $this->assertSame(1, $this->fila_del_reporte($dueno->id)['filas_variante']);

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, DB::table('address_article_variant')->where('article_variant_id', $v3->id)->count(), 'El fantasma de V3 tiene que haberse borrado.');
        $this->assertEquals(7.0, (float) DB::table('article_variants')->where('id', $v3->id)->value('stock'), 'V3 no reparte por sucursal: su stock no se toca.');
        $this->assertEquals(3.0, (float) DB::table('article_variants')->where('id', $v4->id)->value('stock'));
        $this->assertEquals(10.0, $this->stock($e['articulo']));

        $this->assertStringNotContainsString('Stock distinto del proyectado', $this->salida, 'Lo que dejó el sistema coincide con lo proyectado: si no, el criterio y el motor discrepan.');

        $movimientos = $this->movimientos($e['articulo']);

        $this->assertCount(1, $movimientos);
        $this->assertEquals(-2.0, (float) $movimientos[0]->amount, 'amount = 10 − 12.');
    }

    /**
     * 🔴 Límite declarado en el plan: con variantes el pivot del artículo se RECONSTRUYE (como hace
     * el motor en cada movimiento de variante), así que se pierden los `stock_min` / `stock_max` por
     * sucursal de esas filas. El respaldo los tiene que guardar.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_pivot_reconstruido_pierde_stock_min_y_max_pero_el_respaldo_los_guarda()
    {
        $e = $this->escenario();

        DB::table('address_article')
            ->where('article_id', $e['articulo']->id)
            ->where('address_id', $e['s1']->id)
            ->update(['stock_min' => 2, 'stock_max' => 20]);

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $fila_nueva = DB::table('address_article')->where('article_id', $e['articulo']->id)->where('address_id', $e['s1']->id)->first();

        $this->assertNull($fila_nueva->stock_min, 'El pivot se reconstruye como en el motor: el stock_min por sucursal no sobrevive (límite declarado).');

        $lineas = $this->lineas_del_respaldo($this->carpeta_de_salida);

        $this->assertCount(1, $lineas, 'Un artículo, una línea de respaldo.');

        $fila_respaldada = null;
        foreach ($lineas[0]['address_article'] as $fila) {
            if ((int) $fila['address_id'] === (int) $e['s1']->id) {
                $fila_respaldada = $fila;
            }
        }

        $this->assertNotNull($fila_respaldada, 'El respaldo tiene que traer TAMBIÉN las filas vivas del pivot del artículo.');
        $this->assertEquals(2, $fila_respaldada['stock_min'], 'El respaldo tiene que guardar el stock_min que se pierde en la reconstrucción.');
        $this->assertEquals(20, $fila_respaldada['stock_max']);
        $this->assertFalse($fila_respaldada['fantasma'], 'La fila viva no se marca como fantasma.');
    }
}
