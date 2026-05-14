<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag aplicar_descuentos_de_venta_a_costos a users.
 *
 * Cuando es verdadero, el costo usado en ciertos cálculos de venta incorpora
 * descuentos y recargos a nivel de pedido (ver SaleHelper).
 */
class AddAplicarDescuentosDeVentaACostosToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('aplicar_descuentos_de_venta_a_costos')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('aplicar_descuentos_de_venta_a_costos');
        });
    }
}
