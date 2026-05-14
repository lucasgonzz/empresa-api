<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColorsToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega colores configurables para la tienda online.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->string('primary_color', 20)->default('#c5111d')->nullable();
            $table->string('secondary_color', 20)->default('#fe7802')->nullable();
            $table->string('text_color', 20)->default('#F2F2F2')->nullable();
            $table->string('hover_text_color', 20)->default('#FFF')->nullable();
        });
    }

    /**
     * Revierte los colores configurables de la tienda online.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('primary_color');
            $table->dropColumn('secondary_color');
            $table->dropColumn('text_color');
            $table->dropColumn('hover_text_color');
        });
    }
}
