<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProviderIdToColumnPositionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('column_positions', function (Blueprint $table) {

            $table->integer('provider_id')->nullable();
            $table->boolean('create_and_edit')->nullable();
            $table->boolean('no_actualizar_otro_proveedor')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('column_positions', function (Blueprint $table) {
            //
        });
    }
}
