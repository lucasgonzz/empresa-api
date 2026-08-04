<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migración: agrega el seguimiento de reversión a `import_histories`
 * (grupo 305, prompt 01).
 *
 * Hoy nada en la base registra que una importación fue revertida: el único
 * rastro es una frase que el job agrega a `observations`, columna que además
 * el usuario edita a mano desde el propio historial. Estas cinco columnas
 * son la fuente de verdad que el prompt 02 usa para bloquear un segundo
 * rollback y el prompt 03 para pintar el estado en la interfaz.
 */
class AddRollbackTrackingToImportHistories extends Migration
{
    /**
     * Agrega las columnas de seguimiento de rollback y hace el backfill
     * de las importaciones que ya se revirtieron antes de que existieran.
     *
     * @return void
     */
    public function up()
    {
        /*
         * Sin ->after(): esta migración encadena contra conflicts_count, que
         * es de hace pocos días (2026_07_26). Si una instancia todavía no la
         * corrió, el after() falla. El orden de columnas no le importa a
         * nadie; que la migración no explote en el servidor de un cliente, sí.
         */
        Schema::table('import_histories', function (Blueprint $table) {
            /* null = nunca se revirtió · encolado · revertida · fallido */
            $table->string('rollback_status', 20)->nullable();
            $table->timestamp('rollback_requested_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->text('rollback_error')->nullable();
            /* Sin foreign(): regla del workspace para migraciones nuevas. */
            $table->integer('rollback_employee_id')->nullable();
        });

        /*
         * Backfill: marcar como ya revertidas las importaciones históricas
         * que el job revirtió antes de que estas columnas existieran. El
         * único rastro disponible es la frase que el job escribe en
         * observations. Sin este backfill, un cliente que ya revirtió una
         * importación el mes pasado vuelve a ver el botón habilitado.
         */
        DB::table('import_histories')
            ->whereNotNull('observations')
            ->where('observations', 'like', '%Rollback ejecutado%')
            ->update([
                'rollback_status' => 'revertida',
                /*
                 * Aproximación: updated_at es lo más cercano a la fecha real
                 * disponible, porque el job guarda el historial al final de
                 * la transacción del rollback. No es un dato exacto.
                 */
                'rolled_back_at'  => DB::raw('updated_at'),
            ]);
    }

    /**
     * Revierte la migración quitando las cinco columnas de rollback.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn([
                'rollback_status',
                'rollback_requested_at',
                'rolled_back_at',
                'rollback_error',
                'rollback_employee_id',
            ]);
        });
    }
}
