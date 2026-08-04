<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: agrega el conteo por escalón de matching y dos contadores huérfanos a
 * `article_import_results` (el chunk) y `import_histories` (la importación completa)
 * (grupo 232, prompt 03).
 *
 * - matching_counts_json: array de ProcessRow::get_conteo_matching() serializado con
 *   json_encode() en una columna de texto, igual patrón que created_with_repeated_code_ids
 *   y updated_props en este mismo repo (no se introduce un tipo `json` nuevo).
 * - filas_ambiguas / identificadores_descartados: ya se calculaban en ArticleImport.php
 *   (grupo 229) pero se descartaban en silencio porque update_article_import_result() no
 *   los persistía y las columnas no existían. Van como enteros aparte del JSON porque se
 *   consultan para decidir si mostrar un aviso en el listado (igual que conflicts_count):
 *   un JSON_EXTRACT en un listado paginado no se justifica.
 */
class AddMatchingCountsToImportAndResults extends Migration
{
    /**
     * Agrega las columnas a ambas tablas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_import_results', function (Blueprint $table) {
            /* Conteo por escalón de matching de este chunk puntual (JSON en texto). */
            $table->text('matching_counts_json')->nullable();
            /* Filas salteadas por match ambiguo entre varios artículos. */
            $table->integer('filas_ambiguas')->default(0);
            /* Identificadores descartados por ser placeholders (ej. "-", "S/N"). */
            $table->integer('identificadores_descartados')->default(0);
        });

        Schema::table('import_histories', function (Blueprint $table) {
            /* Suma de matching_counts_json de todos los chunks de la importación. */
            $table->text('matching_counts_json')->nullable();
            /* Total acumulado de filas_ambiguas de todos los chunks. */
            $table->integer('filas_ambiguas')->default(0);
            /* Total acumulado de identificadores_descartados de todos los chunks. */
            $table->integer('identificadores_descartados')->default(0);
        });
    }

    /**
     * Revierte la migración quitando las seis columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_import_results', function (Blueprint $table) {
            $table->dropColumn('matching_counts_json');
            $table->dropColumn('filas_ambiguas');
            $table->dropColumn('identificadores_descartados');
        });

        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn('matching_counts_json');
            $table->dropColumn('filas_ambiguas');
            $table->dropColumn('identificadores_descartados');
        });
    }
}
