<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre personalizado por línea de presupuesto en el pivot article_budget.
 * Nullable: null indica que se usa el nombre del artículo (misma semántica que article_sale.name).
 */
class AddNameToArticleBudgetTable extends Migration
{
    /**
     * Agrega columna name al pivot article_budget.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('article_budget', 'name')) {
            Schema::table('article_budget', function (Blueprint $table) {
                $table->string('name', 255)->nullable()->after('location');
            });
        }
    }

    /**
     * Revierte la columna name del pivot article_budget.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('article_budget', 'name')) {
            Schema::table('article_budget', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }
}
