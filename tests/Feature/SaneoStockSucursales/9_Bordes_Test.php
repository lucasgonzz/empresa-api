<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Los BORDES del saneo (misión sanear-stock-de-sucursales-borradas, 6/10/2026): los casos donde
 * "recalcular y listo" haría daño.
 *
 *   1. solo fantasmas (sin ninguna sucursal viva): se borran las filas y el stock global NO se toca;
 *   2. fantasma con amount 0 o NULL: se borra y no hay movimiento (el stock no cambia);
 *   3. stock desviado de antes (el desfase NO es la suma de los fantasmas): el movimiento lleva el
 *      desfase TOTAL y `--ver` informa las dos cifras;
 *   4. filas repetidas del mismo par: se borran todas;
 *   5. `no_recalculable` (una variante con una fila viva en una sucursal que no es del dueño): no se
 *      toca NADA y se informa con su motivo;
 *   6. filas SIN dueño (el artículo o la variante ya no existe): no se tocan y se informan;
 *   7. `articles.stock` NULL.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Bordes_Test extends SaneoStockSucursalesTestCase
{
    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_solo_fantasmas_pierde_las_filas_pero_no_su_stock_global()
    {
        $dueno = $this->dueno('solo-fantasmas');
        $muerta = $this->sucursal_muerta($dueno);

        // Sin ninguna sucursal viva el artículo lleva stock GLOBAL (6): el motor no lo recalcula desde
        // el pivot. Las dos filas fantasma son restos que nadie suma.
        $e = $this->articulo_con_fantasmas($dueno, 'Solo fantasmas', [], [[$muerta, -1], [$muerta, 3]], 6);
        $articulo = $e['articulo'];

        $this->assertEquals(6.0, $this->stock($articulo));

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('solo_fantasmas 1', $this->salida);
        $this->assertStringContainsString('articles.stock NO se toca', $this->salida, 'El reporte tiene que avisar que el stock global de estos artículos no se corrige.');
        $this->assertEquals(0.0, $this->fila_del_reporte($dueno->id)['desfase'], 'Sin sucursales vivas no hay suma contra la que medir un desfase.');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'Las filas fantasma de un artículo sin sucursales vivas también se borran.');
        $this->assertEquals(6.0, $this->stock($articulo), 'El stock global de un artículo sin sucursales vivas NO se toca: no es la suma de nada.');
        $this->assertCount(0, $this->movimientos($articulo), 'No cambió el stock: no hay movimiento que dejar.');
    }

    /**
     * Mismo caso con el stock sin cargar (NULL): sigue NULL, no se convierte en 0.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_solo_fantasmas_y_stock_null_sigue_con_stock_null()
    {
        $dueno = $this->dueno('solo-fantasmas-null');
        $muerta = $this->sucursal_muerta($dueno);

        $articulo = $this->articulo_con_fantasmas($dueno, 'Solo fantasmas sin stock', [], [[$muerta, -1]], null)['articulo'];

        $this->assertNull($this->stock($articulo));

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta));
        $this->assertNull($this->stock($articulo), 'Un stock sin cargar (NULL) no se convierte en 0.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_fantasma_con_amount_cero_o_null_se_borra_sin_dejar_movimiento()
    {
        $dueno = $this->dueno('amount-cero');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_fantasmas($dueno, 'Amount cero', [$s1->id => 10], [[$muerta, 0], [$muerta, null]]);
        $articulo = $e['articulo'];

        $this->assertSame(2, $this->filas_en($articulo, $muerta), 'El escenario no quedó armado: dos filas fantasma (una en 0 y otra NULL).');
        $this->assertEquals(10.0, $this->stock($articulo));

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'Una fila fantasma sin cantidad también se borra.');
        $this->assertEquals(10.0, $this->stock($articulo));
        $this->assertCount(0, $this->movimientos($articulo), 'El stock no cambió: no hay movimiento que dejar.');

        $this->assertStringContainsString('Artículos saneados: 1 (con movimiento de stock: 0, sin movimiento: 1)', $this->salida);
    }

    /**
     * Cuando el stock global venía desviado de antes (un stock cargado a mano, un movimiento que no
     * tocó el pivot), el desfase NO es la suma de los fantasmas. El movimiento tiene que explicar
     * el desfase TOTAL —lo que realmente cambia— y `--ver` mostrar las dos cifras, para que quien
     * lo lee no crea que el fantasma explica todo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_el_stock_desviado_el_movimiento_es_el_desfase_total_y_ver_informa_las_dos_cifras()
    {
        $dueno = $this->dueno('desfase');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Vive 10, un fantasma de −2; el motor lo habría dejado en 8, pero alguien lo dejó en 20.
        $e = $this->articulo_con_fantasmas($dueno, 'Desfase', [$s1->id => 10], [[$muerta, -2]], 20);
        $articulo = $e['articulo'];

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);

        $fila = $this->fila_del_reporte($dueno->id);

        $this->assertEquals(-2.0, $fila['unidades_articulo'], 'Cifra 1: las unidades de los fantasmas (−2).');
        $this->assertEquals(10.0, $fila['desfase'], 'Cifra 2: el desfase real del stock global (20 − 10).');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(10.0, $this->stock($articulo));

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos);
        $this->assertEquals(-10.0, (float) $movimientos[0]->amount, 'El movimiento explica el desfase TOTAL (10 − 20), no solo los fantasmas (+2).');
        $this->assertEquals(20.0, (float) $movimientos[0]->stock_anterior);
        $this->assertEquals(10.0, (float) $movimientos[0]->stock_resultante);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function las_filas_repetidas_del_mismo_par_se_borran_todas()
    {
        $dueno = $this->dueno('repetidas');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Cinco ventas contra la misma sucursal muerta: cinco filas del mismo par, de −1 cada una.
        $e = $this->articulo_con_fantasmas($dueno, 'Repetidas', [$s1->id => 10], [[$muerta, -1], [$muerta, -1], [$muerta, -1], [$muerta, -1], [$muerta, -1]]);
        $articulo = $e['articulo'];

        $this->assertSame(5, $this->filas_en($articulo, $muerta));
        $this->assertEquals(5.0, $this->stock($articulo));

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);
        $this->assertSame(5, $this->fila_del_reporte($dueno->id)['filas_articulo'], 'El reporte cuenta las cinco filas, no el par.');
        $this->assertStringContainsString('#' . $muerta . ': 5 filas (-5.00 u)', $this->salida);

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'Quedaron filas del par: el saneo tiene que borrarlas todas.');
        $this->assertEquals(10.0, $this->stock($articulo));
        $this->assertStringContainsString('Filas borradas: address_article 5', $this->salida);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_no_recalculable_no_se_toca_y_se_informa_con_su_motivo()
    {
        $dueno = $this->dueno('no-recalculable');
        $ajena = $this->sucursal($this->dueno('no-recalculable-ajena'), 'zz Sucursal ajena');
        $comprador = $this->domicilio_de_comprador();
        $muerta = $this->sucursal_muerta($dueno);

        // Dos formas de lo mismo: la variante tiene una fila VIVA en una sucursal que no es del dueño
        // (de otro comercio, o un domicilio de comprador). El motor revienta al recalcularlo.
        $en_ajena = $this->articulo_no_recalculable($dueno, $ajena, $muerta, 'No recalculable ajena');
        $en_comprador = $this->articulo_no_recalculable($dueno, $comprador, $muerta, 'No recalculable comprador');

        $articulos = [$en_ajena['articulo']->id, $en_comprador['articulo']->id];

        $antes = $this->foto_de_tablas();

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('no_recalculable 2', $this->salida);
        $this->assertStringContainsString('variante_con_fila_en_sucursal_ajena: 2', $this->salida, 'El reporte tiene que decir el MOTIVO por el que no se los toca.');
        $this->assertSame(2, $this->fila_del_reporte($dueno->id)['articulos'], 'Siguen siendo artículos afectados (tienen fantasmas): solo que --aplicar no los toca.');
        $this->assertEquals(0.0, $this->fila_del_reporte($dueno->id)['desfase'], 'En un artículo que no se recalcula no hay desfase que calcular.');

        $this->assertSame(0, $this->aplicar($dueno), 'Un artículo saltado no es un error: exit 0. Salida:' . "\n" . $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Un artículo no_recalculable se tocó (ni siquiera sus fantasmas se borran)');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'Sin nada que el comando pueda tocar no hay respaldo que dejar.');
        $this->assertStringContainsString('No hay nada para sanear', $this->salida);

        foreach ($articulos as $id) {
            $this->assertCount(0, $this->movimientos($id), 'Un artículo no recalculable no deja movimiento.');
        }
    }

    /**
     * 🔴 Con trabajo para hacer Y un artículo que el comando no toca, el resumen final tiene que decir
     * lo que quedó sin tocar. Antes arrancaba en cero y solo contaba lo que salteaba el helper por una
     * carrera: "Saltados: 0." y exit 0 con un artículo que seguía con su fantasma.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_resumen_final_de_aplicar_cuenta_los_no_recalculables_que_la_medicion_dejo_afuera()
    {
        $dueno = $this->dueno('resumen-saltados');
        $s1 = $this->sucursal($dueno);
        $ajena = $this->sucursal($this->dueno('resumen-saltados-ajena'), 'zz Sucursal ajena');
        $muerta = $this->sucursal_muerta($dueno);

        $sano = $this->articulo_con_fantasmas($dueno, 'Con trabajo', [$s1->id => 10], [[$muerta, -3]]);
        $no_recalculable = $this->articulo_no_recalculable($dueno, $ajena, $muerta);

        $this->assertSame(0, $this->aplicar($dueno), 'Un artículo saltado no es un error: exit 0. Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($sano['articulo'], $muerta), 'El que sí se puede recalcular se sanó.');
        $this->assertStringContainsString('Artículos saneados: 1', $this->salida);

        $this->assertStringContainsString('Saltados (variante_con_fila_en_sucursal_ajena): 1.', $this->salida, 'El resumen tiene que decir que un artículo quedó sin tocar y por qué.');
        $this->assertStringNotContainsString('Saltados: 0.', $this->salida, '"Saltados: 0." sería mentira: el no recalculable sigue con su fantasma.');

        $this->assertSame(1, DB::table('address_article_variant')->where('id', $no_recalculable['fila_fantasma'])->count(), 'El no recalculable conserva su fila fantasma.');
        $this->assertCount(0, $this->movimientos($no_recalculable['articulo']), 'Y no deja movimiento.');
    }

    /**
     * La premisa de la clase `no_recalculable`: con el dueño CORRECTO la función del sistema
     * revienta con `Undefined index`. Si algún día el motor deja de reventar, el test se saltea y
     * avisa: la clase habría que repensarla.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function premisa_del_motor_un_articulo_no_recalculable_revienta_al_recalcularlo()
    {
        $dueno = $this->dueno('premisa');
        $ajena = $this->sucursal($this->dueno('premisa-ajena'));
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_no_recalculable($dueno, $ajena, $muerta);

        try {
            ArticleHelper::setArticleStockFromAddresses(Article::find($e['articulo']->id), false, $dueno->id);
        } catch (\ErrorException $excepcion) {
            $this->assertStringContainsString('Undefined', $excepcion->getMessage(), 'Tenía que ser el "Undefined index" de get_addresses().');

            return;
        }

        $this->markTestSkipped('El motor ya no revienta con una variante en una sucursal ajena: la clase no_recalculable del saneo hay que repensarla.');
    }

    /**
     * Filas fantasma de artículos y variantes que ya no existen: no tienen dueño, no se tocan, y se
     * informan. Se mide por DIFERENCIA (la base del slot trae sus propias filas).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function las_filas_sin_dueno_no_se_tocan_y_se_informan()
    {
        $dueno = $this->dueno('sin-dueno');
        $muerta = $this->sucursal_muerta($dueno);

        $antes = FilasFantasmaDeSucursalHelper::filas_sin_dueno();

        // Un artículo y una variante que no existen (se borraron de verdad), con filas colgando.
        $articulo_inexistente = (int) DB::table('articles')->max('id') + 5000;
        $variante_inexistente = (int) DB::table('article_variants')->max('id') + 5000;

        $ids_articulo = [
            DB::table('address_article')->insertGetId(['article_id' => $articulo_inexistente, 'address_id' => $muerta, 'amount' => -3]),
            DB::table('address_article')->insertGetId(['article_id' => $articulo_inexistente, 'address_id' => $muerta, 'amount' => 1]),
        ];

        $id_variante = DB::table('address_article_variant')->insertGetId(['article_variant_id' => $variante_inexistente, 'address_id' => $muerta, 'amount' => 4]);

        $despues = FilasFantasmaDeSucursalHelper::filas_sin_dueno();

        $this->assertSame($antes['articulo']['filas'] + 2, $despues['articulo']['filas'], 'El escenario no quedó armado: dos filas sin dueño en address_article.');
        $this->assertEqualsWithDelta($antes['articulo']['unidades'] - 2.0, $despues['articulo']['unidades'], 0.001);
        $this->assertSame($antes['variante']['filas'] + 1, $despues['variante']['filas']);
        $this->assertEqualsWithDelta($antes['variante']['unidades'] + 4.0, $despues['variante']['unidades'], 0.001);

        // El reporte sin acotar las informa.
        $this->assertSame(0, $this->sanear(['--ver' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('SIN dueño', $this->salida, 'El reporte sin acotar tiene que informar las filas sin dueño.');
        $this->assertStringContainsString('address_article ' . $despues['articulo']['filas'] . ' filas', $this->salida);
        $this->assertStringContainsString('address_article_variant ' . $despues['variante']['filas'] . ' filas', $this->salida);

        // Acotado a un dueño no las menciona: no son de ningún dueño.
        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);
        $this->assertStringNotContainsString('SIN dueño', $this->salida);

        // Y --aplicar (aun sin acotar) no las toca. Con --todos: una base con VARIOS dueños con trabajo
        // (la de otro slot, la de un cliente compartido) haría que el freno cortara el comando y el
        // test pasaría en vacío sin probar nada. Se afirma el exit 0 por lo mismo.
        $this->assertSame(0, $this->sanear(['--aplicar' => true, '--todos' => true, '--salida' => $this->carpeta_de_salida]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(2, DB::table('address_article')->whereIn('id', $ids_articulo)->count(), 'El saneo borró filas que no tienen dueño: no hay con qué recalcularlas.');
        $this->assertSame(1, DB::table('address_article_variant')->where('id', $id_variante)->count(), 'El saneo borró una fila de una variante inexistente.');
        $this->assertEquals($despues, FilasFantasmaDeSucursalHelper::filas_sin_dueno(), 'Las filas sin dueño tienen que seguir exactamente igual.');
    }

    /**
     * Un artículo con el stock sin cargar (NULL) pero con sucursales vivas: el motor lo dejaría en
     * la suma. El movimiento sale del 0 implícito, y `stock_anterior` queda NULL (no hay stock
     * anterior que registrar).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_stock_null_y_sucursales_vivas_queda_en_la_suma()
    {
        $dueno = $this->dueno('stock-null');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_fantasmas($dueno, 'Stock null', [$s1->id => 10], [[$muerta, -1]], null);
        $articulo = $e['articulo'];

        $this->assertNull($this->stock($articulo));

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta));
        $this->assertEquals(10.0, $this->stock($articulo), 'Con sucursales vivas el stock queda en su suma, también si estaba sin cargar.');

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos, 'El stock pasó de "sin cargar" a 10: hay un cambio que explicar.');
        $this->assertEquals(10.0, (float) $movimientos[0]->amount, 'amount = 10 − 0 (NULL cuenta como 0).');
        $this->assertNull($movimientos[0]->stock_anterior, 'Antes no había stock cargado: stock_anterior es NULL, no 0.');
        $this->assertEquals(10.0, (float) $movimientos[0]->stock_resultante);
    }
}
