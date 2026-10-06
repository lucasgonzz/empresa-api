<?php

namespace Tests\Feature\SaneoStockSucursales;

/**
 * POR LOTES y validación de las opciones (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * Una base real tiene decenas de miles de artículos: el comando los junta (solo ids) y los procesa
 * en tandas de `--lote`. El tamaño de la tanda es un detalle de memoria y de progreso, no puede
 * cambiar el resultado:
 *
 *   1. `--lote` chico y `--lote` grande dejan EXACTAMENTE lo mismo;
 *   2. la salida informa el avance por tanda;
 *   3. una opción inválida es un error de uso (exit 1) y NO escribe nada: mejor frenar que
 *      interpretar un `--lote=0` o un `--user_id=abc` y sanear lo que nadie pidió.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Por_lotes_y_opciones_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con cinco artículos de distinta forma (vivas y fantasmas distintas), con la misma
     * receta en cada "gemelo" para poder comparar los resultados.
     *
     * @param  string  $etiqueta
     * @return array  ['dueno', 'articulos' => Article[]]
     */
    protected function dueno_con_cinco_articulos($etiqueta)
    {
        $dueno = $this->dueno($etiqueta);
        $s1 = $this->sucursal($dueno);
        $s2 = $this->sucursal($dueno, 'zz Segunda');
        $muerta = $this->sucursal_muerta($dueno);

        $articulos = [];

        $articulos[] = $this->articulo_con_fantasmas($dueno, 'Lote 1', [$s1->id => 10], [[$muerta, -1]])['articulo'];
        $articulos[] = $this->articulo_con_fantasmas($dueno, 'Lote 2', [$s1->id => 4, $s2->id => 6], [[$muerta, 3], [$muerta, 2]])['articulo'];
        $articulos[] = $this->articulo_con_fantasmas($dueno, 'Lote 3', [$s2->id => 8], [[$muerta, -8]])['articulo'];
        $articulos[] = $this->articulo_con_fantasmas($dueno, 'Lote 4', [], [[$muerta, -2]], 9)['articulo'];
        $articulos[] = $this->articulo_con_variantes($dueno, 'Lote 5', [['vivas' => [$s1->id => 3], 'fantasmas' => [[$muerta, -1]]]], [[$muerta, 1]])['articulo'];

        return ['dueno' => $dueno, 'articulos' => $articulos, 'muerta' => $muerta];
    }

    /**
     * El estado de los cinco artículos sin ids propios: stock, montos del pivot, movimientos.
     *
     * @param  array  $e
     * @return array
     */
    protected function estado_de_los_cinco(array $e)
    {
        $estado = [];

        foreach ($e['articulos'] as $indice => $articulo) {
            $estado[$indice] = [
                'stock' => $this->stock($articulo),
                'pivot' => array_map(function ($fila) {
                    return (float) $fila['amount'];
                }, $this->pivot($articulo)),
                'movimientos' => $this->movimientos($articulo)->map(function ($movimiento) {
                    return (float) $movimiento->amount;
                })->all(),
            ];
        }

        return $estado;
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_lote_chico_deja_el_mismo_resultado_que_uno_grande_e_informa_cada_tanda()
    {
        $grande = $this->dueno_con_cinco_articulos('lote-grande');
        $chico = $this->dueno_con_cinco_articulos('lote-chico');

        $this->assertSame(0, $this->aplicar($grande['dueno'], ['--lote' => 1000, '--salida' => $this->carpeta_nueva()]), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Tanda 1/1', $this->salida, 'Con un lote grande hay una sola tanda.');

        $this->assertSame(0, $this->aplicar($chico['dueno'], ['--lote' => 2, '--salida' => $this->carpeta_nueva()]), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Tanda 1/3', $this->salida, 'Cinco artículos en lotes de 2 son 3 tandas.');
        $this->assertStringContainsString('Tanda 2/3', $this->salida);
        $this->assertStringContainsString('Tanda 3/3', $this->salida);
        $this->assertStringNotContainsString('Tanda 4/3', $this->salida);

        // Nada con fantasmas en ninguno de los dos.
        foreach ([$grande, $chico] as $e) {
            foreach ($e['articulos'] as $articulo) {
                $this->assertSame(0, $this->filas_en($articulo, $e['muerta']), 'Quedó un fantasma del artículo ' . $articulo->id . '.');
            }
        }

        $this->assertEquals($this->estado_de_los_cinco($grande), $this->estado_de_los_cinco($chico), 'El tamaño del lote no puede cambiar el resultado.');

        // Y se hicieron las correcciones: 4 de los 5 cambian el stock global (el 4 es solo fantasmas y el 5 ya estaba bien).
        $con_movimiento = 0;
        foreach ($chico['articulos'] as $articulo) {
            $con_movimiento += $this->movimientos($articulo)->count();
        }

        $this->assertSame(3, $con_movimiento, 'Tres artículos cambian de stock global: los dos de variantes y solo-fantasmas no.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_opcion_invalida_es_un_error_de_uso_y_no_escribe_nada()
    {
        $e = $this->dueno_con_cinco_articulos('opciones');

        $antes = $this->foto_de_tablas();

        $invalidas = [
            '--lote=0' => ['--lote' => '0'],
            '--lote=abc' => ['--lote' => 'abc'],
            '--lote=-3' => ['--lote' => '-3'],
            '--limite=0' => ['--limite' => '0'],
            '--limite=2.5' => ['--limite' => '2.5'],
            '--user_id=abc' => ['--user_id' => 'abc'],
            '--articulo_id=0' => ['--articulo_id' => '0'],
        ];

        foreach ($invalidas as $descripcion => $opciones) {
            $codigo = $this->sanear(array_merge(['--aplicar' => true, '--salida' => $this->carpeta_de_salida], $opciones));

            $this->assertSame(1, $codigo, $descripcion . ' tiene que ser un error de uso (exit 1). Salida:' . "\n" . $this->salida);
            $this->assertStringContainsString('entero positivo', $this->salida, $descripcion . ' tiene que explicar qué esperaba.');
        }

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Una opción inválida escribió en la base');
        $this->assertDirectoryDoesNotExist($this->carpeta_de_salida, 'Una opción inválida dejó archivos de respaldo.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function articulo_id_acota_a_un_solo_articulo()
    {
        $e = $this->dueno_con_cinco_articulos('articulo-id');

        $elegido = $e['articulos'][1];

        // Filas fantasma de address_article de cada artículo ANTES (el 2 tiene dos filas; el resto, una).
        $antes = [];

        foreach ($e['articulos'] as $articulo) {
            $antes[$articulo->id] = $this->filas_en($articulo, $e['muerta']);
        }

        $this->assertSame(2, $antes[$elegido->id], 'El escenario no quedó armado: el artículo elegido tenía dos filas fantasma.');

        $stocks_antes = [];

        foreach ($e['articulos'] as $articulo) {
            $stocks_antes[$articulo->id] = $this->stock($articulo);
        }

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--articulo_id' => $elegido->id]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($elegido, $e['muerta']), 'El artículo pedido tenía que sanearse.');

        $respaldos = $this->archivos_de($this->carpeta_de_salida, '-respaldo.jsonl');

        $this->assertCount(1, $respaldos);
        $this->assertStringContainsString('-user' . $e['dueno']->id . '-art' . $elegido->id . '-', basename($respaldos[0]), 'El nombre del respaldo tiene que decir el artículo al que se acotó la corrida.');
        $this->assertCount(1, $this->lineas_del_respaldo($this->carpeta_de_salida), 'Acotado a un artículo, el respaldo tiene una sola línea.');

        foreach ($e['articulos'] as $articulo) {
            if ($articulo->id === $elegido->id) {
                continue;
            }

            $this->assertSame($antes[$articulo->id], $this->filas_en($articulo, $e['muerta']), '--articulo_id tocó otro artículo (' . $articulo->id . '): le cambió las filas fantasma.');
            $this->assertEquals($stocks_antes[$articulo->id], $this->stock($articulo), '--articulo_id tocó otro artículo (' . $articulo->id . '): le cambió el stock.');
            $this->assertCount(0, $this->movimientos($articulo), '--articulo_id le dejó un movimiento a otro artículo (' . $articulo->id . ').');
        }
    }
}
