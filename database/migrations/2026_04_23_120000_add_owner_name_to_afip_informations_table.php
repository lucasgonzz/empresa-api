<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOwnerNameToAfipInformationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('afip_information', function (Blueprint $table) {
            /**
             * Nombre opcional del dueño para mostrar en la cabecera de factura.
             */
            $table->string('owner_name', 120)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('afip_informations', function (Blueprint $table) {
            $table->dropColumn('owner_name');
        });
    }
}
