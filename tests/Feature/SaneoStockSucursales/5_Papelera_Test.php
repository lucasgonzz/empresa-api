<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * Los artículos de la PAPELERA (`articles.deleted_at` no nulo) cuentan y se sanean igual (misión
 * sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Un artículo borrado a la papelera se puede restaurar, y cuando se restaura tiene que volver con
 * el stock bien: sus filas fantasma siguen ahí y siguen inflando (o hundiendo) su `articles.stock`.
 * Dos trampas concretas que estos tests cubren:
 *
 *   1. el reporte tiene que CONTARLOS (el modelo `Article` los esconde con SoftDeletes; el comando
 *      mira con SQL crudo y tiene que decir cuántos son);
 *   2. la función del sistema recibe el artículo por su modelo: con `Article::find()` un artículo de
 *      la papelera vuelve null y `setArticleStockFromAddresses()` revienta. Hay que buscarlo con
 *      `withTrashed()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Papelera_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Manda un artículo a la papelera como lo hace `SoftDeletes`, sin pasar por los hooks del modelo.
     *
     * @param  \App\Models\Article  $articulo
     * @return void
     */
    protected function a_la_papelera($articulo)
    {
        DB::table('articles')->where('id', $articulo->id)->update(['deleted_at' => now()]);

        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('deleted_at'), 'El fixture no pudo mandar el artículo a la papelera.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_cuenta_los_articulos_de_la_papelera_como_afectados()
    {
        $dueno = $this->dueno('papelera-ver');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $activo = $this->articulo_con_fantasmas($dueno, 'Activo', [$s1->id => 10], [[$muerta, -1]]);
        $borrado = $this->articulo_con_fantasmas($dueno, 'Borrado', [$s1->id => 10], [[$muerta, -2]]);

        $this->a_la_papelera($borrado['articulo']);

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);

        $fila = $this->fila_del_reporte($dueno->id);

        $this->assertSame(2, $fila['articulos'], 'Los artículos de la papelera cuentan como afectados: tienen que ser 2, no 1.');
        $this->assertSame(1, $fila['papelera'], 'Y el reporte tiene que decir cuántos de ellos están en la papelera.');
        $this->assertSame(2, $fila['filas_articulo']);
        $this->assertEquals(-3.0, $fila['unidades_articulo']);
        $this->assertEquals(-3.0, $fila['desfase'], 'El desfase también incluye al artículo de la papelera: −1 y −2.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_sanea_el_articulo_de_la_papelera_y_le_deja_su_movimiento()
    {
        $dueno = $this->dueno('papelera-aplicar');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $borrado = $this->articulo_con_fantasmas($dueno, 'Borrado', [$s1->id => 10], [[$muerta, -2], [$muerta, -3]]);
        $articulo = $borrado['articulo'];

        $this->a_la_papelera($articulo);

        $this->assertEquals(5.0, $this->stock($articulo), 'El escenario no quedó armado: stock crudo 10 − 2 − 3.');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $muerta), 'El artículo de la papelera conserva sus filas fantasma: el saneo lo ignoró.');
        $this->assertEquals(10.0, $this->stock($articulo), 'El artículo de la papelera tiene que volver con el stock bien: la suma de sus sucursales vivas.');
        $this->assertNotNull(DB::table('articles')->where('id', $articulo->id)->value('deleted_at'), 'El saneo no restaura ni toca la papelera: el artículo sigue borrado.');

        $movimientos = $this->movimientos($articulo);

        $this->assertCount(1, $movimientos, 'El artículo de la papelera también deja su movimiento.');
        $this->assertEquals(5.0, (float) $movimientos[0]->amount);
        $this->assertSame((int) $dueno->id, (int) $movimientos[0]->user_id);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_variantes_en_la_papelera_tambien_se_sanea()
    {
        $dueno = $this->dueno('papelera-variantes');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_variantes(
            $dueno,
            'Borrado con variantes',
            [['vivas' => [$s1->id => 6], 'fantasmas' => [[$muerta, -2]]]],
            [[$muerta, 1]]
        );

        $this->a_la_papelera($e['articulo']);

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, DB::table('address_article_variant')->where('address_id', $muerta)->where('article_variant_id', $e['variantes'][0]->id)->count(), 'Quedó el fantasma de la variante del artículo de la papelera.');
        $this->assertSame(0, $this->filas_en($e['articulo'], $muerta), 'Quedó el fantasma del artículo de la papelera.');
        $this->assertEquals(6.0, $this->stock($e['articulo']));
        $this->assertNotNull(DB::table('articles')->where('id', $e['articulo']->id)->value('deleted_at'));
    }
}
