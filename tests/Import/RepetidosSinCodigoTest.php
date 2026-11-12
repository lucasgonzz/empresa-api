<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportHistory;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Regresión del ticket 107: los nombres sin código también respetan última fila gana. */
class RepetidosSinCodigoTest extends ImportTestCase
{
    /** @var array Archivos temporales a borrar al terminar cada test. */
    protected $temporales = [];

    /**
     * Borra los .xlsx temporales de cada test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        $this->temporales = [];

        parent::tearDown();
    }

    /**
     * Escribe un .xlsx temporal con la cabecera de ImportTestCase::columnas() y las filas dadas
     * (bar_code, sku, provider_code, nombre, costo, precio, stock, iva). Los textos van como
     * celdas de TEXTO, para que el código con espacios llegue tal cual; null queda vacía.
     *
     * @param  array $filas
     * @return string ruta absoluta
     */
    protected function xlsx(array $filas)
    {
        $spreadsheet = new Spreadsheet();
        $hoja = $spreadsheet->getActiveSheet();

        $hoja->fromArray([[
            'codigo_de_barras', 'sku', 'codigo_de_proveedor', 'nombre', 'costo', 'precio', 'stock', 'iva',
        ]], null, 'A1', true);

        // Letra de la planilla para cada posición de la fila.
        $columnas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($filas as $indice => $fila) {
            foreach ($fila as $posicion => $valor) {

                if (is_null($valor)) {
                    continue;
                }

                $referencia = $columnas[$posicion] . ($indice + 2);

                if (is_string($valor)) {
                    $hoja->setCellValueExplicit($referencia, $valor, DataType::TYPE_STRING);
                } else {
                    $hoja->setCellValue($referencia, $valor);
                }
            }
        }

        $ruta = sys_get_temp_dir() . '/' . uniqid('fila_sin_identificador_') . '.xlsx';

        (new Xlsx($spreadsheet))->save($ruta);

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /**
     * Importa un .xlsx por el endpoint real, en modo crear y actualizar, y devuelve su historial.
     *
     * @param  string $ruta
     * @param  array  $config Overrides de configuración y de columnas.
     * @return \App\Models\ImportHistory
     */
    protected function importar_xlsx($ruta, array $config = [])
    {
        /* ArticleController@import mueve el archivo con storeAs(): se importa sobre una copia. */
        $copia = sys_get_temp_dir() . '/' . uniqid('fila_sin_identificador_copia_') . '.xlsx';
        copy($ruta, $copia);
        $this->temporales[] = $copia;

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $copia,
                    basename($ruta),
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            self::config_por_defecto(),
            self::columnas(),
            $config
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }


    /** @dataProvider tamanos_de_lote */
    public function test_la_ultima_fila_por_nombre_persiste_el_precio($lote)
    {
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => $lote]);
        $import = $this->importar_xlsx($this->xlsx([
            [null, null, null, 'Llave francesa 10"', null, 313.22, null, null],
            [null, null, null, 'Llave francesa 10"', null, 344.54, null, null],
            [null, null, null, 'Disyuntor diferencial 2x40A', null, null, null, null],
            [null, null, null, 'Disyuntor diferencial 2x40A', null, 54883.47, null, null],
        ]), ['filas_repetidas_del_archivo' => 'ultima_gana', 'prop_stock_actual' => null]);

        $this->assertSame('terminado', $import->fresh()->status);
        $this->assertSame($lote === 1 ? 4 : 1, (int) $import->total_chunks);
        foreach (['Llave francesa 10"' => 344.54, 'Disyuntor diferencial 2x40A' => 54883.47] as $nombre => $precio) {
            $articulos = Article::where('user_id', $this->tenant->id)->where('name', $nombre)->get();
            $this->assertCount(1, $articulos, json_encode(Article::where('user_id', $this->tenant->id)->get(['name','price','bar_code'])->toArray()));
            $this->assertDecimal($precio, $articulos[0]->price);
            $this->assertDecimal($precio, $articulos[0]->final_price);
            $this->assertNull($articulos[0]->cost);
            $this->assertNull($articulos[0]->percentage_gain);
        }
        $this->assertSame([], $this->filas_de_conflictos($import, 'sin_identificador'));
        $this->assertSame([2 => 3, 4 => 5], $this->sobrescrituras($import));
        if ($lote > 1) {
            $this->assertBuckets($import, ['creado_nuevo' => 2, 'merge_fila_repetida' => 2]);
        }
    }

    public function tamanos_de_lote()
    {
        return ['mismo lote' => [1000], 'entre lotes' => [1]];
    }

    public function test_nombre_existente_no_pierde_la_ultima_fila_del_buffer()
    {
        Article::whereKey($this->seed['A1']->id)->update(['name' => 'Solo nombre existente', 'price' => 100]);
        $import = $this->importar_xlsx($this->xlsx([
            [null, null, null, 'Solo nombre existente', null, 250, null, null],
            [null, null, null, 'Solo nombre existente', null, 350, null, null],
            [null, null, null, 'Solo nombre existente', null, 450, null, null],
        ]), ['filas_repetidas_del_archivo' => 'ultima_gana']);
        $this->assertDecimal(450, $this->recargar('A1')->price);
        $this->assertSame([2 => 3, 3 => 4], $this->sobrescrituras($import));
    }
    public function test_nombre_ambiguo_entre_fakes_con_codigos_no_actualiza_arbitrariamente()
    {
        $import = $this->importar_xlsx($this->xlsx([
            ['779107001', null, 'PC-107-A', 'Martillo sin desempate', null, 100, null, null],
            ['779107002', null, 'PC-107-B', 'Martillo sin desempate', null, 200, null, null],
            [null, null, null, 'Martillo sin desempate', null, 300, null, null],
        ]));
        foreach (['779107001' => 100, '779107002' => 200] as $codigo => $precio) {
            $articulo = Article::where('user_id', $this->tenant->id)->where('bar_code', (string) $codigo)->first();
            $this->assertNotNull($articulo, json_encode(Article::where('user_id', $this->tenant->id)->get(['name','price','bar_code'])->toArray()));
            $this->assertDecimal($precio, $articulo->price);
        }
        $this->assertSame([4], $this->filas_de_conflictos($import, 'ambiguo'));
        $this->assertSame([], $this->sobrescrituras($import));
    }

    public function test_planilla_de_36_filas_del_ticket_107_con_notas_y_stock_excluidos()
    {
        $filas = json_decode(file_get_contents(__DIR__ . '/fixtures/soporte_107_nombres_precios.json'), true);
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([['Nombre', 'Precio final', 'Notas excluidas', 'Stock excluido']], null, 'A3');
        foreach ($filas as $indice => $fila) {
            $hoja->fromArray([[$fila[0], $fila[1], 'No cargar esta nota', 999]], null, 'A' . ($indice + 4));
        }
        $ruta = sys_get_temp_dir() . '/' . uniqid('soporte_107_') . '.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->temporales[] = $ruta;
        $antes = Article::where('user_id', $this->tenant->id)->count();
        $import = $this->importar_xlsx($ruta, [
            'start_row' => 4, 'prop_nombre' => 1, 'prop_precio' => 2,
            'prop_codigo_de_barras' => -1, 'prop_sku' => -1, 'prop_codigo_de_proveedor' => -1,
            'prop_costo' => -1, 'prop_stock_actual' => -1, 'prop_iva' => -1,
            'filas_repetidas_del_archivo' => 'ultima_gana',
        ]);
        $this->assertSame(34, Article::where('user_id', $this->tenant->id)->count() - $antes);
        $this->assertSame(34, (int) $import->fresh()->created_models);
        $this->assertSame([], $this->filas_de_conflictos($import, 'sin_identificador'));
        $esperados = [];
        foreach ($filas as $fila) {
            $esperados[$fila[0]] = $fila[1];
        }
        $sin_precio = 0;
        foreach ($esperados as $nombre => $precio) {
            $articulo = Article::where('user_id', $this->tenant->id)->where('name', $nombre)->firstOrFail();
            if (is_null($precio)) {
                $this->assertNull($articulo->price);
                $sin_precio++;
            } else {
                $numero = \App\Http\Controllers\Helpers\import\article\ProcessRow::get_number($precio);
                $this->assertDecimal($numero, $articulo->price);
                $this->assertDecimal($numero, $articulo->final_price);
            }
            $this->assertNull($articulo->cost);
            $this->assertNull($articulo->percentage_gain);
            $this->assertNull($articulo->stock);
            $this->assertTrue(is_null($articulo->descripcion) || $articulo->descripcion === '');
        }
        $this->assertSame(4, $sin_precio);
    }

    public function test_reimportar_por_nombre_con_pulgadas_encuentra_el_articulo_en_indice_acotado()
    {
        Article::whereKey($this->seed['A9']->id)->update(['name' => 'Llave existente 12"', 'price' => 100]);
        $antes = Article::where('user_id', $this->tenant->id)->count();
        $import = $this->importar_xlsx($this->xlsx([
            [null, null, null, 'Llave existente 12"', null, 250, null, null],
        ]));
        $this->assertSame($antes, Article::where('user_id', $this->tenant->id)->count());
        $this->assertSame('Llave existente 12"', $this->recargar('A9')->name);
        $this->assertDecimal(250, $this->recargar('A9')->price);
        $this->assertBuckets($import, ['name' => 1]);
    }

}
