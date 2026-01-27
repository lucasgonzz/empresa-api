<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CorregirCamposTiendaNubeToArticles extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->decimal('peso', 30,4)->nullable()->change();
            $table->decimal('profundidad', 30,4)->nullable()->change();
            $table->decimal('ancho', 30,4)->nullable()->change();
            $table->decimal('alto', 30,4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            //
        });
    }
}
