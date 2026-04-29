<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega address_id a pedidos Tienda Nube para alinear con Sale al confirmar (depósito / dirección de operación).
 */
class AddAddressIdToTiendaNubeOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('tienda_nube_orders', function (Blueprint $table) {
            $table->integer('address_id')->unsigned()->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('tienda_nube_orders', function (Blueprint $table) {
            $table->dropColumn('address_id');
        });
    }
}
