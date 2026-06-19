<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre personalizado por línea de venta en el pivot article_sale.
 * Nullable: null indica que se usa el nombre del artículo (y variante si aplica).
 */
class AddNameToArticleSaleTable extends Migration
{
    /**
     * Agrega columna name al pivot article_sale.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('variant_description');
        });
    }

    /**
     * Revierte la columna name del pivot article_sale.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('article_sale', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
}
