<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * BASE COMPARTIDA: varios comercios en la misma base (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * Hay clientes que comparten la base con otros comercios (`u767360347_empresa` tiene 51 adentro).
 * Ahí:
 *
 *   1. `--user_id` acota de verdad: saneando al dueño A, los artículos del dueño B no se tocan;
 *   2. sin `--user_id`, CADA artículo se recalcula con SU dueño (`articles.user_id`), nunca con el
 *      usuario logueado ni con `config('app.USER_ID')`.
 *
 * 🔴 El punto 2 es el que se rompe en silencio. `setArticleStockFromAddresses()` arma un arreglo
 * con las sucursales del dueño que le pasan (`get_addresses($user_id)`) y, con variantes, hace
 * `$addresses[$address_id] += ...`: si el dueño es el equivocado la sucursal no está en el
 * arreglo y revienta con `Undefined index`. Un artículo CON VARIANTES del dueño B es la prueba: con
 * el dueño equivocado el saneo de ese artículo falla y el test se pone rojo.
 *
 * El test corre autenticado como el usuario 500 (lo hace `EmpresaTestCase`) y con
 * `config('app.USER_ID')` apuntando al OTRO dueño: ninguno de los dos es el de los artículos que
 * se sanean.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Base_compartida_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Dos comercios en la misma base, cada uno con sus sucursales y sus artículos con fantasmas.
     *
     *  - A: un artículo sin variantes (vive 10, fantasma −3).
     *  - B: un artículo sin variantes (vive 20, fantasma +5) y uno CON variantes (V vive 7; fantasma
     *       de variante −1 y del artículo +2).
     *
     * @return array
     */
    protected function escenario()
    {
        $a = $this->dueno('comercio-a');
        $b = $this->dueno('comercio-b');

        $sa = $this->sucursal($a, 'zz Sucursal de A');
        $sb = $this->sucursal($b, 'zz Sucursal de B');

        $muerta_a = $this->sucursal_muerta($a);
        $muerta_b = $this->sucursal_muerta($b);

        $articulo_a = $this->articulo_con_fantasmas($a, 'A simple', [$sa->id => 10], [[$muerta_a, -3]])['articulo'];

        $articulo_b = $this->articulo_con_fantasmas($b, 'B simple', [$sb->id => 20], [[$muerta_b, 5]])['articulo'];

        $b_con_variantes = $this->articulo_con_variantes(
            $b,
            'B con variantes',
            [['vivas' => [$sb->id => 7], 'fantasmas' => [[$muerta_b, -1]]]],
            [[$muerta_b, 2]]
        );

        return compact('a', 'b', 'sa', 'sb', 'muerta_a', 'muerta_b', 'articulo_a', 'articulo_b', 'b_con_variantes');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function user_id_acota_y_no_toca_los_articulos_de_los_otros_duenos()
    {
        $e = $this->escenario();

        $articulos_de_b = [$e['articulo_b']->id, $e['b_con_variantes']['articulo']->id];

        $b_antes = $this->foto_de_los_articulos($articulos_de_b);

        $this->assertSame(0, $this->aplicar($e['a']), 'Salida:' . "\n" . $this->salida);

        // El dueño A quedó sano.
        $this->assertSame(0, $this->filas_en($e['articulo_a'], $e['muerta_a']), 'El artículo del dueño A conserva su fantasma.');
        $this->assertEquals(10.0, $this->stock($e['articulo_a']));

        // El dueño B no se tocó: ni filas, ni stocks, ni movimientos.
        $this->assertSame($b_antes, $this->foto_de_los_articulos($articulos_de_b), '--user_id=A tocó artículos del dueño B.');
        $this->assertSame(1, $this->filas_en($e['articulo_b'], $e['muerta_b']), 'El fantasma del dueño B tiene que seguir ahí: no se lo había pedido.');
        $this->assertCount(0, $this->movimientos($e['articulo_b']));
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function sin_user_id_cada_articulo_se_recalcula_con_su_dueno_aunque_el_config_sea_otro()
    {
        $e = $this->escenario();

        // El "dueño global" del sistema apunta a A (y el usuario logueado es el 500): B no es ninguno.
        config(['app.USER_ID' => $e['a']->id]);

        // Sin --user_id: se sanea TODO lo que haya en la base (incluidas las filas ajenas que dejaron
        // otras corridas: todo dentro de la transacción del test, que se revierte).
        $this->sanear(['--aplicar' => true, '--salida' => $this->carpeta_de_salida]);

        $respaldos = $this->archivos_de($this->carpeta_de_salida, '-respaldo.jsonl');

        $this->assertCount(1, $respaldos, 'La corrida tenía que dejar su respaldo.');
        $this->assertStringContainsString('-todos-', basename($respaldos[0]), 'Sin --user_id el nombre del respaldo dice que la corrida fue sobre todos los dueños.');

        $variantes_b = $e['b_con_variantes'];

        // El artículo CON VARIANTES del dueño B: con el dueño equivocado revienta (Undefined index) y
        // queda con sus fantasmas.
        $this->assertSame(0, $this->filas_en($variantes_b['articulo'], $e['muerta_b']), 'El artículo con variantes del dueño B conserva su fantasma: se recalculó con el dueño equivocado (Undefined index) o no se tocó. Salida:' . "\n" . $this->salida);
        $this->assertSame(0, DB::table('address_article_variant')->where('article_variant_id', $variantes_b['variantes'][0]->id)->where('address_id', $e['muerta_b'])->count(), 'La variante del dueño B conserva su fantasma.');

        // El pivot del artículo se reconstruyó con las sucursales de B (y no con las de A ni las del 500).
        $pivot = $this->pivot($variantes_b['articulo']);

        $this->assertCount(1, $pivot, 'El pivot reconstruido tiene una fila por sucursal del dueño B.');
        $this->assertSame((int) $e['sb']->id, (int) $pivot[0]['address_id'], 'El pivot se reconstruyó con la sucursal de OTRO dueño: el saneo usó el dueño equivocado.');
        $this->assertEquals(7.0, $this->stock($variantes_b['articulo']));

        // Los artículos sin variantes de los dos dueños.
        $this->assertEquals(10.0, $this->stock($e['articulo_a']));
        $this->assertEquals(20.0, $this->stock($e['articulo_b']));
        $this->assertSame(0, $this->filas_en($e['articulo_a'], $e['muerta_a']));
        $this->assertSame(0, $this->filas_en($e['articulo_b'], $e['muerta_b']));

        // Y el movimiento de cada uno lleva a SU dueño como user_id.
        $mov_a = $this->movimientos($e['articulo_a']);
        $mov_b = $this->movimientos($e['articulo_b']);

        $this->assertCount(1, $mov_a);
        $this->assertCount(1, $mov_b);
        $this->assertSame((int) $e['a']->id, (int) $mov_a[0]->user_id, 'El movimiento del artículo del dueño A tiene que llevar a A como user_id.');
        $this->assertSame((int) $e['b']->id, (int) $mov_b[0]->user_id, 'El movimiento del artículo del dueño B tiene que llevar a B como user_id (no al usuario logueado ni a config(app.USER_ID)).');

        $this->assertEquals(3.0, (float) $mov_a[0]->amount, 'A: 10 − 7 (el fantasma de −3 hundía el stock).');
        $this->assertEquals(-5.0, (float) $mov_b[0]->amount, 'B: 20 − 25 (el fantasma de +5 lo inflaba).');
    }

    /**
     * El reporte separa a los dueños: una fila por cada uno.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_sin_acotar_trae_una_fila_por_dueno()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->sanear(['--ver' => true]), 'Salida:' . "\n" . $this->salida);

        $fila_a = $this->fila_del_reporte($e['a']->id);
        $fila_b = $this->fila_del_reporte($e['b']->id);

        $this->assertSame(1, $fila_a['articulos']);
        $this->assertEquals(-3.0, $fila_a['unidades_articulo']);

        $this->assertSame(2, $fila_b['articulos'], 'El dueño B tiene dos artículos afectados.');
        $this->assertSame(2, $fila_b['filas_articulo'], 'B: el fantasma del simple y el del artículo con variantes.');
        $this->assertSame(1, $fila_b['filas_variante']);
        $this->assertEquals(7.0, $fila_b['unidades_articulo'], 'B: +5 y +2.');
        $this->assertEquals(-1.0, $fila_b['unidades_variante']);
        $this->assertEquals(5.0, $fila_b['desfase'], 'B: el simple sobra 5; el de variantes tiene el stock bien.');
    }

    /**
     * Foto del contenido de las tablas que el saneo toca, para un conjunto de artículos.
     *
     * @param  int[]  $article_ids
     * @return array
     */
    protected function foto_de_los_articulos(array $article_ids)
    {
        $variantes = DB::table('article_variants')->whereIn('article_id', $article_ids)->pluck('id')->all();

        return [
            'address_article' => $this->filas(DB::table('address_article')->whereIn('article_id', $article_ids)->orderBy('id')->get()),
            'address_article_variant' => $this->filas(DB::table('address_article_variant')->whereIn('article_variant_id', $variantes)->orderBy('id')->get()),
            'articles' => $this->filas(DB::table('articles')->whereIn('id', $article_ids)->orderBy('id')->get(['id', 'stock', 'user_id'])),
            'article_variants' => $this->filas(DB::table('article_variants')->whereIn('article_id', $article_ids)->orderBy('id')->get()),
            'stock_movements' => $this->filas(DB::table('stock_movements')->whereIn('article_id', $article_ids)->orderBy('id')->get()),
        ];
    }
}
