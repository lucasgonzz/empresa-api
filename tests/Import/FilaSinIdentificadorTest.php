<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\ImportHistory;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Misión importacion-fila-sin-identificador (10/11/2026): la fila que tiró abajo la importación
 * de Mayorista de pesca.
 *
 * La planilla tiene títulos de sección intercalados entre los artículos: el código viene con
 * espacios y el resto de la fila vacío. Esa celda de espacios pasa checkRow() (getColumnValue()
 * devuelve '' y no null) y IdentifierNormalizer la deja sin código. Con esa fila y sin una
 * columna de nombre mapeada, ya_estaba_en_el_excel() leía $data['name'] sin defenderse y una
 * sola fila tiraba el lote entero: «Undefined array key "name"».
 *
 * Los tests fijan también lo que había detrás de esa caída: arreglando solo la lectura, la fila
 * seguía de largo y encolaba un artículo sin nombre y sin código.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class FilaSinIdentificadorTest extends ImportTestCase
{
    /** Código con espacios que trae la fila de título de sección en la planilla real. */
    const CODIGO_CON_ESPACIOS = '  ';

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

    /**
     * Cantidad de artículos del tenant que no tienen nombre ni ningún código.
     *
     * @return int
     */
    protected function articulos_sin_nombre_ni_codigo()
    {
        return Article::where('user_id', $this->tenant->id)
                        ->where(function ($query) {
                            $query->whereNull('name')->orWhere('name', '');
                        })
                        ->where(function ($query) {
                            $query->whereNull('provider_code')->orWhere('provider_code', '');
                        })
                        ->where(function ($query) {
                            $query->whereNull('bar_code')->orWhere('bar_code', '');
                        })
                        ->where(function ($query) {
                            $query->whereNull('sku')->orWhere('sku', '');
                        })
                        ->count();
    }

    /**
     * El caso de Pesca: mapeo sin columna de nombre y una fila de título con el código en
     * espacios. El lote termina, las filas válidas se procesan, la de título no crea nada y
     * queda reportada.
     *
     * @return void
     */
    public function test_una_fila_sin_codigo_y_sin_columna_de_nombre_no_tira_el_lote()
    {
        $sin_nombre_antes = $this->articulos_sin_nombre_ni_codigo();

        $import = $this->importar_xlsx($this->xlsx([
            [null, null, 'PESCA-VALIDO-1', 'Nombre que no se mapea 1', 100, null, null, null],
            [null, null, self::CODIGO_CON_ESPACIOS, 'Titulo de seccion', null, null, null, null],
            [null, null, 'PESCA-VALIDO-2', 'Nombre que no se mapea 2', 200, null, null, null],
        ]), [
            'prop_nombre' => -1,
            'prop_precio' => -1,
            'prop_stock_actual' => -1,
            'prop_iva' => -1,
        ]);

        $this->assertSame('terminado', (string) $import->fresh()->status, 'Una sola fila no puede tirar el lote.');

        $this->assertBuckets($import, [
            'creado_nuevo'        => 2,
            'sin_match_no_creado' => 1,
        ]);

        $this->assertSame([3], $this->filas_de_conflictos($import, 'sin_identificador'));

        $this->assertTrue(
            Article::where('user_id', $this->tenant->id)->where('provider_code', 'PESCA-VALIDO-1')->exists(),
            'La fila válida anterior a la de título se tiene que procesar.'
        );

        $this->assertTrue(
            Article::where('user_id', $this->tenant->id)->where('provider_code', 'PESCA-VALIDO-2')->exists(),
            'La fila válida posterior a la de título se tiene que procesar.'
        );

        $this->assertSame(
            $sin_nombre_antes,
            $this->articulos_sin_nombre_ni_codigo(),
            'No se puede crear un artículo sin nombre y sin código.'
        );
    }

    /**
     * Con la columna de nombre mapeada pero vacía en esa fila, antes no se caía y creaba un
     * artículo vacío: es el mismo defecto por el otro camino.
     *
     * @return void
     */
    public function test_una_fila_sin_codigo_y_con_el_nombre_vacio_no_crea_un_articulo()
    {
        $sin_nombre_antes = $this->articulos_sin_nombre_ni_codigo();

        $import = $this->importar_xlsx($this->xlsx([
            [null, null, self::CODIGO_CON_ESPACIOS, null, 77, null, null, null],
        ]));

        $this->assertSame('terminado', (string) $import->fresh()->status);

        $this->assertBuckets($import, ['sin_match_no_creado' => 1]);

        $this->assertSame([2], $this->filas_de_conflictos($import, 'sin_identificador'));

        $this->assertSame(
            $sin_nombre_antes,
            $this->articulos_sin_nombre_ni_codigo(),
            'No se puede crear un artículo sin nombre y sin código.'
        );
    }

    /**
     * Guarda del comportamiento que no cambia: una fila con nombre y sin código se sigue creando
     * y se sigue reportando como fila sin identificador.
     *
     * @return void
     */
    public function test_una_fila_con_nombre_y_sin_codigo_se_sigue_creando()
    {
        $import = $this->importar_xlsx($this->xlsx([
            [null, null, self::CODIGO_CON_ESPACIOS, 'Articulo solo con nombre pesca', 50, null, null, null],
        ]));

        $this->assertSame('terminado', (string) $import->fresh()->status);

        $this->assertBuckets($import, ['creado_nuevo' => 1]);

        $this->assertSame([2], $this->filas_de_conflictos($import, 'sin_identificador'));

        $this->assertTrue(
            Article::where('user_id', $this->tenant->id)->where('name', 'Articulo solo con nombre pesca')->exists(),
            'Una fila con nombre se tiene que seguir creando aunque no traiga código.'
        );
    }
}
