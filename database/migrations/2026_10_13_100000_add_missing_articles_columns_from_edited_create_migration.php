<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repone en `articles` las columnas que el 11/9/2024 (commit b61cb9de) se agregaron
 * EDITANDO la migración `2019_10_24_002337_create_articles_table` en lugar de crear una nueva.
 *
 * 🔴 POR QUÉ HACE FALTA, porque no se deduce del código:
 * Una migración que ya figura como corrida no se vuelve a ejecutar, así que las bases
 * creadas antes de esa fecha nunca recibieron esas columnas. En HiperMax (13/10/2026)
 * faltaba `unidades_por_bulto` y `ArticleController::update` tiraba
 * `Unknown column 'unidades_por_bulto'` al guardar cualquier artículo.
 *
 * Se revisan las cinco que entraron en ese commit y solo se crean las que falten:
 * en una base nueva ya existen y la migración no hace nada.
 */
class AddMissingArticlesColumnsFromEditedCreateMigration extends Migration
{
    /**
     * Agrega cada columna solo si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('articles', 'percentage_gain_blanco')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->decimal('percentage_gain_blanco', 16, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('articles', 'final_price_blanco')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->decimal('final_price_blanco', 30, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('articles', 'unidades_por_bulto')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->integer('unidades_por_bulto')->nullable();
            });
        }

        if (! Schema::hasColumn('articles', 'contenido')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('contenido')->nullable();
            });
        }

        if (! Schema::hasColumn('articles', 'tipo_envase_id')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('tipo_envase_id')->nullable();
            });
        }
    }

    /**
     * No se revierte: las columnas pertenecen al esquema original de `articles`.
     *
     * @return void
     */
    public function down()
    {
    }
}
