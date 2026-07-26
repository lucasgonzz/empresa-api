<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega `conflicts_count` a `import_histories` y `article_import_results`
 * (grupo 229, prompt 02).
 *
 * Contador acumulado de conflictos (import_conflicts) para no tener que hacer
 * COUNT(*) cada vez que se lista el historial o se decide si mostrar el botón
 * de "ver conflictos" en el frontend (prompt 05). Se actualiza con
 * increment() al persistir cada lote, nunca con lectura-escritura, porque
 * los chunks de un mismo import pueden procesarse en paralelo.
 */
class AddConflictsCountToImportHistories extends Migration
{
    /**
     * Agrega la columna conflicts_count a ambas tablas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            /* Total acumulado de conflictos de todos los lotes. */
            $table->integer('conflicts_count')->default(0);
        });

        Schema::table('article_import_results', function (Blueprint $table) {
            /* Total de conflictos detectados en este chunk puntual. */
            $table->integer('conflicts_count')->default(0);
        });
    }

    /**
     * Revierte la migración quitando la columna conflicts_count de ambas tablas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn('conflicts_count');
        });

        Schema::table('article_import_results', function (Blueprint $table) {
            $table->dropColumn('conflicts_count');
        });
    }
}
