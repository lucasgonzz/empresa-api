<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * `--aplicar` sobre artículos SIN variantes (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * El caso de 3DTisk: un artículo con filas en sucursales vivas y filas fantasma de una sucursal
 * borrada. `articles.stock` quedó como la suma CRUDA de todas (fantasmas incluidas), distinta de la
 * suma de lo que el usuario ve. Lo que el saneo tiene que dejar:
 *
 *   1. las filas fantasma borradas (todas, también las repetidas del mismo par);
 *   2. las filas vivas intactas (mismo id, mismo amount);
 *   3. `articles.stock` = suma de las sucursales vivas;
 *   4. UN movimiento por artículo, con `amount` = stock nuevo − stock viejo (con signo), el concepto
 *      y la observación del precedente de 3DTisk, el dueño del ARTÍCULO como `user_id`, y las
 *      columnas que completa el motor (`stock_anterior`, `stock_resultante`, `stock_por_deposito`)
 *      coherentes entre sí.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Sin_variantes_Test extends SaneoStockSucursalesTestCase
{
    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_borra_los_fantasmas_deja_el_stock_en_la_suma_de_las_sucursales_vivas_y_un_movimiento()
    {
        $dueno = $this->dueno('sin-variantes');
        $s1 = $this->sucursal($dueno, 'zz Centro');
        $s2 = $this->sucursal($dueno, 'zz Norte');
        $muerta = $this->sucursal_muerta($dueno);

        // 10 + 4 vivos; tres filas fantasma del MISMO par (−3, −1, +2): cada venta contra la
        // sucursal muerta abrió una fila nueva. El stock crudo es 10 + 4 − 3 − 1 + 2 = 12.
        $e = $this->articulo_con_fantasmas($dueno, 'Sin variantes', [$s1->id => 10, $s2->id => 4], [[$muerta, -3], [$muerta, -1], [$muerta, 2]]);
        $articulo = $e['articulo'];

        $this->assertEquals(12.0, $this->stock($articulo), 'El escenario no quedó armado: el stock crudo tenía que ser 12.');
        $this->assertSame(3, $this->filas_en($articulo, $muerta), 'El escenario no quedó armado: tenían que ser 3 filas fantasma del mismo par.');

        $vivas_antes = [];
        foreach ($this->pivot($articulo) as $fila) {
            if ((int) $fila['address_id'] !== $muerta) {
                $vivas_antes[(int) $fila['id']] = $fila;
            }
        }

        $codigo = $this->aplicar($dueno);

        $this->assertSame(0, $codigo, 'El saneo tenía que terminar bien. Salida:' . "\n" . $this->salida);

        // 1 y 2: los fantasmas se fueron y las vivas quedaron EXACTAMENTE como estaban.
        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'Quedaron filas fantasma del artículo: el saneo no las borró todas.');

        $vivas_despues = [];
        foreach ($this->pivot($articulo) as $fila) {
            $vivas_despues[(int) $fila['id']] = $fila;
        }

        $this->assertSame($vivas_antes, $vivas_despues, 'Las filas vivas tienen que quedar intactas (mismo id, mismo amount, mismos timestamps).');

        // 3: el stock global es la suma de lo que se ve.
        $this->assertEquals(14.0, $this->stock($articulo), 'articles.stock tiene que ser la suma de las sucursales vivas (10 + 4).');

        // 4: UN movimiento, del saneo.
        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos, 'Tiene que haber UN solo movimiento por artículo afectado (el artículo no tenía ninguno antes).');

        $movimiento = $movimientos[0];

        $this->assertSame(
            (int) DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->value('id'),
            (int) $movimiento->concepto_stock_movement_id,
            'El movimiento tiene que llevar el concepto "' . self::CONCEPTO . '".'
        );

        $this->assertEquals(2.0, (float) $movimiento->amount, 'amount = stock nuevo − stock viejo = 14 − 12.');
        $this->assertSame(self::OBSERVACION . ' - 14', $movimiento->observations, 'La observación es la del precedente de 3DTisk, más el " - <stock>" que agrega el motor.');
        $this->assertSame((int) $dueno->id, (int) $movimiento->user_id, 'El user_id del movimiento tiene que ser el dueño del ARTÍCULO (no el usuario logueado ni config(app.USER_ID)).');
        $this->assertNull($movimiento->employee_id);
        $this->assertNull($movimiento->from_address_id, 'El saneo no mueve stock de ninguna sucursal.');
        $this->assertNull($movimiento->to_address_id, 'El saneo no mueve stock de ninguna sucursal.');
        $this->assertNull($movimiento->article_variant_id);
        $this->assertNull($movimiento->sale_id);

        // Columnas que completa el motor, coherentes con lo que hizo el saneo.
        $this->assertEquals(12.0, (float) $movimiento->stock_anterior, 'stock_anterior es el stock global antes del saneo.');
        $this->assertEquals(14.0, (float) $movimiento->stock_resultante, 'stock_resultante es el stock global después del saneo (leído de articles.stock).');
        $this->assertEqualsWithDelta((float) $movimiento->stock_anterior + (float) $movimiento->amount, (float) $movimiento->stock_resultante, 0.001, 'stock_anterior + amount tiene que dar stock_resultante.');

        $foto = json_decode($movimiento->stock_por_deposito, true);

        $this->assertIsArray($foto, 'El movimiento tiene que traer la foto stock_por_deposito (el artículo reparte por sucursales vivas).');

        $renglones = [];
        foreach ($foto['articulo'] as $renglon) {
            $renglones[(int) $renglon['address_id']] = $renglon;
        }

        $this->assertArrayHasKey($muerta, $renglones, 'La sucursal borrada tiene que figurar en la foto: es de donde salió el desfase.');
        $this->assertEquals(-2.0, $renglones[$muerta]['anterior'], 'En "anterior" la sucursal borrada tiene la suma de sus filas fantasma (−3 −1 +2).');
        $this->assertEquals(0.0, $renglones[$muerta]['resultante'], 'En "resultante" la sucursal borrada ya no tiene nada.');
        $this->assertNull($renglones[$muerta]['deposito'], 'Una sucursal que no existe no tiene nombre.');

        $this->assertEquals(10.0, $renglones[$s1->id]['anterior']);
        $this->assertEquals(10.0, $renglones[$s1->id]['resultante']);
        $this->assertEquals(4.0, $renglones[$s2->id]['anterior']);
        $this->assertEquals(4.0, $renglones[$s2->id]['resultante']);

        // La invariante del libro: la suma de los "resultante" de la foto es el stock_resultante.
        $suma = 0.0;
        foreach ($foto['articulo'] as $renglon) {
            $suma += $renglon['resultante'];
        }

        $this->assertEqualsWithDelta((float) $movimiento->stock_resultante, $suma, 0.001, 'La suma de los depósitos de la foto tiene que ser el stock_resultante del movimiento.');
    }

    /**
     * Un fantasma NEGATIVO inflaba el hueco (el stock global quedaba por debajo de lo que se ve) y
     * uno POSITIVO lo contrario: el movimiento tiene que llevar el signo de la corrección.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_fantasma_negativo_sube_el_stock_y_uno_positivo_lo_baja()
    {
        $dueno = $this->dueno('signos');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Una venta contra la sucursal muerta: −5 en la fila fantasma, el stock global quedó en 5.
        $negativo = $this->articulo_con_fantasmas($dueno, 'Fantasma negativo', [$s1->id => 10], [[$muerta, -5]])['articulo'];

        // Una anulación contra la sucursal muerta: +5 en la fila fantasma, el stock global quedó en 15.
        $positivo = $this->articulo_con_fantasmas($dueno, 'Fantasma positivo', [$s1->id => 10], [[$muerta, 5]])['articulo'];

        $this->assertEquals(5.0, $this->stock($negativo));
        $this->assertEquals(15.0, $this->stock($positivo));

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(10.0, $this->stock($negativo), 'El fantasma negativo se llevaba 5 unidades de más: el stock tiene que volver a 10.');
        $this->assertEquals(10.0, $this->stock($positivo), 'El fantasma positivo sumaba 5 unidades de más: el stock tiene que volver a 10.');

        $mov_negativo = $this->movimientos($negativo);
        $mov_positivo = $this->movimientos($positivo);

        $this->assertCount(1, $mov_negativo);
        $this->assertCount(1, $mov_positivo);

        $this->assertEquals(5.0, (float) $mov_negativo[0]->amount, 'Se quitó un fantasma negativo: el movimiento SUMA 5.');
        $this->assertEquals(-5.0, (float) $mov_positivo[0]->amount, 'Se quitó un fantasma positivo: el movimiento RESTA 5.');

        $this->assertEquals(5.0, (float) $mov_negativo[0]->stock_anterior);
        $this->assertEquals(10.0, (float) $mov_negativo[0]->stock_resultante);
        $this->assertEquals(15.0, (float) $mov_positivo[0]->stock_anterior);
        $this->assertEquals(10.0, (float) $mov_positivo[0]->stock_resultante);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function las_filas_de_varias_sucursales_borradas_se_van_todas_con_un_solo_movimiento()
    {
        $dueno = $this->dueno('varias-muertas');
        $s1 = $this->sucursal($dueno);
        $m1 = $this->sucursal_muerta($dueno);
        $m2 = $this->sucursal_muerta($dueno);
        $m3 = $this->sucursal_muerta($dueno);

        // 10 vivos y fantasmas en tres sucursales distintas: −1 −2 +4 → stock crudo 11.
        $articulo = $this->articulo_con_fantasmas($dueno, 'Varias muertas', [$s1->id => 10], [[$m1, -1], [$m2, -2], [$m3, 4]])['articulo'];

        $this->assertEquals(11.0, $this->stock($articulo));

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        foreach ([$m1, $m2, $m3] as $muerta) {
            $this->assertSame(0, $this->filas_en($articulo, $muerta), 'Quedaron filas de la sucursal borrada ' . $muerta . '.');
        }

        $this->assertCount(1, $this->pivot($articulo), 'Solo tiene que quedar la fila de la sucursal viva.');
        $this->assertEquals(10.0, $this->stock($articulo));

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos, 'Aunque haya fantasmas en tres sucursales, el movimiento es UNO por artículo.');
        $this->assertEquals(-1.0, (float) $movimientos[0]->amount, 'El movimiento lleva el neto: 10 − 11.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_resultado_dice_cuantas_filas_se_borraron_y_cuantos_movimientos_quedaron()
    {
        $dueno = $this->dueno('reporte-aplicar');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $this->articulo_con_fantasmas($dueno, 'Reporte uno', [$s1->id => 10], [[$muerta, -2], [$muerta, -1]]);
        $this->articulo_con_fantasmas($dueno, 'Reporte dos', [$s1->id => 3], [[$muerta, 1]]);

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Artículos saneados: 2 (con movimiento de stock: 2, sin movimiento: 0)', $this->salida);
        $this->assertStringContainsString('Filas borradas: address_article 3 · address_article_variant 0', $this->salida);
        $this->assertStringContainsString('Suma de los movimientos: +2.00 unidades', $this->salida, 'Σ de los movimientos: +3 del primero y −1 del segundo.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_con_detalle_lista_cada_articulo_saneado_con_su_movimiento()
    {
        $dueno = $this->dueno('detalle-aplicar');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $uno = $this->articulo_con_fantasmas($dueno, 'Detalle uno', [$s1->id => 10], [[$muerta, -2]])['articulo'];
        $dos = $this->articulo_con_fantasmas($dueno, 'Detalle dos', [], [[$muerta, -1]], 6)['articulo'];

        $this->assertSame(0, $this->aplicar($dueno, ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $movimiento = $this->movimientos($uno)[0];

        $this->assertStringContainsString(
            'art ' . $uno->id . ' → saneado · recalcular · filas borradas 1+0 · stock 8.00 → 10.00 · movimiento #' . $movimiento->id . ' (+2.00)',
            $this->salida,
            'El detalle de --aplicar tiene que mostrar las filas borradas, el stock antes y después y el movimiento.'
        );

        $this->assertStringContainsString('art ' . $dos->id . ' → saneado · solo_fantasmas · filas borradas 1+0 · stock 6.00 → 6.00 · sin movimiento', $this->salida);
    }
}
