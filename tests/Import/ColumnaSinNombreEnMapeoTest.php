<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use Tests\TestCase;

/**
 * Misión importacion-fila-sin-identificador (10/11/2026): a qué columna del Excel apunta un
 * ítem del mapeo cuya columna no tiene título.
 *
 * El ítem sin título usaba su posición en la respuesta de la IA como índice de columna. La IA
 * no devolvió la columna A de la planilla de Mayorista de pesca, así que todo quedó corrido en
 * uno: la columna D, sin título y con los costos, salió con la letra C, la misma del nombre.
 *
 * Los tres analizadores comparten la regla. No extiende ImportTestCase porque no toca la base.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ColumnaSinNombreEnMapeoTest extends TestCase
{
    /**
     * Los tres analizadores que arman el mapeo de columnas.
     *
     * @return array
     */
    public function analizadores()
    {
        return [
            'articulos'   => [AiExcelAnalyzer::class],
            'clientes'    => [AiClientAnalyzer::class],
            'proveedores' => [AiProviderAnalyzer::class],
        ];
    }

    /**
     * Encabezados medidos en la planilla real de Mayorista de pesca.
     *
     * @return array
     */
    protected function encabezados_de_pesca()
    {
        return [
            '', 'CODIGO', 'Anzuelos serie 92611 importado Surfish', '', 'TIPO DE CAMBIO',
            'VALOR DEL DOLAR', 'MARCA', 'CATEGORIA', 'SUBCATEGORIA', 'STOCK',
        ];
    }

    /**
     * Arma ítems de mapeo con solo el nombre de columna, que es lo que decide el índice.
     *
     * @param  array $nombres Nombre de cada ítem, en el orden que los devolvió la IA.
     * @return array
     */
    protected function items(array $nombres)
    {
        $items = [];

        foreach ($nombres as $nombre) {
            $items[] = ['excel_column' => $nombre, 'system_property' => null, 'confidence' => 0];
        }

        return $items;
    }

    /**
     * Llama a enrich_column_mapping(), que es protected a propósito, y devuelve el resultado.
     *
     * @param  string $clase
     * @param  array  $mapeo
     * @param  array  $encabezados
     * @return array
     */
    protected function enriquecer($clase, array $mapeo, array $encabezados)
    {
        $analizador = new $clase(1);

        $metodo = new \ReflectionMethod($analizador, 'enrich_column_mapping');
        $metodo->setAccessible(true);

        return $metodo->invokeArgs($analizador, [$mapeo, $encabezados]);
    }

    /**
     * Índices del mapeo enriquecido, en orden.
     *
     * @param  array $enriquecido
     * @return array
     */
    protected function indices(array $enriquecido)
    {
        $indices = [];

        foreach ($enriquecido as $item) {
            $indices[] = $item['excel_column_index'];
        }

        return $indices;
    }

    /**
     * El caso de Pesca: la IA no devuelve la columna A y la columna sin título queda en D.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_la_columna_sin_titulo_de_pesca_queda_en_la_letra_d($clase)
    {
        $enriquecido = $this->enriquecer($clase, $this->items([
            'CODIGO', 'Anzuelos serie 92611 importado Surfish', '', 'TIPO DE CAMBIO',
            'VALOR DEL DOLAR', 'MARCA', 'CATEGORIA', 'SUBCATEGORIA', 'STOCK',
        ]), $this->encabezados_de_pesca());

        $this->assertSame(3, $enriquecido[2]['excel_column_index'], 'La columna sin título tiene que ser la D.');
        $this->assertSame('D', $enriquecido[2]['excel_column_letter']);

        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9],
            $this->indices($enriquecido),
            'Las columnas con título no se pueden mover.'
        );
    }

    /**
     * Un ítem con excel_column null o ausente es una columna sin título igual que uno vacío.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_un_nombre_null_o_ausente_tambien_es_una_columna_sin_titulo($clase)
    {
        $mapeo = $this->items(['CODIGO', 'Anzuelos serie 92611 importado Surfish', null]);
        unset($mapeo[2]['excel_column']);

        $enriquecido = $this->enriquecer($clase, $mapeo, $this->encabezados_de_pesca());

        $this->assertSame(3, $enriquecido[2]['excel_column_index']);
    }

    /**
     * Si la IA devuelve las dos columnas sin título, cada una se lleva la suya, en orden.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_dos_columnas_sin_titulo_no_comparten_indice($clase)
    {
        $enriquecido = $this->enriquecer($clase, $this->items([
            '', 'CODIGO', 'Anzuelos serie 92611 importado Surfish', '', 'TIPO DE CAMBIO',
        ]), $this->encabezados_de_pesca());

        $this->assertSame([0, 1, 2, 3, 4], $this->indices($enriquecido));
    }

    /**
     * Sin una columna vacía libre después del ítem anterior, se toma la primera vacía libre.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_sin_vacia_posterior_toma_la_primera_vacia_libre($clase)
    {
        $enriquecido = $this->enriquecer($clase, $this->items([
            'CODIGO', 'STOCK', '', '',
        ]), $this->encabezados_de_pesca());

        $this->assertSame([1, 9, 0, 3], $this->indices($enriquecido));
    }

    /**
     * Sin ninguna columna vacía en el archivo se conserva el comportamiento de siempre.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_sin_columnas_vacias_conserva_la_posicion($clase)
    {
        $enriquecido = $this->enriquecer($clase, $this->items([
            'CODIGO', '', 'MARCA',
        ]), ['CODIGO', 'NOMBRE', 'MARCA']);

        $this->assertSame([0, 1, 2], $this->indices($enriquecido));
    }

    /**
     * Un nombre que no coincide con ningún encabezado no consume columnas vacías.
     *
     * @dataProvider analizadores
     * @param  string $clase
     * @return void
     */
    public function test_un_nombre_que_no_coincide_conserva_la_posicion($clase)
    {
        $enriquecido = $this->enriquecer($clase, $this->items([
            'CODIGO', 'COLUMNA INVENTADA', '',
        ]), $this->encabezados_de_pesca());

        $this->assertSame([1, 1, 3], $this->indices($enriquecido));
    }
}
