<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use Illuminate\Support\Facades\DB;

/**
 * ORDEN DE BLOQUEO del saneo de un artículo (misión sanear-stock-de-sucursales-borradas,
 * 6/10/2026).
 *
 * El saneo corre con el sistema en vivo: las ventas entran mientras tanto. Si toma los candados de
 * las filas en un orden distinto del que usa el motor, un movimiento y el saneo pueden esperarse
 * mutuamente (deadlock) y alguien pierde la venta. El motor toca, en este orden:
 *
 *     address_article_variant → article_variants → address_article → articles
 *
 * La concurrencia real no se puede probar con tráfico en un test. Lo que SÍ se puede probar, y es
 * lo que hace falta para que la propiedad no se pierda por un refactor, son sus dos condiciones
 * observables:
 *
 *   1. las lecturas con candado (`SELECT ... FOR UPDATE`) salen en ese orden, una por tabla;
 *   2. ninguna lectura SIN candado se cuela entre ellas: en REPEATABLE READ el snapshot nace en la
 *      primera lectura sin candado, y las lecturas que vienen después (la foto de depósitos, la
 *      función del sistema) tienen que ver lo que los candados protegen. La única lectura sin
 *      candado que precede es la de los ids de variante, que va FUERA de la transacción a
 *      propósito.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Orden_de_bloqueo_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Corre el saneo de un artículo y devuelve lo que preguntó a la base, en orden.
     *
     * @param  int  $article_id
     * @return array  Lista de SQL en minúsculas.
     */
    protected function consultas_del_saneo($article_id)
    {
        $concepto_id = (int) DB::table('concepto_stock_movements')->where('name', self::CONCEPTO)->value('id');

        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = strtolower($consulta->sql);
        });

        $resultado = FilasFantasmaDeSucursalHelper::sanear_articulo($article_id, $concepto_id);

        $this->assertSame(FilasFantasmaDeSucursalHelper::RESULTADO_SANEADO, $resultado['resultado'], 'El escenario no sanó el artículo.');

        return $consultas;
    }

    /**
     * De una lista de SQL, las tablas de los SELECT con candado en orden de aparición, y la posición
     * de cada uno.
     *
     * @param  array  $consultas
     * @return array  ['tablas' => string[], 'posiciones' => int[]]
     */
    protected function candados_en(array $consultas)
    {
        $tablas = [];
        $posiciones = [];

        foreach ($consultas as $posicion => $sql) {
            if (strpos($sql, 'select') !== 0 || strpos($sql, 'for update') === false) {
                continue;
            }

            preg_match('/ from `([a-z_]+)`/', $sql, $coincidencia);

            $tablas[] = $coincidencia[1];
            $posiciones[] = $posicion;
        }

        return ['tablas' => $tablas, 'posiciones' => $posiciones];
    }

    /**
     * Afirma que ninguna lectura sin candado cae entre el primer candado y el último, y que antes
     * del primero solo hay la lectura de los ids de variante.
     *
     * @param  array  $consultas
     * @param  array  $posiciones  Posiciones de los SELECT con candado.
     * @return void
     */
    protected function assertNingunaLecturaSinCandadoEntreLosCandados(array $consultas, array $posiciones)
    {
        $primero = min($posiciones);
        $ultimo = max($posiciones);

        for ($i = $primero; $i <= $ultimo; $i++) {
            if (strpos($consultas[$i], 'select') === 0) {
                $this->assertContains($i, $posiciones, 'Una lectura SIN candado se coló entre los candados del saneo (rompe el snapshot): ' . $consultas[$i]);
            }
        }

        // Antes del primer candado solo puede ir la lectura de los ids de variante (afuera de la transacción).
        for ($i = 0; $i < $primero; $i++) {
            $this->assertStringContainsString('from `article_variants`', $consultas[$i], 'Antes del primer candado solo puede ir la lectura de los ids de variante: ' . $consultas[$i]);
        }
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_variantes_toma_los_cuatro_candados_en_el_orden_del_motor()
    {
        $dueno = $this->dueno('bloqueo-variantes');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_variantes(
            $dueno,
            'Bloqueo con variantes',
            [['vivas' => [$s1->id => 6], 'fantasmas' => [[$muerta, -1]]]],
            [[$muerta, 2]]
        );

        $consultas = $this->consultas_del_saneo($e['articulo']->id);

        $candados = $this->candados_en($consultas);

        $this->assertSame(
            ['address_article_variant', 'article_variants', 'address_article', 'articles'],
            $candados['tablas'],
            'Las lecturas con candado tienen que ir en el orden del motor: pivots de variante, variantes, pivot del artículo, artículos.'
        );

        $this->assertNingunaLecturaSinCandadoEntreLosCandados($consultas, $candados['posiciones']);
    }

    /**
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_sin_variantes_toma_sus_candados_en_el_mismo_orden()
    {
        $dueno = $this->dueno('bloqueo-simple');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $articulo = $this->articulo_con_fantasmas($dueno, 'Bloqueo simple', [$s1->id => 10], [[$muerta, -1]])['articulo'];

        $consultas = $this->consultas_del_saneo($articulo->id);

        $candados = $this->candados_en($consultas);

        $this->assertSame(
            ['article_variants', 'address_article', 'articles'],
            $candados['tablas'],
            'Sin variantes no hay pivot de variante que bloquear; el resto va en el mismo orden.'
        );

        $this->assertNingunaLecturaSinCandadoEntreLosCandados($consultas, $candados['posiciones']);
    }
}
