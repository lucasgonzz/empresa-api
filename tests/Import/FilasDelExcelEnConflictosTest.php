<?php

namespace Tests\Import;

use App\Models\ArticleImportResult;
use App\Models\ArticleImportResultObservation;
use App\Models\ImportConflict;

/**
 * Los números de fila que se reportan son los de la planilla del usuario (misión
 * fila-sobrescrita-corrida, 4/10/2026).
 *
 * El defecto, medido en demo2 con la lista de la demo (encabezado en la fila 7): el paso 3 del
 * asistente decía bien "Filas en el Excel: 9, 16" y "10, 14", pero el modal de resultado decía
 * "La fila 8 fue sobrescrita por la fila 15" y "La fila 9 fue sobrescrita por la fila 13".
 * ProcessRow reportaba fila de Excel − 1 (el "índice de fila de datos" que asertaban los tests
 * de esta carpeta, pensado para un encabezado en la fila 1) y además no contaba las filas vacías
 * que ArticleImport saltea. La columna `import_conflicts.fila` nació documentada como "Fila del
 * Excel, tal como la ve el usuario (1-based, incluyendo header)".
 *
 * Fixtures (ver fixtures/generar_filas_del_excel.php):
 *
 *   26_repetidos_con_encabezado_corrido.xlsx   membrete 1-5, encabezado en la 7, datos 8 a 17;
 *                                              FR-1002 en 9 y 16, FR-1003 en 10 y 14
 *   27_filas_vacias_entre_los_datos.xlsx       encabezado en la 3, datos 4 a 10 con la 6 y la 7
 *                                              vacías; EX-02 en 5 y 9, costo "consultar" en la 8
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class FilasDelExcelEnConflictosTest extends ImportTestCase
{
    /**
     * El caso de la demo, tal cual: las sobrescrituras dicen las filas del Excel, las mismas
     * que muestra el paso 3.
     *
     * @return void
     */
    public function test_con_encabezado_corrido_la_sobrescritura_dice_las_filas_del_excel()
    {
        $import = $this->importar('26_repetidos_con_encabezado_corrido.xlsx', [
            'provider_id' => $this->providers['A']->id,
            'start_row'   => 8,
        ]);

        $this->assertSame(
            [9 => 16, 10 => 14],
            $this->sobrescrituras($import),
            'FR-1002 (filas 9 y 16) y FR-1003 (filas 10 y 14): la fila pisada y la que gana tienen '
            . 'que ser las del Excel, no una menos.'
        );
    }

    /**
     * Las filas vacías del medio cuentan: un repetido que las cruza y un conflicto de otro tipo
     * que viene después quedan en su fila real.
     *
     * @return void
     */
    public function test_las_filas_vacias_no_corren_los_numeros_de_los_conflictos()
    {
        $import = $this->importar('27_filas_vacias_entre_los_datos.xlsx', [
            'provider_id' => $this->providers['A']->id,
            'start_row'   => 4,
        ]);

        $this->assertSame(
            [5 => 9],
            $this->sobrescrituras($import),
            'EX-02 está en las filas 5 y 9 del Excel, con las 6 y 7 vacías en el medio.'
        );

        $this->assertSame(
            [8],
            $this->filas_de_conflictos($import, 'numero_invalido'),
            'El costo "consultar" está en la fila 8 del Excel, después de las dos filas vacías.'
        );
    }

    /**
     * El detalle por fila de cada lote (lo que muestra el historial al abrir un lote) usa el
     * mismo número: solo las filas con datos, cada una con su fila del Excel.
     *
     * @return void
     */
    public function test_el_detalle_por_fila_del_lote_usa_la_fila_del_excel()
    {
        $import = $this->importar('27_filas_vacias_entre_los_datos.xlsx', [
            'provider_id' => $this->providers['A']->id,
            'start_row'   => 4,
        ]);

        $ids_de_lotes = ArticleImportResult::where('import_history_id', $import->id)->pluck('id');

        $filas = ArticleImportResultObservation::whereIn('article_import_result_id', $ids_de_lotes)
                    ->orderBy('fila')
                    ->pluck('fila')
                    ->map(function ($fila) {
                        return (int) $fila;
                    })
                    ->values()
                    ->all();

        $this->assertSame([4, 5, 8, 9, 10], $filas, 'Las filas vacías (6 y 7) no se procesan, pero tampoco corren a las demás.');
    }
}
