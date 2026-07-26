<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla `import_conflicts` (grupo 229, prompt 02).
 *
 * Persiste las filas del Excel de importación de artículos que no se pudieron
 * procesar de forma segura: identificador ambiguo (match contra más de un
 * artículo), identificador descartado por ser un placeholder ("-", "S/N", etc.)
 * o fila sin ningún identificador utilizable. Antes de este prompt esta
 * información solo quedaba en el log del servidor; ahora se guarda para
 * mostrarla en el frontend (Historial de importaciones).
 *
 * No lleva foreign keys (convención del proyecto para migraciones nuevas):
 * la relación con import_histories/article_import_results se resuelve en
 * Eloquent (ver App\Models\ImportConflict).
 */
class CreateImportConflictsTable extends Migration
{
    /**
     * Crea la tabla import_conflicts.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('import_conflicts', function (Blueprint $table) {
            $table->id();

            // Historial de importación al que pertenece este conflicto.
            $table->unsignedBigInteger('import_history_id')->index();

            // Chunk (ArticleImportResult) donde se detectó el conflicto; puede ser null si no aplica.
            $table->unsignedBigInteger('article_import_result_id')->nullable()->index();

            /* Fila del Excel, tal como la ve el usuario (1-based, incluyendo header). */
            $table->integer('fila')->nullable();

            /*
             * Tipo de conflicto:
             *   'ambiguo'                -> el identificador resolvio a mas de un articulo
             *   'placeholder_descartado' -> el valor era "-", "S/N", etc. y se ignoro
             *   'sin_identificador'      -> la fila quedo sin ningun identificador usable
             */
            $table->string('tipo', 40);

            /* bar_code | sku | provider_code | id */
            $table->string('campo', 40)->nullable();

            /* Valor tal cual venia en el Excel. */
            $table->text('valor')->nullable();

            /* Ids de los articulos que compitieron por el match (solo en 'ambiguo'). */
            $table->json('article_ids')->nullable();

            /* Nombre del producto en el Excel, para que el usuario lo ubique. */
            $table->text('nombre_excel')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Revierte la migración eliminando la tabla import_conflicts.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('import_conflicts');
    }
}
