<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de fallos de importación de artículos (17/7/2026).
 *
 * import_histories:
 *  - error_trace: log técnico completo (stack trace + contexto + snapshot de memoria). LONGTEXT porque
 *    un trace puede pasar los 65.535 bytes de TEXT; error_message queda para el motivo humano corto.
 *  - failed_chunk_number: en qué lote murió (para ir directo al problema).
 *  - failed_at: cuándo falló.
 *  - import_status_id: link al ImportStatus asociado, para que el watchdog (prompt 503) pueda marcar
 *    ambos registros como fallidos a partir de un ImportHistory colgado.
 *
 * article_import_results:
 *  - peak_memory_mb / memory_limit_mb: pico de memoria del lote. Sirve para confirmar si el cuelgue del
 *    lote ~20 (fila ~9.500) es por OOM: si el pico sube lote a lote hacia el memory_limit, es memoria.
 *
 * Nota: import_status_id NO es una foreign key de motor (regla del workspace: sin foreign() en
 * migraciones nuevas). Es un entero simple; la relación lógica se resuelve en Eloquent.
 */
class AddErrorTrackingToImportTables extends Migration
{
    /**
     * Aplica las columnas nuevas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            /* Log técnico completo del fallo. LONGTEXT (4 GB) para no desbordar como error_message (TEXT). */
            $table->longText('error_trace')->nullable()->after('error_message');

            /* Número de lote (chunk_number) donde murió la importación. */
            $table->integer('failed_chunk_number')->nullable()->after('error_trace');

            /* Momento en que se marcó como fallida. */
            $table->timestamp('failed_at')->nullable()->after('failed_chunk_number');

            /* Link al ImportStatus asociado (mismo import). Sin FK declarativa: relación por columna. */
            $table->integer('import_status_id')->nullable()->after('failed_at');
        });

        Schema::table('article_import_results', function (Blueprint $table) {
            /* Pico de memoria del proceso durante este lote, en MB. */
            $table->decimal('peak_memory_mb', 8, 2)->nullable();

            /* memory_limit vigente al procesar el lote, en MB (-1 => sin límite => se guarda null). */
            $table->integer('memory_limit_mb')->nullable();
        });
    }

    /**
     * Revierte las columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn(['error_trace', 'failed_chunk_number', 'failed_at', 'import_status_id']);
        });

        Schema::table('article_import_results', function (Blueprint $table) {
            $table->dropColumn(['peak_memory_mb', 'memory_limit_mb']);
        });
    }
}
