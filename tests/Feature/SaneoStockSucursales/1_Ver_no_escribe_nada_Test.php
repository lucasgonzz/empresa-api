<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * `stock:sanear-sucursales-borradas --ver` (el modo por defecto) SOLO LEE (misión
 * sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * Lo que este test protege: Lucas corre `--ver` en la base de un cliente real para decidir si
 * sanea. Si `--ver` escribiera algo —una fila, un movimiento, un archivo de respaldo— dejaría de ser
 * una mirada y pasaría a ser una intervención sin que nadie lo haya pedido.
 *
 *   1. `--ver` no cambia NI UNA fila de ninguna de las siete tablas que el comando podría tocar, y
 *      no crea la carpeta de salida (ni deja nada en la carpeta por defecto de `storage/`);
 *   2. sin ningún flag el comando corre en modo `--ver`;
 *   3. `--ver` y `--aplicar` juntos: gana la lectura, no se escribe nada y se avisa;
 *   4. el reporte trae, por dueño, las filas fantasma de cada pivot, los artículos, los de la
 *      papelera, las unidades CON SIGNO y el desfase de `articles.stock`;
 *   5. `--detalle` lista artículo por artículo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Ver_no_escribe_nada_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con los tres tipos de artículo que el reporte tiene que contar: uno común con
     * filas fantasma positivas y negativas, uno en la papelera y uno con variantes.
     *
     * Números (los que el reporte tiene que mostrar):
     *
     *  - X1 (sin variantes): vive S1 = 10; fantasmas en D1 de −3 y +1  → stock crudo 8, queda en 10 (desfase −2).
     *  - X2 (papelera):      vive S1 = 5;  fantasma en D2 de −4         → stock crudo 1, queda en 5  (desfase −4).
     *  - X3 (con variante):  la variante vive S1 = 7; fantasma de variante en D1 de −2 y fantasma de
     *                        artículo en D1 de +3; el motor ya dejó el stock en 7 (los fantasmas de
     *                        variante no suman)                          → desfase 0.
     *
     * @return array
     */
    protected function dueno_con_de_todo()
    {
        $dueno = $this->dueno('ver');
        $s1 = $this->sucursal($dueno);
        $d1 = $this->sucursal_muerta($dueno);
        $d2 = $this->sucursal_muerta($dueno);

        $x1 = $this->articulo_con_fantasmas($dueno, 'X1 comun', [$s1->id => 10], [[$d1, -3], [$d1, 1]]);

        $x2 = $this->articulo_con_fantasmas($dueno, 'X2 papelera', [$s1->id => 5], [[$d2, -4]]);
        DB::table('articles')->where('id', $x2['articulo']->id)->update(['deleted_at' => now()]);

        $x3 = $this->articulo_con_variantes(
            $dueno,
            'X3 variantes',
            [['vivas' => [$s1->id => 7], 'fantasmas' => [[$d1, -2]]]],
            [[$d1, 3]]
        );

        return compact('dueno', 's1', 'd1', 'd2', 'x1', 'x2', 'x3');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function ver_no_cambia_ninguna_tabla_ni_crea_archivos()
    {
        $e = $this->dueno_con_de_todo();

        $carpeta_por_defecto = storage_path('app/saneo-stock-sucursales-borradas');
        $archivos_por_defecto_antes = (array) glob($carpeta_por_defecto . DIRECTORY_SEPARATOR . '*');

        $antes = $this->foto_de_tablas();

        $codigo = $this->sanear(['--ver' => true, '--user_id' => $e['dueno']->id, '--salida' => $this->carpeta_de_salida]);

        $this->assertSame(0, $codigo, 'El --ver tiene que terminar bien. Salida:' . "\n" . $this->salida);

        // El fixture es de verdad algo para sanear: si no, "no cambió nada" sería trivial.
        $this->assertSame(3, $this->fila_del_reporte($e['dueno']->id)['articulos'], 'El escenario no quedó armado: --ver tenía que ver los 3 artículos con fantasmas.');

        $despues = $this->foto_de_tablas();

        $this->assertFotosIguales($antes, $despues, '--ver escribió en la base');

        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, '--ver creó la carpeta de salida: no tiene que escribir archivos.');

        $archivos_por_defecto_despues = (array) glob($carpeta_por_defecto . DIRECTORY_SEPARATOR . '*');

        $this->assertSame($archivos_por_defecto_antes, $archivos_por_defecto_despues, '--ver dejó archivos en la carpeta de salida por defecto (storage/app).');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function sin_ningun_flag_el_comando_corre_en_modo_ver()
    {
        $e = $this->dueno_con_de_todo();

        $antes = $this->foto_de_tablas();

        $codigo = $this->sanear(['--user_id' => $e['dueno']->id, '--salida' => $this->carpeta_de_salida]);

        $this->assertSame(0, $codigo, 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Modo: VER', $this->salida, 'Sin flags el comando tiene que anunciar que corre en modo VER.');
        $this->assertStringNotContainsString('Modo: APLICAR', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'El comando sin flags escribió en la base (tiene que ser --ver)');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'El comando sin flags creó la carpeta de salida.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function ver_y_aplicar_juntos_gana_la_lectura_y_avisa()
    {
        $e = $this->dueno_con_de_todo();

        $antes = $this->foto_de_tablas();

        $codigo = $this->sanear(['--ver' => true, '--aplicar' => true, '--user_id' => $e['dueno']->id, '--salida' => $this->carpeta_de_salida]);

        $this->assertSame(0, $codigo, 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('gana la lectura', $this->salida, 'Con --ver y --aplicar juntos el comando tiene que avisar que gana la lectura.');
        $this->assertStringContainsString('Modo: VER', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Con --ver y --aplicar juntos se escribió en la base (tiene que ganar la lectura)');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'Con --ver y --aplicar juntos se creó la carpeta de salida.');
    }

    /**
     * El reporte por dueño con los números del escenario (ver `dueno_con_de_todo()`):
     *
     *  - filas fantasma de address_article:         X1 2 + X2 1 + X3 1 = 4
     *  - filas fantasma de address_article_variant: X3 1               = 1
     *  - artículos 3, de los cuales 1 en la papelera
     *  - unidades address_article: (−3 + 1) + (−4) + 3 = −3 ; address_article_variant: −2
     *  - desfase: −2 + −4 + 0 = −6
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_reporte_informa_por_dueno_filas_articulos_papelera_unidades_con_signo_y_desfase()
    {
        $e = $this->dueno_con_de_todo();

        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $fila = $this->fila_del_reporte($e['dueno']->id);

        $this->assertSame(4, $fila['filas_articulo'], 'Filas fantasma de address_article del dueño.');
        $this->assertSame(1, $fila['filas_variante'], 'Filas fantasma de address_article_variant del dueño.');
        $this->assertSame(3, $fila['articulos'], 'Artículos afectados (los de la papelera también cuentan).');
        $this->assertSame(1, $fila['papelera'], 'De los afectados, cuántos están en la papelera.');
        $this->assertEquals(-3.0, $fila['unidades_articulo'], 'Unidades con signo de las filas fantasma de address_article.');
        $this->assertEquals(-2.0, $fila['unidades_variante'], 'Unidades con signo de las filas fantasma de address_article_variant.');
        $this->assertEquals(-6.0, $fila['desfase'], 'Desfase: articles.stock menos la suma de las sucursales vivas.');

        // Acotado a un dueño, el TOTAL es el mismo dueño.
        $this->assertSame($fila, $this->fila_del_reporte('TOTAL'), 'Acotado por --user_id el TOTAL tiene que ser el del dueño.');

        // Las sucursales borradas con sus filas: D1 tiene 4 filas (X1 2, X3 una de cada pivot) y D2 una.
        $this->assertStringContainsString('#' . $e['d1'] . ': 4 filas (-1.00 u)', $this->salida, 'La sucursal borrada D1 tiene que figurar con sus 4 filas y sus unidades con signo.');
        $this->assertStringContainsString('#' . $e['d2'] . ': 1 filas (-4.00 u)', $this->salida, 'La sucursal borrada D2 tiene que figurar con su fila.');

        // Y las tres clases: los tres son `recalcular`.
        $this->assertStringContainsString('recalcular 3 · solo_fantasmas 0 · no_recalculable 0', $this->salida);
    }

    /**
     * Las sucursales borradas se listan de la que más filas tiene a la que menos, y como mucho diez
     * (una base vieja puede tener decenas de sucursales borradas con filas).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function las_sucursales_borradas_se_listan_de_mas_filas_a_menos_y_hasta_diez()
    {
        $dueno = $this->dueno('top-muertas');
        $s1 = $this->sucursal($dueno);

        // Doce sucursales borradas; la k-ésima con k filas fantasma de −1.
        $muertas = [];
        $fantasmas = [];

        for ($k = 1; $k <= 12; $k++) {
            $muertas[$k] = $this->sucursal_muerta($dueno);

            for ($fila = 0; $fila < $k; $fila++) {
                $fantasmas[] = [$muertas[$k], -1];
            }
        }

        $this->articulo_con_fantasmas($dueno, 'Top de muertas', [$s1->id => 200], $fantasmas);

        $this->assertSame(0, $this->ver($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Sucursales borradas con filas (12, se listan las 10 con más filas)', $this->salida);

        // Las 10 con más filas (k = 12 ... 3), en ese orden.
        $posicion_anterior = -1;

        for ($k = 12; $k >= 3; $k--) {
            $texto = '#' . $muertas[$k] . ': ' . $k . ' filas (-' . $k . '.00 u)';

            $posicion = strpos($this->salida, $texto);

            $this->assertNotFalse($posicion, 'Falta en el reporte: ' . $texto);
            $this->assertGreaterThan($posicion_anterior, $posicion, 'La sucursal con ' . $k . ' filas tiene que ir después de la que tiene más.');

            $posicion_anterior = $posicion;
        }

        // Las dos con menos filas quedan afuera.
        $this->assertStringNotContainsString('#' . $muertas[2] . ': ', $this->salida, 'Solo se listan las diez con más filas.');
        $this->assertStringNotContainsString('#' . $muertas[1] . ': ', $this->salida, 'Solo se listan las diez con más filas.');
    }

    /**
     * `--detalle` lista artículo por artículo, pero con tope: en una base con miles de afectados un
     * volcado entero ahogaría la consola y escondería el resumen. Corta a las 200 líneas y avisa.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_detalle_se_corta_a_las_200_lineas_y_avisa_cuantos_articulos_faltan()
    {
        $dueno = $this->dueno('detalle-tope');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // 205 artículos con un fantasma cada uno. Se arman con inserts masivos: esto prueba el
        // formato del reporte y no el motor, y 205 `Article::create` serían segundos al pedo.
        $filas_de_articulos = [];

        for ($i = 1; $i <= 205; $i++) {
            $filas_de_articulos[] = ['name' => 'zz Detalle tope ' . $i . ' ' . uniqid(), 'user_id' => $dueno->id, 'stock' => 9];
        }

        DB::table('articles')->insert($filas_de_articulos);

        $ids = DB::table('articles')->where('user_id', $dueno->id)->orderBy('id')->pluck('id')->all();

        $this->assertCount(205, $ids, 'El escenario no quedó armado.');

        $filas_de_pivot = [];

        foreach ($ids as $id) {
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $s1->id, 'amount' => 10];
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $muerta, 'amount' => -1];
        }

        DB::table('address_article')->insert($filas_de_pivot);

        $this->assertSame(0, $this->ver($dueno, ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(205, $this->fila_del_reporte($dueno->id)['articulos'], 'La tabla cuenta TODOS los artículos aunque el detalle se corte.');

        $this->assertSame(200, preg_match_all('/^  art \d+ · dueño /mu', $this->salida), 'El detalle tiene que listar exactamente 200 artículos.');
        $this->assertStringContainsString('... y 5 artículos más (el detalle se corta a las 200 líneas; --sin_tope las lista todas)', $this->salida, 'El aviso del corte tiene que decir cómo listar todos.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_detalle_lista_cada_articulo_con_su_clase_y_su_stock()
    {
        $e = $this->dueno_con_de_todo();

        $this->assertSame(0, $this->ver($e['dueno'], ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        foreach (['x1', 'x2', 'x3'] as $clave) {
            $this->assertStringContainsString('art ' . $e[$clave]['articulo']->id . ' ', $this->salida, 'El --detalle no lista el artículo ' . $clave . '.');
        }

        // X1: stock 8 → 10, desfase −2. Es la línea que une el desfase con el stock proyectado.
        $this->assertMatchesRegularExpression(
            '/art ' . $e['x1']['articulo']->id . ' .*recalcular.*stock 8\.00 → 10\.00 \(desfase -2\.00\)/u',
            $this->salida,
            'El detalle de X1 tiene que mostrar el stock de hoy, el que queda y el desfase.'
        );

        // El de la papelera lo dice.
        $this->assertMatchesRegularExpression('/art ' . $e['x2']['articulo']->id . ' .*papelera/u', $this->salida);
    }
}
