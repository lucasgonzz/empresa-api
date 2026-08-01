<?php

namespace Tests\Import;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Concepto de stock faltante.
 *
 * Congela el comportamiento arreglado en el prompt 261/01: una importación de Excel
 * no puede caerse con 500 porque falte el concepto de movimiento de stock. Es
 * exactamente el bug que tumbó los 41 tests de la suite `Import` el 28/7/2026.
 *
 * Correctivo del prompt 261/02: la versión anterior restauraba la tabla
 * `concepto_stock_movements` con `ConceptoStockMovement::updateOrCreate()->fill()`,
 * pero el modelo no define `$fillable` ni `$guarded` (hereda `$guarded = ['*']`), así
 * que cualquier asignación masiva tira `MassAssignmentException`. Acá el borrado y la
 * restauración de la tabla van SIEMPRE por `DB::table()` (query builder), que no pasa
 * por asignación masiva.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class ConceptoFaltanteTest extends ImportTestCase
{
    /** Nombre de la tabla global (sin user_id) de conceptos de movimiento de stock. */
    const TABLA = 'concepto_stock_movements';

    /**
     * Corre $callback con la tabla de conceptos de movimiento vacía y después la deja
     * exactamente como estaba (mismos ids, mismos nombres, mismas fechas).
     *
     * Se usa el query builder a propósito: ConceptoStockMovement NO define $fillable, así
     * que hereda $guarded = ['*'] y cualquier create()/fill()/updateOrCreate() tira
     * MassAssignmentException. DB::table() no pasa por la asignación masiva.
     *
     * @param  \Closure $callback
     * @return void
     */
    protected function sin_conceptos_de_stock(\Closure $callback)
    {
        $filas = DB::table(self::TABLA)->get();

        $respaldo = [];

        foreach ($filas as $fila) {
            $respaldo[] = (array) $fila;
        }

        try {

            DB::table(self::TABLA)->delete();

            $callback();

        } finally {

            /* Se vacía de nuevo antes de reinsertar por si el código bajo prueba creó alguna. */
            DB::table(self::TABLA)->delete();

            if (count($respaldo) > 0) {
                DB::table(self::TABLA)->insert($respaldo);
            }
        }
    }

    /**
     * Con la tabla de conceptos vacía, la importación de 04_stock.xlsx no puede
     * explotar con 500: tiene que devolver 200 y actualizar el stock de A1 igual.
     *
     * @return void
     */
    public function test_importacion_no_explota_sin_concepto_de_stock()
    {
        /* Cantidad de filas antes de vaciar la tabla, para comprobar al final que la
           restauración dejó la tabla exactamente como estaba. */
        $cantidad_antes = DB::table(self::TABLA)->count();

        $this->sin_conceptos_de_stock(function () {

            $this->importar('04_stock.xlsx', ['provider_id' => null]);

            /* La importación no solo no se cayó (el assertStatus(200) ya vive dentro
               de importar()), sino que además hizo su trabajo: A1 sube de 10 a 25. */
            $this->assertDecimal(25, $this->recargar('A1')->stock, 'A1 sube de 10 a 25 aunque falte el concepto');

        });

        /* La restauración por finally tiene que dejar la tabla con la misma cantidad
           de filas que tenía antes de este test: es la aserción que hace visible el
           fallo que originó este correctivo en vez de dejarlo pasar callado. */
        $this->assertSame(
            $cantidad_antes,
            DB::table(self::TABLA)->count(),
            'concepto_stock_movements tiene que quedar con la misma cantidad de filas de antes'
        );
    }

    /**
     * El movimiento de stock generado para A1 sin concepto disponible tiene que
     * quedar con concepto_stock_movement_id en null, no con un id inventado.
     *
     * @return void
     */
    public function test_movimiento_sin_concepto_queda_en_null_y_no_inventa_uno()
    {
        $this->sin_conceptos_de_stock(function () {

            $this->importar('04_stock.xlsx', ['provider_id' => null]);

            $a1 = $this->recargar('A1');

            $movimiento = StockMovement::where('article_id', $a1->id)
                                        ->orderBy('id', 'DESC')
                                        ->first();

            $this->assertNotNull($movimiento, 'Tiene que haber movimiento para A1');

            $this->assertNull(
                $movimiento->concepto_stock_movement_id,
                'Sin concepto disponible el movimiento no puede inventar un id'
            );

        });
    }
}
