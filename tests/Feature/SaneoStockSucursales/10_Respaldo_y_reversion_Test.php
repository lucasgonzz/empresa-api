<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Console\Commands\SanearStockDeSucursalesBorradas;
use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * RESPALDO y REVERSIÓN (misión sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * El comando toca el stock de un negocio real. Lo único que lo hace seguro de correr es que se
 * pueda volver atrás:
 *
 *   1. `--aplicar` deja un respaldo JSONL (una línea por artículo, con TODO lo que va a tocar,
 *      escrita ANTES de tocarlo) y un SQL de reversión (un bloque por artículo, cada uno su propia
 *      transacción);
 *   2. correr ese SQL devuelve la base EXACTAMENTE a como estaba —filas con su id, stocks,
 *      variantes— y borra el movimiento; el UPDATE de stock está guardado para no pisar un cambio
 *      posterior;
 *   3. sin respaldo no se escribe una fila: carpeta inutilizable, concepto inexistente o un error
 *      de escritura a mitad de la corrida cortan el comando (exit 1), y un error de escritura no
 *      deja seguir con el artículo siguiente;
 *   4. un artículo que falla se revierte solo, deja `revertido_por_error` y la corrida sigue con el
 *      resto.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Respaldo_y_reversion_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con un artículo SIN variantes (stock crudo 12, queda en 14) y uno CON variantes
     * (pivot reconstruido, stocks desviados para que haya algo que restaurar).
     *
     * @return array
     */
    protected function escenario()
    {
        $dueno = $this->dueno('respaldo');
        $s1 = $this->sucursal($dueno, 'zz Centro');
        $s2 = $this->sucursal($dueno, 'zz Norte');
        $muerta = $this->sucursal_muerta($dueno);

        $simple = $this->articulo_con_fantasmas($dueno, 'Respaldo simple', [$s1->id => 10, $s2->id => 4], [[$muerta, -3], [$muerta, -1], [$muerta, 2]]);

        $con_variantes = $this->articulo_con_variantes(
            $dueno,
            'Respaldo con variantes',
            [
                ['vivas' => [$s1->id => 6, $s2->id => 4], 'fantasmas' => [[$muerta, -1], [$muerta, -1]]],
                ['vivas' => [$s1->id => 5], 'fantasmas' => [[$muerta, 1]]],
            ],
            [[$muerta, -2]]
        );

        // Desvíos de antes del saneo: el stock de V1 cargado a mano (con una fecha vieja) y el global.
        DB::table('article_variants')->where('id', $con_variantes['variantes'][0]->id)->update(['stock' => 99, 'updated_at' => '2026-01-01 10:00:00']);
        DB::table('articles')->where('id', $con_variantes['articulo']->id)->update(['stock' => 20]);

        return compact('dueno', 's1', 's2', 'muerta', 'simple', 'con_variantes');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function aplicar_deja_el_respaldo_jsonl_con_el_estado_previo_y_un_bloque_de_sql_por_articulo()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('-respaldo.jsonl', $this->salida, 'La salida tiene que decir dónde quedó el respaldo.');
        $this->assertStringContainsString('-reversion.sql', $this->salida);
        $this->assertStringContainsString('DEL SERVIDOR', $this->salida, 'El aviso de bajar los archivos del servidor es parte del contrato del comando.');

        $lineas = $this->lineas_del_respaldo($this->carpeta_de_salida);

        $this->assertCount(2, $lineas, 'Un artículo, una línea de respaldo.');

        $por_articulo = [];
        foreach ($lineas as $linea) {
            $por_articulo[(int) $linea['article_id']] = $linea;
        }

        // El artículo simple: el estado previo completo, con los fantasmas marcados.
        $simple = $por_articulo[$e['simple']['articulo']->id];

        $this->assertSame('antes', $simple['evento']);
        $this->assertSame((int) $e['dueno']->id, (int) $simple['user_id']);
        $this->assertSame('recalcular', $simple['clase']);
        $this->assertSame(1, $simple['intento']);
        $this->assertFalse($simple['en_papelera']);
        $this->assertSame('12.00', $simple['articles_stock'], 'El respaldo guarda articles.stock tal cual estaba en la base.');
        $this->assertEquals(14.0, $simple['stock_proyectado']);
        $this->assertEquals(-2.0, $simple['desfase']);

        $fantasmas = 0;
        $vivas = 0;
        foreach ($simple['address_article'] as $fila) {
            $fila['fantasma'] ? $fantasmas++ : $vivas++;
        }

        $this->assertSame(3, $fantasmas, 'Las tres filas fantasma del artículo, marcadas.');
        $this->assertSame(2, $vivas, 'Las dos filas vivas también van en el respaldo: con variantes el pivot entero se reconstruye.');

        // El artículo con variantes: los fantasmas de variante y los stocks de las variantes.
        $con_variantes = $por_articulo[$e['con_variantes']['articulo']->id];

        $this->assertSame('20.00', $con_variantes['articles_stock']);
        $this->assertCount(3, $con_variantes['address_article_variant_fantasma'], 'Los tres fantasmas de variante, con su fila completa.');
        $this->assertCount(2, $con_variantes['variantes']);

        $stocks_de_variantes = [];
        foreach ($con_variantes['variantes'] as $variante) {
            $stocks_de_variantes[(int) $variante['id']] = (int) $variante['stock'];
        }

        $this->assertSame(99, $stocks_de_variantes[$e['con_variantes']['variantes'][0]->id], 'El respaldo guarda el stock de la variante ANTES de que el sistema lo pise.');

        // El SQL: un bloque por artículo, cada uno su propia transacción.
        $sql = $this->sql_de_reversion($this->carpeta_de_salida);

        $this->assertSame(2, substr_count($sql, "START TRANSACTION;\n"), 'Un START TRANSACTION por artículo.');
        $this->assertSame(2, substr_count($sql, "COMMIT;\n"), 'Un COMMIT por artículo: el archivo sirve aunque la corrida se corte a la mitad.');
        $this->assertStringContainsString('INSERT IGNORE INTO address_article ', $sql);
        $this->assertStringContainsString('INSERT IGNORE INTO address_article_variant ', $sql);
        $this->assertStringContainsString('DELETE FROM stock_movements WHERE id = ', $sql);
    }

    /**
     * 🔴 La prueba que importa: correr el SQL de reversión devuelve TODAS las tablas a como estaban.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function correr_el_sql_de_reversion_devuelve_la_base_exactamente_a_como_estaba()
    {
        $e = $this->escenario();

        $antes = $this->foto_de_tablas();

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $despues_de_aplicar = $this->foto_de_tablas();

        // El saneo cambió cosas de verdad (si no, "volvió a como estaba" sería trivial).
        $this->assertNotSame($antes['address_article']['huella'], $despues_de_aplicar['address_article']['huella'], 'El saneo no cambió address_article.');
        $this->assertNotSame($antes['address_article_variant']['huella'], $despues_de_aplicar['address_article_variant']['huella'], 'El saneo no cambió address_article_variant.');
        $this->assertNotSame($antes['articles']['huella'], $despues_de_aplicar['articles']['huella'], 'El saneo no cambió articles.stock.');
        $this->assertNotSame($antes['article_variants']['huella'], $despues_de_aplicar['article_variants']['huella'], 'El saneo no cambió article_variants.stock.');
        $this->assertSame($antes['stock_movements']['filas'] + 2, $despues_de_aplicar['stock_movements']['filas'], 'El saneo tenía que dejar un movimiento por cada artículo (14 − 12 y 15 − 20).');

        $ejecutadas = $this->correr_reversion($this->sql_de_reversion($this->carpeta_de_salida));

        $this->assertGreaterThan(0, $ejecutadas);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Correr el SQL de reversión no devolvió la base a como estaba');

        // En concreto: las filas fantasma vuelven con su id y los movimientos se van.
        $this->assertSame(3, $this->filas_en($e['simple']['articulo'], $e['muerta']));
        $this->assertCount(0, $this->movimientos($e['simple']['articulo']));
        $this->assertCount(0, $this->movimientos($e['con_variantes']['articulo']));
        $this->assertEquals(12.0, $this->stock($e['simple']['articulo']));
        $this->assertEquals(20.0, $this->stock($e['con_variantes']['articulo']));
        $this->assertSame('2026-01-01 10:00:00', DB::table('article_variants')->where('id', $e['con_variantes']['variantes'][0]->id)->value('updated_at'), 'La reversión también devuelve el updated_at de la variante que el sistema tocó.');
    }

    /**
     * El UPDATE de stock de la reversión está GUARDADO con el valor que dejó el saneo: si después
     * entró una venta, no se la pisa.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_update_de_stock_de_la_reversion_no_pisa_un_cambio_posterior()
    {
        $e = $this->escenario();

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Salida:' . "\n" . $this->salida);

        $articulo = $e['simple']['articulo'];

        $this->assertEquals(14.0, $this->stock($articulo));

        // Una venta posterior al saneo (−2): el stock pasa a 12.
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 12]);

        $this->correr_reversion($this->sql_de_reversion($this->carpeta_de_salida));

        $this->assertEquals(12.0, $this->stock($articulo), 'La reversión pisó un cambio de stock posterior al saneo: el UPDATE tiene que estar guardado con AND stock = <el que dejó el saneo>.');
        $this->assertSame(3, $this->filas_en($articulo, $e['muerta']), 'Las filas sí se devuelven.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_carpeta_de_salida_inutilizable_es_exit_1_y_no_escribe_una_sola_fila()
    {
        $e = $this->escenario();

        // Un ARCHIVO donde tendría que ir la carpeta: no se puede crear ni escribir adentro.
        $archivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saneo-stock-no-es-carpeta-' . uniqid();

        file_put_contents($archivo, 'soy un archivo');

        try {
            $antes = $this->foto_de_tablas();

            $codigo = $this->sanear(['--aplicar' => true, '--user_id' => $e['dueno']->id, '--salida' => $archivo]);

            $this->assertSame(1, $codigo, 'Sin poder dejar el respaldo el comando tiene que terminar con 1. Salida:' . "\n" . $this->salida);
            $this->assertStringContainsString('NO se tocó la base', $this->salida);

            $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Con la carpeta de salida inutilizable el comando escribió en la base');
        } finally {
            @unlink($archivo);
        }
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function sin_el_concepto_de_stock_el_comando_se_niega_a_escribir()
    {
        $e = $this->escenario();

        DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->delete();

        $this->assertNull(DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->first(), 'El escenario no quedó armado: el concepto tenía que no existir.');

        // --ver avisa, para que Lucas se entere antes de --aplicar.
        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('no existe el concepto de stock', $this->salida, '--ver tiene que avisar que --aplicar se negaría a escribir.');

        $antes = $this->foto_de_tablas();

        $codigo = $this->aplicar($e['dueno']);

        $this->assertSame(1, $codigo, 'Sin el concepto, --aplicar tiene que terminar con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('No existe el concepto de stock', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Sin el concepto el comando escribió en la base (etiquetar con un concepto ajeno corrompe el libro)');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'Sin el concepto el comando dejó archivos de respaldo.');
    }

    /**
     * Un artículo cuya transacción falla se revierte solo y el resto sigue.
     *
     * La falla se provoca al crear el movimiento DEL SEGUNDO artículo: para ese momento el saneo ya
     * borró sus filas y recalculó su stock, así que el test prueba que el rollback devuelve todo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_que_falla_se_revierte_deja_revertido_por_error_y_el_resto_sigue()
    {
        $dueno = $this->dueno('falla');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $primero = $this->articulo_con_fantasmas($dueno, 'Falla uno', [$s1->id => 10], [[$muerta, -1]]);
        $segundo = $this->articulo_con_fantasmas($dueno, 'Falla dos', [$s1->id => 10], [[$muerta, -2]]);
        $tercero = $this->articulo_con_fantasmas($dueno, 'Falla tres', [$s1->id => 10], [[$muerta, -3]]);

        $id_que_falla = (int) $segundo['articulo']->id;

        StockMovement::creating(function ($movimiento) use ($id_que_falla) {
            if ((int) $movimiento->article_id === $id_que_falla) {
                throw new \RuntimeException('falla de prueba al crear el movimiento');
            }
        });

        $pivot_antes = $this->pivot($segundo['articulo']);

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con un artículo fallido el comando termina con 1 (hay que volver a correrlo). Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Fallidos: 1', $this->salida);
        $this->assertStringContainsString('falla de prueba al crear el movimiento', $this->salida);
        $this->assertStringContainsString('continúa donde quedó', $this->salida);

        // El que falló quedó EXACTAMENTE como estaba: filas, stock y sin movimiento.
        $this->assertSame($pivot_antes, $this->pivot($segundo['articulo']), 'El artículo que falló no volvió a su estado: el rollback no deshizo el borrado de sus filas.');
        $this->assertEquals(8.0, $this->stock($segundo['articulo']), 'El artículo que falló tiene que conservar su stock crudo.');
        $this->assertCount(0, $this->movimientos($segundo['articulo']));

        // Los otros dos se saneron igual.
        foreach ([$primero, $tercero] as $sano) {
            $this->assertSame(0, $this->filas_en($sano['articulo'], $muerta), 'Un artículo fallido no puede frenar a los demás.');
            $this->assertEquals(10.0, $this->stock($sano['articulo']));
            $this->assertCount(1, $this->movimientos($sano['articulo']));
        }

        // El respaldo: las tres líneas "antes" (write-ahead, también la del que falló) y la marca del error.
        $lineas = $this->lineas_del_respaldo($this->carpeta_de_salida);

        $antes = array_filter($lineas, function ($linea) {
            return $linea['evento'] === 'antes';
        });
        $errores = array_values(array_filter($lineas, function ($linea) {
            return $linea['evento'] === 'revertido_por_error';
        }));

        $this->assertCount(3, $antes, 'El respaldo se escribe ANTES de tocar el artículo: también hay línea del que después falló.');
        $this->assertCount(1, $errores, 'El artículo fallido deja UNA línea revertido_por_error.');
        $this->assertSame($id_que_falla, (int) $errores[0]['article_id']);
        $this->assertStringContainsString('falla de prueba', $errores[0]['error']);
        $this->assertFalse($errores[0]['bloque_sql_escrito'], 'Falló antes de escribir su bloque de reversión.');

        // El SQL: solo los dos que se sanearon. El fallido no tiene bloque.
        $sql = $this->sql_de_reversion($this->carpeta_de_salida);

        $this->assertSame(2, substr_count($sql, "START TRANSACTION;\n"), 'El artículo fallido no tiene que dejar bloque de reversión.');
        $this->assertStringNotContainsString('-- Artículo ' . $id_que_falla . ' ', $sql);
    }

    /**
     * 🔴 Sin respaldo no se escribe: si NO se puede dejar el respaldo de un artículo, el comando no
     * lo toca y corta la corrida (no sigue con el próximo). Se simula un disco lleno con una
     * subclase que hace fallar la escritura número N.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_no_se_puede_escribir_el_respaldo_no_se_toca_el_articulo_y_se_corta_la_corrida()
    {
        $dueno = $this->dueno('disco-lleno');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $primero = $this->articulo_con_fantasmas($dueno, 'Disco uno', [$s1->id => 10], [[$muerta, -1]]);
        $segundo = $this->articulo_con_fantasmas($dueno, 'Disco dos', [$s1->id => 10], [[$muerta, -2]]);
        $tercero = $this->articulo_con_fantasmas($dueno, 'Disco tres', [$s1->id => 10], [[$muerta, -3]]);

        // Escrituras que salen bien: 1 encabezado del SQL, 2 respaldo del primero, 3 bloque del
        // primero. La 4 (el respaldo del segundo) falla: disco lleno.
        $this->registrar_comando_con_disco_que_se_llena(3);

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Un error de respaldo tiene que terminar con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('No se pudo escribir el respaldo', $this->salida);
        $this->assertStringContainsString('Se corta la corrida', $this->salida);

        // El primero se sanó (su respaldo y su bloque salieron bien)...
        $this->assertSame(0, $this->filas_en($primero['articulo'], $muerta));
        $this->assertEquals(10.0, $this->stock($primero['articulo']));

        // ...el segundo NO se tocó (no hubo respaldo) y el tercero tampoco (la corrida se cortó).
        foreach ([$segundo, $tercero] as $intacto) {
            $this->assertSame(1, $this->filas_en($intacto['articulo'], $muerta), 'Sin respaldo no se toca el artículo ni se sigue con el próximo.');
            $this->assertCount(0, $this->movimientos($intacto['articulo']));
        }

        $this->assertEquals(8.0, $this->stock($segundo['articulo']));
        $this->assertEquals(7.0, $this->stock($tercero['articulo']));
    }

    /**
     * Con un disco que se llena en la PRIMERA escritura de un artículo no se toca nada.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_el_respaldo_falla_desde_el_primer_articulo_no_se_escribe_ninguna_fila()
    {
        $dueno = $this->dueno('disco-lleno-ya');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $this->articulo_con_fantasmas($dueno, 'Disco ya uno', [$s1->id => 10], [[$muerta, -1]]);
        $this->articulo_con_fantasmas($dueno, 'Disco ya dos', [$s1->id => 10], [[$muerta, -2]]);

        // Solo el encabezado del SQL sale bien.
        $this->registrar_comando_con_disco_que_se_llena(1);

        $antes = $this->foto_de_tablas();

        $this->assertSame(1, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'El respaldo falló y aun así se escribió en la base');
    }

    /**
     * El bloque de reversión de un artículo, con una entrada armada a mano: los valores que salen
     * de la base se escriben tal cual (decimales, NULL, fechas entre comillas) y cada UPDATE de
     * stock va guardado con el valor que dejó el saneo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function el_bloque_de_reversion_escribe_los_valores_tal_cual_y_guarda_los_updates()
    {
        $bloque = FilasFantasmaDeSucursalHelper::armar_sql_de_reversion([
            'article_id' => 5,
            'user_id' => 9,
            'clase' => 'recalcular',
            'filas_articulo_borradas' => [
                ['id' => 71, 'article_id' => 5, 'address_id' => 3, 'amount' => '-1.00', 'created_at' => null, 'updated_at' => '2026-10-05 12:30:00', 'stock_min' => null, 'stock_max' => 7],
            ],
            'filas_variante_borradas' => [
                ['id' => 12, 'address_id' => 3, 'article_variant_id' => 8, 'amount' => 4, 'on_display' => null, 'created_at' => null, 'updated_at' => null],
            ],
            'pivot_reconstruido' => false,
            'filas_articulo_previas' => [],
            'stock_antes' => '10.00',
            'stock_despues' => '11.00',
            'variantes_cambiadas' => [
                ['id' => 8, 'stock_antes' => 99, 'updated_at_antes' => '2026-01-01 10:00:00', 'stock_despues' => 4],
            ],
            'movimiento_id' => 77,
        ]);

        $esperado = implode("\n", [
            '-- Artículo 5 (dueño 9, clase recalcular) · movimiento 77',
            'START TRANSACTION;',
            'DELETE FROM stock_movements WHERE id = 77 AND article_id = 5;',
            "INSERT IGNORE INTO address_article (id, article_id, address_id, amount, created_at, updated_at, stock_min, stock_max) VALUES (71, 5, 3, -1.00, NULL, '2026-10-05 12:30:00', NULL, 7);",
            'INSERT IGNORE INTO address_article_variant (id, address_id, article_variant_id, amount, on_display, created_at, updated_at) VALUES (12, 3, 8, 4, NULL, NULL, NULL);',
            "UPDATE article_variants SET stock = 99, updated_at = '2026-01-01 10:00:00' WHERE id = 8 AND stock = 4;",
            'UPDATE articles SET stock = 10.00 WHERE id = 5 AND stock = 11.00;',
            'COMMIT;',
        ]) . "\n\n";

        $this->assertSame($esperado, $bloque);

        // Con el pivot reconstruido: se vacía el del artículo y vuelven TODAS las filas de antes.
        $reconstruido = FilasFantasmaDeSucursalHelper::armar_sql_de_reversion([
            'article_id' => 5,
            'user_id' => 9,
            'clase' => 'recalcular',
            'filas_articulo_borradas' => [],
            'filas_variante_borradas' => [],
            'pivot_reconstruido' => true,
            'filas_articulo_previas' => [
                ['id' => 70, 'article_id' => 5, 'address_id' => 2, 'amount' => '6.00', 'created_at' => null, 'updated_at' => null, 'stock_min' => 1, 'stock_max' => null],
                ['id' => 71, 'article_id' => 5, 'address_id' => 3, 'amount' => '-1.00', 'created_at' => null, 'updated_at' => null, 'stock_min' => null, 'stock_max' => null],
            ],
            'stock_antes' => null,
            'stock_despues' => '0.00',
            'variantes_cambiadas' => [],
            'movimiento_id' => null,
        ]);

        $this->assertStringContainsString("DELETE FROM address_article WHERE article_id = 5;\n", $reconstruido);
        $this->assertStringContainsString('VALUES (70, 5, 2, 6.00, NULL, NULL, 1, NULL), (71, 5, 3, -1.00, NULL, NULL, NULL, NULL);', $reconstruido, 'Vuelven TODAS las filas del pivot con su id, vivas y fantasma.');
        $this->assertStringContainsString('UPDATE articles SET stock = NULL WHERE id = 5 AND stock = 0.00;', $reconstruido, 'Un stock que antes era NULL vuelve a NULL.');
        $this->assertStringNotContainsString('stock_movements', $reconstruido, 'Sin movimiento no hay nada que borrar.');
    }

    /**
     * Registra en Artisan una versión del comando donde, después de `$escrituras_que_salen_bien`
     * escrituras a los archivos de respaldo, todas las siguientes fallan (un disco que se llena).
     *
     * @param  int  $escrituras_que_salen_bien
     * @return void
     */
    protected function registrar_comando_con_disco_que_se_llena($escrituras_que_salen_bien)
    {
        $comando = new class($escrituras_que_salen_bien) extends SanearStockDeSucursalesBorradas {
            /** @var int */
            private $escrituras_restantes;

            public function __construct($escrituras)
            {
                parent::__construct();

                $this->escrituras_restantes = $escrituras;
            }

            protected function volcar($manejador, $texto)
            {
                if ($this->escrituras_restantes <= 0) {
                    return false;
                }

                $this->escrituras_restantes--;

                return parent::volcar($manejador, $texto);
            }
        };

        // `all()` fuerza el arranque del Kernel y la carga de los comandos de app/Console/Commands:
        // si el primero que se registra es el de este test, el Artisan nace sin ellos y el que
        // reemplaza al original terminaría sin nada que reemplazar.
        Artisan::all();

        // El registro por nombre pisa al comando original (misma firma).
        Artisan::registerCommand($comando);
    }
}
