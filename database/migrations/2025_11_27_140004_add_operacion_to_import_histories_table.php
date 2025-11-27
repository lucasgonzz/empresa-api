<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOperacionToImportHistoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            
            $table->string('operacion_a_realizar')->nullable();
            $table->boolean('no_actualizar_otro_proveedor')->nullable();
            $table->json('columnas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('import_histories', function (Blueprint $table) {
            //
        });
    }
}
