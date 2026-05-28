<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddArticleDescriptionFontSizeToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega tamaño de fuente configurable para la descripción de artículos.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->unsignedInteger('article_description_font_size')->default(16)->nullable();
        });
    }

    /**
     * Revierte el tamaño de fuente configurable para la descripción de artículos.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('article_description_font_size');
        });
    }
}
