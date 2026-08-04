<?php

namespace Tests\Import;

use App\Models\Article;

/**
 * Interpretación de números del Excel (costos, precios, stock).
 *
 * Todas las filas de 03_numeros_y_costos.xlsx usan provider_codes NUM-xx que no
 * existen en el escenario, así que cada una crea un artículo nuevo y el costo
 * resultante se lee directo de ese artículo.
 *
 * REGLA QUE FIJA ESTE TEST (ImportHelper::parseNumericValue):
 *   - Si hay coma Y punto, manda el que esté más a la derecha como decimal.
 *   - Si hay solo coma, la coma es el decimal.
 *   - Si hay solo punto y TODO el valor son grupos de exactamente 3 dígitos
 *     ("2.500", "1.234.567"), el punto es separador de MILES.
 *   - En cualquier otro caso el punto es DECIMAL ("3330.95", "2.5").
 *
 * El caso "2.500" -> 2500 es el que más sorprende a los usuarios y es el que
 * justifica la alerta previa a la importación.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class NumerosYCostosTest extends ImportTestCase
{
    const ARCHIVO = '03_numeros_y_costos.xlsx';

    /**
     * @dataProvider casos_de_costo
     *
     * @param  string     $provider_code
     * @param  float|null $costo_esperado
     * @param  string     $descripcion
     * @return void
     */
    public function test_costo_interpretado($provider_code, $costo_esperado, $descripcion)
    {
        $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', $provider_code)
                            ->first();

        $this->assertNotNull($articulo, 'No se creó el artículo ' . $provider_code);

        $this->assertDecimal($costo_esperado, $articulo->cost, $descripcion);
    }

    /**
     * @return array
     */
    public function casos_de_costo()
    {
        return [
            'formato argentino'          => ['NUM-01', 1234.56,      '"1.234,56" -> 1234,56'],
            'formato norteamericano'     => ['NUM-02', 12345.67,     '"12,345.67" -> 12345,67'],
            'con simbolo de moneda'      => ['NUM-03', 37468.24,     '"$ 37.468,24" -> 37468,24'],
            'punto como decimal'         => ['NUM-04', 3330.95,      '"3330.95" -> 3330,95 (no son grupos de 3)'],
            'punto como miles'           => ['NUM-05', 2500.0,       '"2.500" -> 2500 (grupos exactos de 3)'],
            'muchos decimales'           => ['NUM-06', 1234.567891,  '"1234,5678912" -> se redondea a 6 decimales'],
            'celda numerica real'        => ['NUM-07', 8888.25,      'float 8888.25 pasa derecho'],
            'texto no numerico'          => ['NUM-08', null,         '"consultar" no pisa el costo'],
            'punto decimal corto'        => ['NUM-09', 2.5,          '"2.5" -> 2,5'],
            'miles multiples'            => ['NUM-10', 1234567.89,   '"1.234.567,89" -> 1234567,89'],
            'fuera de rango'             => ['NUM-11', null,         '46 dígitos no entran en decimal(50,6)'],
            'cero'                       => ['NUM-12', 0.0,          '"0" -> 0'],
        ];
    }

    /**
     * Un valor no numérico no puede pisar el costo con basura ni romper la
     * importación: se salta el campo y se reporta.
     *
     * @return void
     */
    public function test_valores_no_numericos_se_reportan_como_conflicto()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $this->assertSame(1, $this->conflictos($import, 'numero_invalido'), 'NUM-08');
        $this->assertSame(1, $this->conflictos($import, 'numero_fuera_de_rango'), 'NUM-11');
    }

    /**
     * Ninguna fila con problema numérico puede perderse: las 12 filas se procesan
     * y se crean los 12 artículos, con o sin costo.
     *
     * @return void
     */
    public function test_todas_las_filas_crean_articulo()
    {
        $import = $this->importar(self::ARCHIVO, ['provider_id' => $this->providers['A']->id]);

        $this->assertBuckets($import, ['creado_nuevo' => 12]);
        $this->assertCount(12, $this->articulos_creados());
    }
}
