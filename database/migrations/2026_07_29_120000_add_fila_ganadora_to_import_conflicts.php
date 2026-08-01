<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega `fila_ganadora` a `import_conflicts` (grupo 265, prompt 01).
 *
 * Hoy, cuando dos filas del Excel de artículos representan al mismo producto,
 * la importación resuelve el choque sin dejar rastro por fila: solo incrementa
 * un contador agregado (`merge_bar_code_repetido` dentro de `matching_counts_json`).
 * Esta columna permite reportar, para cada fila descartada, cuál fue la fila
 * que prevaleció.
 *
 * Número de fila del Excel (1-based, igual que `fila`) cuyos datos prevalecieron
 * cuando esta fila fue descartada por representar el mismo producto. Solo se
 * completa en los conflictos de tipo 'fila_sobrescrita'; en todos los demás
 * tipos queda null.
 */
class AddFilaGanadoraToImportConflicts extends Migration
{
    /**
     * Agrega la columna fila_ganadora a import_conflicts.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_conflicts', function (Blueprint $table) {
            $table->integer('fila_ganadora')->nullable()->after('fila');
        });
    }

    /**
     * Revierte la migración quitando la columna fila_ganadora.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_conflicts', function (Blueprint $table) {
            $table->dropColumn('fila_ganadora');
        });
    }
}
