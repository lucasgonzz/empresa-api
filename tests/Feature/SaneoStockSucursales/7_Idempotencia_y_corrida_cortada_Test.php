<?php

namespace Tests\Feature\SaneoStockSucursales;

use Illuminate\Support\Facades\DB;

/**
 * IDEMPOTENCIA y corrida cortada (misión sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * El comando corre sobre la base de un cliente real, por SSH, con el sistema en vivo. Tiene que ser
 * seguro volver a correrlo:
 *
 *   1. una SEGUNDA `--aplicar` no encuentra nada: ni filas, ni movimientos nuevos, ni archivos de
 *      respaldo vacíos; y un `--ver` posterior no ve ningún artículo;
 *   2. una corrida CORTADA (`--limite`) y retomada deja el mismo resultado que una sola corrida:
 *      cada artículo es su propia transacción, así que lo hecho queda hecho y lo que falta sigue
 *      detectable;
 *   3. `--limite` cuenta artículos saneados: los que el comando NO toca (los `no_recalculable`) no
 *      pueden comerse el límite, o una corrida acotada se toparía siempre con los mismos y nunca
 *      avanzaría.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Idempotencia_y_corrida_cortada_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con tres artículos de las tres formas que importan, en este orden de id:
     *
     *  - a1: sin variantes; vive 10 y un fantasma de −4         → stock 6 → 10, UN movimiento de +4.
     *  - a2: SOLO fantasmas (stock global 6); un fantasma de −1 → se borra la fila, el stock no se toca, sin movimiento.
     *  - a3: con variantes; la variante vive 5, fantasma de variante −1 y del artículo +1 → se borran, el stock ya estaba bien.
     *
     * @param  string  $etiqueta
     * @return array
     */
    protected function dueno_con_tres_articulos($etiqueta)
    {
        $dueno = $this->dueno($etiqueta);
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $a1 = $this->articulo_con_fantasmas($dueno, 'Uno', [$s1->id => 10], [[$muerta, -4]])['articulo'];
        $a2 = $this->articulo_con_fantasmas($dueno, 'Dos', [], [[$muerta, -1]], 6)['articulo'];
        $a3 = $this->articulo_con_variantes($dueno, 'Tres', [['vivas' => [$s1->id => 5], 'fantasmas' => [[$muerta, -1]]]], [[$muerta, 1]])['articulo'];

        return compact('dueno', 's1', 'muerta', 'a1', 'a2', 'a3');
    }

    /**
     * El estado de un dueño sin ids ni nombres propios (para comparar dos dueños "gemelos"): stock,
     * montos del pivot y montos de los movimientos de cada artículo.
     *
     * @param  array  $e  Resultado de dueno_con_tres_articulos().
     * @return array
     */
    protected function estado_normalizado(array $e)
    {
        $estado = [];

        foreach (['a1', 'a2', 'a3'] as $clave) {
            $articulo = $e[$clave];

            $estado[$clave] = [
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
     * Cuántos de los tres artículos de un dueño conservan sus filas fantasma.
     *
     * @param  array  $e
     * @return int
     */
    protected function articulos_con_fantasmas(array $e)
    {
        $con_fantasmas = 0;

        foreach (['a1', 'a2'] as $clave) {
            if ($this->filas_en($e[$clave], $e['muerta']) > 0) {
                $con_fantasmas++;
            }
        }

        if ($this->filas_en($e['a3'], $e['muerta']) > 0 || DB::table('address_article_variant')->where('address_id', $e['muerta'])->count() > 0) {
            $con_fantasmas++;
        }

        return $con_fantasmas;
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function la_segunda_corrida_no_cambia_nada_ni_deja_movimientos_ni_archivos()
    {
        $e = $this->dueno_con_tres_articulos('idempotente');

        $this->assertSame(3, $this->articulos_con_fantasmas($e), 'El escenario no quedó armado: los tres artículos tenían que tener fantasmas.');

        $this->assertSame(0, $this->aplicar($e['dueno']), 'Primera corrida. Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->articulos_con_fantasmas($e), 'La primera corrida tenía que dejarlos todos limpios.');

        $despues_de_la_primera = $this->foto_de_tablas();
        $archivos_de_la_primera = array_merge($this->archivos_de($this->carpeta_de_salida, '-respaldo.jsonl'), $this->archivos_de($this->carpeta_de_salida, '-reversion.sql'));

        $this->assertCount(2, $archivos_de_la_primera, 'La primera corrida deja el respaldo y el SQL de reversión.');

        // El único movimiento: el de a1. a2 es solo fantasmas y a3 ya tenía el stock bien.
        $this->assertCount(1, $this->movimientos($e['a1']));
        $this->assertCount(0, $this->movimientos($e['a2']));
        $this->assertCount(0, $this->movimientos($e['a3']));

        // Segunda corrida: nada.
        $this->assertSame(0, $this->aplicar($e['dueno']), 'Segunda corrida. Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('No hay nada para sanear', $this->salida, 'La segunda corrida tiene que decir que no hay nada para sanear.');
        $this->assertFotosIguales($despues_de_la_primera, $this->foto_de_tablas(), 'La segunda corrida cambió algo (no es idempotente)');

        $archivos_de_la_segunda = array_merge($this->archivos_de($this->carpeta_de_salida, '-respaldo.jsonl'), $this->archivos_de($this->carpeta_de_salida, '-reversion.sql'));

        $this->assertSame($archivos_de_la_primera, $archivos_de_la_segunda, 'La segunda corrida dejó archivos de respaldo: sin nada para escribir no tiene que haber respaldo.');

        // Y un --ver posterior no ve ningún artículo.
        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('No hay artículos con filas fantasma', $this->salida, 'Después de sanear, --ver no tiene que encontrar artículos.');
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_corrida_cortada_por_limite_y_retomada_deja_lo_mismo_que_una_sola()
    {
        $entero = $this->dueno_con_tres_articulos('entero');
        $cortado = $this->dueno_con_tres_articulos('cortado');

        // Una sola corrida.
        $this->assertSame(0, $this->aplicar($entero['dueno'], ['--salida' => $this->carpeta_nueva()]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->articulos_con_fantasmas($entero));

        // La misma tarea en tres cortes de un artículo.
        $carpeta_cortada = $this->carpeta_nueva();

        $this->assertSame(0, $this->aplicar($cortado['dueno'], ['--limite' => 1, '--salida' => $carpeta_cortada]), 'Primer corte. Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Se cortó por --limite: quedan 2 artículos', $this->salida, 'La corrida cortada tiene que decir cuántos artículos quedan.');
        $this->assertSame(0, $this->filas_en($cortado['a1'], $cortado['muerta']), 'El primer corte tenía que sanear el primer artículo.');
        $this->assertSame(1, $this->filas_en($cortado['a2'], $cortado['muerta']), 'El primer corte no podía tocar el segundo artículo.');
        $this->assertSame(1, $this->filas_en($cortado['a3'], $cortado['muerta']), 'El primer corte no podía tocar el tercer artículo.');
        $this->assertSame(2, $this->articulos_con_fantasmas($cortado), 'Después del primer corte tienen que quedar dos artículos con fantasmas, todavía detectables.');

        $this->assertSame(0, $this->aplicar($cortado['dueno'], ['--limite' => 1, '--salida' => $carpeta_cortada]), 'Segundo corte. Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('quedan 1 artículos', $this->salida);
        $this->assertSame(1, $this->articulos_con_fantasmas($cortado));

        $this->assertSame(0, $this->aplicar($cortado['dueno'], ['--limite' => 1, '--salida' => $carpeta_cortada]), 'Tercer corte. Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->articulos_con_fantasmas($cortado), 'Con tres cortes de uno se sanean los tres.');

        // Cada corrida dejó SU propio respaldo: dos corridas del mismo segundo no se pisan entre sí.
        $this->assertCount(3, $this->archivos_de($carpeta_cortada, '-respaldo.jsonl'), 'Tres corridas que escriben tienen que dejar tres respaldos distintos (sin pisarse).');
        $this->assertCount(3, $this->archivos_de($carpeta_cortada, '-reversion.sql'));

        // El resultado es el mismo que el de una sola corrida.
        $this->assertEquals($this->estado_normalizado($entero), $this->estado_normalizado($cortado), 'Cortada y retomada tiene que dejar lo mismo que una sola corrida.');

        // Y retomarla otra vez no encuentra nada.
        $this->assertSame(0, $this->aplicar($cortado['dueno'], ['--salida' => $carpeta_cortada]));
        $this->assertStringContainsString('No hay nada para sanear', $this->salida);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function los_articulos_que_el_comando_no_toca_no_consumen_el_limite()
    {
        $dueno = $this->dueno('limite-no-recalculables');
        $s1 = $this->sucursal($dueno);
        $ajena = $this->sucursal($this->dueno('limite-ajena'));
        $muerta = $this->sucursal_muerta($dueno);

        // Dos que el comando NO toca (ids más bajos) y uno normal.
        $intocable_uno = $this->articulo_no_recalculable($dueno, $ajena, $muerta, 'Intocable uno');
        $intocable_dos = $this->articulo_no_recalculable($dueno, $ajena, $muerta, 'Intocable dos');
        $normal = $this->articulo_con_fantasmas($dueno, 'Normal', [$s1->id => 10], [[$muerta, -1]])['articulo'];

        $this->assertSame(0, $this->aplicar($dueno, ['--limite' => 1]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($normal, $muerta), 'Con --limite=1 el artículo normal se tenía que sanear: los dos que el comando no toca no pueden gastar el límite.');
        $this->assertEquals(10.0, $this->stock($normal));

        // Los intocables, intactos.
        $this->assertSame(1, DB::table('address_article_variant')->where('article_variant_id', $intocable_uno['variante']->id)->where('address_id', $muerta)->count());
        $this->assertSame(1, DB::table('address_article_variant')->where('article_variant_id', $intocable_dos['variante']->id)->where('address_id', $muerta)->count());
    }
}
