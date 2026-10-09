<?php

namespace Tests\Import;

use App\Models\ImportHistory;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Misión importacion-saldo-celdas-de-texto (9/10/2026) — GUARDA de artículos: una celda que vale −1
 * es un dato, no una "columna sin usar".
 *
 * `ImportHelper::getColumnValue()` comparaba el VALOR de la celda con el centinela −1 (`!== -1`), y
 * la misión sacó esa comparación para todas las importaciones (decisión de Lucas). Pero esta clase
 * NO es la prueba de ese arreglo: los artículos leen del CSV intermedio (`fgetcsv`), donde la celda
 * llega como el string "-1", y el `!== -1` (entero) nunca la descartaba. Estos tests pasan con y sin
 * el arreglo. La prueba es `SaldoCeldasDeTextoImportTest::una_celda_numerica_menos_uno_en_el_telefono_se_importa_como_menos_uno`
 * (clientes y proveedores leen por Maatwebsite, que entrega `int`). El test de saldo −1 de esa
 * clase no lo prueba: el saldo se lee de la celda cruda y no pasa por getColumnValue().
 *
 * Lo que esta guarda cuida: que por el camino real de la importación de artículos una celda
 * numérica −1 en el stock se siga tratando igual que una −2 en la misma columna, si algún día el
 * camino deja de pasar por el CSV o vuelve un centinela sobre el valor.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class CeldaMenosUnoTest extends ImportTestCase
{
    /** @var array Archivos temporales a borrar al terminar cada test. */
    protected $temporales = [];

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
     * (bar_code, sku, provider_code, nombre, costo, precio, stock, iva). Los números quedan como
     * celdas NUMÉRICAS y los textos como celdas de TEXTO; null queda vacía. Relee el archivo y
     * verifica que el stock quedó numérico.
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

        $columnas = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($filas as $indice => $fila) {
            foreach ($fila as $posicion => $valor) {

                if (is_null($valor)) {
                    continue;
                }

                $referencia = $columnas[$posicion].($indice + 2);

                if (is_string($valor)) {
                    $hoja->setCellValueExplicit($referencia, $valor, DataType::TYPE_STRING);
                } else {
                    $hoja->setCellValue($referencia, $valor);
                }
            }
        }

        $ruta = sys_get_temp_dir().'/'.uniqid('menos_uno_').'.xlsx';

        (new Xlsx($spreadsheet))->save($ruta);

        $this->temporales[] = $ruta;

        // Premisa: el stock de cada fila es una celda NUMÉRICA.
        $releida = IOFactory::load($ruta)->getActiveSheet();

        foreach ($filas as $indice => $fila) {
            $this->assertSame(DataType::TYPE_NUMERIC, $releida->getCell('G'.($indice + 2))->getDataType(), 'Premisa: el stock de la fila '.($indice + 2).' tiene que ser numérico.');
        }

        return $ruta;
    }

    /**
     * Importa un .xlsx por el endpoint real (mismo camino que ImportTestCase::importar(), pero para
     * un archivo fuera de fixtures/) y devuelve el ImportHistory.
     *
     * @param  string $ruta
     * @return \App\Models\ImportHistory
     */
    protected function importar_xlsx($ruta)
    {
        /* ArticleController@import mueve el archivo con storeAs(): se importa sobre una copia. */
        $copia = sys_get_temp_dir().'/'.uniqid('menos_uno_copia_').'.xlsx';
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
            self::columnas()
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import = ImportHistory::where('user_id', $this->tenant->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import, 'La importación no dejó ImportHistory.');

        $this->assertInvariantesDeConteo($import);

        return $import;
    }

    /**
     * Dos artículos nuevos, uno con stock −1 y otro con stock −2 (celdas numéricas): los dos
     * quedan con el stock del Excel. Si el −1 se descartara como "columna sin usar", el primero
     * quedaría sin stock y el segundo con −2.
     *
     * @return void
     */
    public function test_un_stock_de_menos_uno_se_importa_igual_que_uno_de_menos_dos()
    {
        $this->importar_xlsx($this->xlsx([
            [null, null, 'PC-MENOS-UNO', 'Articulo con stock menos uno', 100, 200, -1, '21'],
            [null, null, 'PC-MENOS-DOS', 'Articulo con stock menos dos', 100, 200, -2, '21'],
        ]));

        $menos_uno = $this->articulos_creados()->firstWhere('provider_code', 'PC-MENOS-UNO');
        $menos_dos = $this->articulos_creados()->firstWhere('provider_code', 'PC-MENOS-DOS');

        $this->assertNotNull($menos_uno, 'No se creó el artículo con stock −1.');
        $this->assertNotNull($menos_dos, 'No se creó el artículo con stock −2.');

        $this->assertDecimal(-2, $menos_dos->stock, 'Referencia: el −2 se importa.');
        $this->assertDecimal(-1, $menos_uno->stock, 'Un stock de −1 tiene que importarse igual que uno de −2.');
    }

    /**
     * Un artículo existente (A1, stock 10) al que el Excel le pone −1: el stock pasa a −1, igual
     * que A2 (stock 20) pasa a −2.
     *
     * @return void
     */
    public function test_un_articulo_existente_pasa_a_stock_menos_uno_igual_que_a_menos_dos()
    {
        $a1 = $this->recargar('A1');
        $a2 = $this->recargar('A2');

        $this->importar_xlsx($this->xlsx([
            [null, null, $a1->provider_code, $a1->name, (float) $a1->cost, null, -1, '21'],
            [null, null, $a2->provider_code, $a2->name, (float) $a2->cost, null, -2, '21'],
        ]));

        $this->assertDecimal(-2, $this->recargar('A2')->stock, 'Referencia: A2 pasa a −2.');
        $this->assertDecimal(-1, $this->recargar('A1')->stock, 'A1 tiene que pasar a −1 igual que A2 pasa a −2.');
    }
}
