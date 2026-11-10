<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAlcanceAImportacionesDePrueba extends Migration
{
    public function up()
    {
        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            $table->string('alcance', 24)->nullable();
        });
    }

    public function down()
    {
        Schema::table('importaciones_de_prueba', function (Blueprint $table) {
            $table->dropColumn('alcance');
        });
    }
}
