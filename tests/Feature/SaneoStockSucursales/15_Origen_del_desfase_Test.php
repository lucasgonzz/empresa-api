<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use Illuminate\Support\Facades\DB;

/**
 * ORIGEN DEL DESFASE y `--solo_explicados` (misión sanear-stock-de-sucursales-borradas, 6/10/2026,
 * segunda ronda de arreglos tras el chequeo independiente).
 *
 * El pedido es corregir el desfase `articles.stock` − suma de las sucursales vivas. Pero ese desfase
 * tiene DOS orígenes que el reporte tiene que separar:
 *
 *   - lo que explican las filas fantasma: sin variantes el motor deja `articles.stock` como la suma
 *     CRUDA de las filas, así que si el dato es coherente el desfase es exactamente la suma de los
 *     fantasmas del artículo;
 *   - lo que NO: una carga manual, una importación, una versión vieja. Con variantes los fantasmas
 *     nunca entran en `articles.stock`, así que ahí todo el desfase es de otra causa.
 *
 * 🔴 Por qué importa: `--aplicar` corrige el desfase entero y el movimiento lleva la etiqueta "Baja de
 * sucursal eliminada". Si una parte del desfase no tiene nada que ver con las sucursales borradas, esa
 * etiqueta miente y nadie lo ve venir. El reporte lo muestra por dueño y `--solo_explicados` deja
 * esos artículos afuera.
 *
 * El escenario (un dueño, una sucursal viva S, una muerta D):
 *
 *   - X: vive 10, fantasma −2, stock 8 (suma cruda).        desfase −2 = −2 por fantasmas + 0 otra causa.
 *   - Y: vive 10, fantasma −2, stock 100 (carga manual).    desfase +90 = −2 por fantasmas + 92 otra causa.
 *   - Z: CON variantes (V vive 6, fantasma de variante −1), stock 11 en vez de 6.
 *                                                           desfase +5 = 0 por fantasmas + 5 otra causa.
 *
 * Totales: desfase +93 = −4 por fantasmas + 97 por otra causa, en 2 artículos (Y y Z).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Origen_del_desfase_Test extends SaneoStockSucursalesTestCase
{
    /**
     * El escenario del docblock.
     *
     * @return array  ['dueno', 's1', 'muerta', 'x', 'y', 'z' (con 'articulo', 'variantes')]
     */
    protected function escenario()
    {
        $dueno = $this->dueno('origen-del-desfase');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $x = $this->articulo_con_fantasmas($dueno, 'X explicado', [$s1->id => 10], [[$muerta, -2]])['articulo'];

        $y = $this->articulo_con_fantasmas($dueno, 'Y otra causa', [$s1->id => 10], [[$muerta, -2]], 100)['articulo'];

        $z = $this->articulo_con_variantes($dueno, 'Z variantes', [['vivas' => [$s1->id => 6], 'fantasmas' => [[$muerta, -1]]]]);

        DB::table('articles')->where('id', $z['articulo']->id)->update(['stock' => 11]);

        return compact('dueno', 's1', 'muerta', 'x', 'y', 'z');
    }

    /**
     * Una fila de la tabla "Origen del desfase" del reporte.
     *
     * Se busca DESPUÉS del título para no confundirla con la fila del mismo dueño de la tabla principal.
     *
     * @param  int|string  $etiqueta  Id del dueño o 'TOTAL'.
     * @return array  desfase, fantasmas, otra_causa, articulos.
     */
    protected function fila_de_origen($etiqueta)
    {
        $inicio = strpos($this->salida, 'Origen del desfase:');

        $this->assertNotFalse($inicio, 'El reporte no trae el título "Origen del desfase:". Salida:' . "\n" . $this->salida);

        $resto = substr($this->salida, $inicio);

        $patron = '/^\|\s*' . preg_quote((string) $etiqueta, '/') . '\s*\|(.*)\|\s*$/m';

        $this->assertSame(1, preg_match($patron, $resto, $coincidencia), 'La tabla "Origen del desfase" no trae la fila "' . $etiqueta . '". Salida:' . "\n" . $this->salida);

        $celdas = array_map('trim', explode('|', $coincidencia[1]));

        return [
            'desfase' => (float) $celdas[0],
            'fantasmas' => (float) $celdas[1],
            'otra_causa' => (float) $celdas[2],
            'articulos' => (int) $celdas[3],
        ];
    }

    /**
     * El reporte parte el desfase por causa, por dueño y en el total, y la suma da.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_parte_el_desfase_en_lo_que_explican_los_fantasmas_y_lo_que_no()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);

        // La tabla principal sigue con su forma de siempre: el desfase total del dueño.
        $this->assertEquals(93.0, $this->fila_del_reporte($e['dueno']->id)['desfase'], 'Desfase total: −2 (X) + 90 (Y) + 5 (Z).');

        // Y la tabla de origen lo parte.
        $fila = $this->fila_de_origen($e['dueno']->id);

        $this->assertEquals(93.0, $fila['desfase']);
        $this->assertEquals(-4.0, $fila['fantasmas'], 'Lo que explican los fantasmas: −2 (X) y −2 (Y); Z tiene variantes, sus fantasmas no entran en el stock.');
        $this->assertEquals(97.0, $fila['otra_causa'], 'Lo que NO explican: 92 (Y, carga manual) y 5 (Z, desvío).');
        $this->assertSame(2, $fila['articulos'], 'Dos artículos con desvío de otra causa: Y y Z.');
        $this->assertEquals($fila['desfase'], $fila['fantasmas'] + $fila['otra_causa'], 'Las dos causas suman el desfase.');

        $total = $this->fila_de_origen('TOTAL');

        $this->assertEquals(93.0, $total['desfase']);
        $this->assertEquals(97.0, $total['otra_causa']);

        $this->assertStringContainsString('2 artículos tenían el stock distinto de la suma de sus filas ANTES de los fantasmas', $this->salida);
        $this->assertStringContainsString('--solo_explicados', $this->salida, 'Y decir cómo dejarlos afuera.');
    }

    /**
     * Si TODO el desfase lo explican los fantasmas, el reporte lo dice en una línea y no arma la tabla.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_todo_el_desfase_lo_explican_los_fantasmas_el_reporte_lo_dice_sin_tabla()
    {
        $dueno = $this->dueno('todo-explicado');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $this->articulo_con_fantasmas($dueno, 'Explicado 1', [$s1->id => 10], [[$muerta, -2]]);
        $this->articulo_con_fantasmas($dueno, 'Explicado 2', [$s1->id => 4], [[$muerta, 3], [$muerta, 1]]);

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(2.0, $this->fila_del_reporte($dueno->id)['desfase'], 'Desfase: −2 + 4.');
        $this->assertStringContainsString('Origen del desfase: lo que hay para corregir lo explican por completo las filas fantasma', $this->salida);
        $this->assertStringNotContainsString('por otra causa', $this->salida, 'Sin desvíos de otra causa no hay tabla de origen.');
    }

    /**
     * `--solo_explicados`: se sanean los artículos cuyo desfase lo explican por completo los fantasmas
     * (X) y se dejan, sin tocar nada, los que tienen un desvío de otra causa (Y, Z). Sin la opción,
     * `--aplicar` corrige los tres (el pedido de Lucas: el desfase entero).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function solo_explicados_deja_afuera_los_articulos_con_otra_causa_y_sin_la_opcion_se_corrigen_todos()
    {
        $e = $this->escenario();

        // La vista previa ya avisa lo que se va a dejar afuera.
        $this->assertSame(0, $this->ver($e['dueno'], ['--solo_explicados' => true]), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('--solo_explicados: 2 artículos con un desvío de stock que no explican los fantasmas', $this->salida);

        $pivot_y_antes = $this->pivot($e['y']);
        $pivot_variante_z_antes = $this->pivot_de_variante($e['z']['variantes'][0]);

        // 1. Con --solo_explicados.
        $this->assertSame(0, $this->aplicar($e['dueno'], ['--solo_explicados' => true]), 'Salida:' . "\n" . $this->salida);

        // El resumen final tiene que decir TODO lo que quedó sin tocar (no "Saltados: 0."): quien mire
        // solo el exit code (0) creería que quedó limpio.
        $this->assertStringContainsString('Saltados (' . FilasFantasmaDeSucursalHelper::MOTIVO_DESVIO_NO_EXPLICADO . '): 2.', $this->salida, 'El resumen final tiene que contar los 2 artículos que --solo_explicados dejó afuera.');
        $this->assertStringNotContainsString('Saltados: 0.', $this->salida, 'Con artículos sin tocar el resumen no puede decir "Saltados: 0.".');

        // X (explicado): saneado, con su movimiento.
        $this->assertSame(0, $this->filas_en($e['x'], $e['muerta']), 'X: el fantasma tiene que haberse borrado.');
        $this->assertEquals(10.0, $this->stock($e['x']));
        $movimientos_x = $this->movimientos($e['x']);
        $this->assertCount(1, $movimientos_x);
        $this->assertEquals(2.0, (float) $movimientos_x[0]->amount, 'X: 10 − 8.');

        // Y (carga manual) y Z (desvío con variantes): intactos, ni una fila ni un número.
        $this->assertSame($pivot_y_antes, $this->pivot($e['y']), 'Y tiene un desvío de otra causa: --solo_explicados no lo toca.');
        $this->assertEquals(100.0, $this->stock($e['y']));
        $this->assertCount(0, $this->movimientos($e['y']));

        $this->assertSame($pivot_variante_z_antes, $this->pivot_de_variante($e['z']['variantes'][0]), 'Z: el fantasma de variante tiene que seguir ahí.');
        $this->assertEquals(11.0, $this->stock($e['z']['articulo']));
        $this->assertCount(0, $this->movimientos($e['z']['articulo']));

        // El respaldo cuenta lo que se hizo: UN solo artículo (X); Y y Z ni se intentaron.
        $lineas = $this->lineas_del_respaldo($this->carpeta_de_salida);
        $this->assertCount(1, $lineas, 'Solo X entra en el trabajo de --solo_explicados.');
        $this->assertSame((int) $e['x']->id, (int) $lineas[0]['article_id']);

        // 2. Sin la opción, los dos que quedaron se corrigen enteros (es el pedido).
        $this->assertSame(0, $this->aplicar($e['dueno'], ['--salida' => $this->carpeta_nueva()]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($e['y'], $e['muerta']));
        $this->assertEquals(10.0, $this->stock($e['y']), 'Y: el stock vuelve a la suma de lo que se ve.');
        $movimientos_y = $this->movimientos($e['y']);
        $this->assertCount(1, $movimientos_y);
        $this->assertEquals(-90.0, (float) $movimientos_y[0]->amount, 'Y: 10 − 100.');

        $this->assertSame(0, DB::table('address_article_variant')->where('article_variant_id', $e['z']['variantes'][0]->id)->where('address_id', $e['muerta'])->count());
        $this->assertEquals(6.0, $this->stock($e['z']['articulo']), 'Z: el stock vuelve a la suma de su variante.');
        $movimientos_z = $this->movimientos($e['z']['articulo']);
        $this->assertCount(1, $movimientos_z);
        $this->assertEquals(-5.0, (float) $movimientos_z[0]->amount, 'Z: 6 − 11.');
    }

    /**
     * 🔴 `--solo_explicados` NO saltea un artículo cuyo stock ya está bien, aunque difiera de la suma
     * CRUDA de sus filas (alguien lo corrigió a mano): no hay corrección que etiquetar mal, solo hay
     * que borrar la fila fantasma. Dejarla es peor: el próximo movimiento del artículo la vuelve a
     * sumar (`SUM` crudo) y el stock se rompe de nuevo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function solo_explicados_no_saltea_un_articulo_cuyo_stock_ya_esta_bien_y_le_borra_el_fantasma()
    {
        $dueno = $this->dueno('stock-ya-corregido');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Vive 10, fantasma −2 y el stock YA está en 10 (alguien lo corrigió a mano): desfase 0, pero el
        // stock difiere de la suma cruda (8): el diagnóstico "otra causa" sería +2 sin la condición.
        $e = $this->articulo_con_fantasmas($dueno, 'Corregido a mano', [$s1->id => 10], [[$muerta, -2]], 10);

        $this->assertEquals(10.0, $this->stock($e['articulo']));

        // La vista previa no lo cuenta como "otra causa" ni avisa de un salteo.
        $this->assertSame(0, $this->ver($dueno, ['--solo_explicados' => true]), 'Salida:' . "\n" . $this->salida);
        $this->assertEquals(0.0, $this->fila_del_reporte($dueno->id)['desfase'], 'Sin desfase: no hay nada que corregir.');
        $this->assertStringNotContainsString('--solo_explicados:', $this->salida, 'Este artículo no se saltea: no hay corrección con una parte ajena.');
        $this->assertStringContainsString('Origen del desfase: lo que hay para corregir lo explican por completo las filas fantasma', $this->salida);

        $this->assertSame(0, $this->aplicar($dueno, ['--solo_explicados' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($e['articulo'], $muerta), 'El fantasma tiene que borrarse aunque se pida --solo_explicados: dejarlo rompería el stock en el próximo movimiento.');
        $this->assertEquals(10.0, $this->stock($e['articulo']), 'El stock ya estaba bien: no cambia.');
        $this->assertCount(0, $this->movimientos($e['articulo']), 'Sin cambio de stock no hay movimiento (y por lo tanto ninguna etiqueta que mienta).');
        $this->assertStringContainsString('Artículos saneados: 1', $this->salida);
    }

    /**
     * La decisión de `--solo_explicados` se vuelve a tomar DENTRO del candado, en el helper: lo medido
     * antes pudo cambiar. Se prueba directo contra el helper (lo que corre por artículo).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_helper_vuelve_a_decidir_el_solo_explicados_bajo_el_candado()
    {
        $e = $this->escenario();

        $concepto_id = (int) DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->value('id');

        $pivot_antes = $this->pivot($e['y']);

        // Con la opción, Y se saltea con su motivo y no se toca nada.
        $resultado = FilasFantasmaDeSucursalHelper::sanear_articulo($e['y']->id, $concepto_id, null, null, true);

        $this->assertSame(FilasFantasmaDeSucursalHelper::RESULTADO_SALTADO, $resultado['resultado']);
        $this->assertSame(FilasFantasmaDeSucursalHelper::MOTIVO_DESVIO_NO_EXPLICADO, $resultado['motivo']);
        $this->assertSame($pivot_antes, $this->pivot($e['y']), 'Un artículo salteado no pierde ni una fila.');
        $this->assertEquals(100.0, $this->stock($e['y']));
        $this->assertCount(0, $this->movimientos($e['y']));

        // X, que sí está explicado, se sanea aunque llegue la opción.
        $resultado_x = FilasFantasmaDeSucursalHelper::sanear_articulo($e['x']->id, $concepto_id, null, null, true);

        $this->assertSame(FilasFantasmaDeSucursalHelper::RESULTADO_SANEADO, $resultado_x['resultado']);

        // Sin la opción, el mismo Y se sanea.
        $resultado_y = FilasFantasmaDeSucursalHelper::sanear_articulo($e['y']->id, $concepto_id);

        $this->assertSame(FilasFantasmaDeSucursalHelper::RESULTADO_SANEADO, $resultado_y['resultado']);
        $this->assertEquals(10.0, $this->stock($e['y']));
    }

    /**
     * Con variantes y desfase, `--aplicar` recalcula con la función del sistema, que RECONSTRUYE el
     * pivot del artículo (pierde `stock_min`/`stock_max` y las filas de comprador): el reporte lo
     * avisa ANTES de aplicar, y el detalle lo marca artículo por artículo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_avisa_de_los_pivots_que_se_van_a_reconstruir()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->ver($e['dueno'], ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Artículos con variantes con depósitos y desfase: 1', $this->salida, 'Z es el único artículo con variantes con depósitos y desfase.');
        $this->assertStringContainsString('RECONSTRUYE el pivot', $this->salida);

        // El detalle de Z lo marca, con la parte del desfase que viene de otra causa.
        $this->assertSame(1, preg_match('/^  art ' . (int) $e['z']['articulo']->id . ' .*de otra causa \+5\.00.*reconstruye el pivot\s*$/mu', $this->salida),'La línea de detalle de Z tiene que decir "de otra causa +5.00" y "reconstruye el pivot". Salida:' . "\n" . $this->salida);

        // Y la de X (sin variantes, todo explicado) no dice ni una cosa ni la otra.
        $this->assertSame(1, preg_match('/^  art ' . (int) $e['x']->id . ' .*$/mu', $this->salida, $linea_x));
        $this->assertStringNotContainsString('de otra causa', $linea_x[0]);
        $this->assertStringNotContainsString('reconstruye', $linea_x[0]);
    }

    /**
     * 🔴 Un artículo cuyo stock YA está bien (alguien lo corrigió a mano) no tiene corrección que
     * repartir: no aporta ni a "por filas fantasma" ni a "por otra causa", ni lleva la marca "de otra
     * causa" en el detalle. Sin esto la tabla de origen mostraba "−2,00 por fantasmas / +2,00 por otra
     * causa" para ese artículo: suma cero, pero deja un monto de otra causa sin ningún artículo detrás
     * (y el detalle lo marca como lo que `--solo_explicados` deja afuera cuando NO lo deja).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_el_stock_ya_corregido_no_aporta_monto_de_otra_causa()
    {
        $e = $this->escenario();

        // W: vive 10, fantasma −2 y el stock YA está en 10 (corregido a mano): desfase 0, pero el stock
        // difiere de la suma cruda de sus filas (8).
        $w = $this->articulo_con_fantasmas($e['dueno'], 'W corregido a mano', [$e['s1']->id => 10], [[$e['muerta'], -2]], 10)['articulo'];

        $this->assertEquals(10.0, $this->stock($w), 'El escenario no quedó armado: W tiene el stock ya corregido.');

        $this->assertSame(0, $this->ver($e['dueno'], ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        // La tabla es la del escenario: W no cambia ni un número.
        $fila = $this->fila_de_origen($e['dueno']->id);

        $this->assertEquals(93.0, $fila['desfase'], 'W no tiene desfase: no suma.');
        $this->assertEquals(-4.0, $fila['fantasmas'], 'W no aporta a "por filas fantasma" (sin la regla daría −6).');
        $this->assertEquals(97.0, $fila['otra_causa'], 'W no aporta a "por otra causa" (sin la regla daría 99: un monto sin artículo detrás).');
        $this->assertSame(2, $fila['articulos'], 'Y y Z; W no es un artículo con otra causa.');
        $this->assertEquals($fila['desfase'], $fila['fantasmas'] + $fila['otra_causa'], 'Las dos causas siguen sumando el desfase.');

        // Y su línea de detalle no lleva la marca.
        $this->assertSame(1, preg_match('/^  art ' . (int) $w->id . ' .*$/mu', $this->salida, $linea_w), 'El detalle tiene que traer la línea de W. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('(desfase 0.00)', $linea_w[0], 'W: desfase cero.');
        $this->assertStringNotContainsString('de otra causa', $linea_w[0], 'W no tiene corrección: la marca "de otra causa" sería falsa.');
    }
}
