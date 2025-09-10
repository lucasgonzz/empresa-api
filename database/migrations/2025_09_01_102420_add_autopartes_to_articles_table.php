<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAutopartesToArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('espesor')->nullable();
            $table->text('modelo')->nullable();
            $table->string('pastilla')->nullable();
            $table->text('diametro')->nullable();
            $table->text('litros')->nullable();
            $table->text('descripcion')->nullable();
            // $table->text('contenido')->nullable(); // Para columna CANT
            $table->text('cm3')->nullable(); 
            $table->text('calipers')->nullable(); 
            $table->text('juego')->nullable(); 
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
