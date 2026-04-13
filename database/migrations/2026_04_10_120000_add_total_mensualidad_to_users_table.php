<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTotalMensualidadToUsersTable extends Migration
{
    /**
     * Agrega la columna total_mensualidad a la tabla users.
     *
     * Almacena el monto total calculado de la mensualidad del usuario,
     * teniendo en cuenta cuentas base (dueño + empleados), ecommerce,
     * Mercado Libre y Tienda Nube.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('total_mensualidad', 12, 2)->nullable()->after('total_a_pagar');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('total_mensualidad');
        });
    }
}
