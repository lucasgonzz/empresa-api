<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de si ESTE movimiento dio de alta el producto terminado en stock
 * (mision produccion-v2-multinivel, 26/8/2026).
 *
 * ── Que significa cada valor ─────────────────────────────────────────────────────────────────
 *
 *   null = fila anterior a esta migracion. El motor cae a la resolucion por cascada, o sea al
 *          comportamiento exacto de antes. Por eso NO hay backfill: null ya es correcto.
 *   1    = este movimiento sumo el producto a stock.
 *   0    = este movimiento no lo sumo.
 *
 * ── Por que se guarda el hecho en vez de recalcularlo al borrar ──────────────────────────────
 *
 * `apply_output_stock_if_end_status()` se llama DOS VECES sobre el mismo movimiento: al crearlo,
 * para sumar, y al borrarlo, para restar. Hasta hoy resolvia el estado final en el momento de
 * cada llamada, y entre una y otra pueden pasar semanas. Si en el medio el usuario cambia el
 * estado final de la ruta, le cambia el grupo, agrega un estado con position mas alta o mueve
 * una position, la resolucion al borrar da OTRO estado, la comparacion no matchea y el borrado
 * NO REVIERTE lo que ese movimiento sumo: queda stock fantasma, sin error y sin log.
 *
 * 🔴 ESTO NO ES "ESTADO DERIVADO GUARDADO EN SU PROPIO SLOT". Esa clase de error habla de
 * cachear un valor que se puede recalcular a partir de una fuente VIGENTE. Aca la fuente no es
 * vigente: es la configuracion en el momento en que el hecho ocurrio, y esa configuracion es
 * mutable. `production_batch_movements` ya es una tabla de hechos, no de configuracion. Lo que
 * se guarda es QUE PASO, no que pasaria. Es la misma razon por la que un `stock_movement`
 * guarda su `stock_resultante`.
 */
class AddOutputStockAppliedToProductionBatchMovementsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('production_batch_movements', 'output_stock_applied')) {
            return;
        }

        Schema::table('production_batch_movements', function (Blueprint $table) {
            $table->boolean('output_stock_applied')->nullable()->default(null);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('production_batch_movements', 'output_stock_applied')) {
            return;
        }

        Schema::table('production_batch_movements', function (Blueprint $table) {
            $table->dropColumn('output_stock_applied');
        });
    }
}
