<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPreciosServiciosToUsersTable extends Migration
{
    /**
     * Agrega columnas de precio individual por servicio al usuario.
     *
     * Cada columna permite sobreescribir el precio_por_cuenta para ese
     * servicio en particular. Si el valor es NULL, se utiliza precio_por_cuenta
     * como fallback en el cálculo del total_mensualidad.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Precio por la cuenta de ecommerce (tienda online)
            $table->decimal('precio_ecommerce', 12, 2)->nullable()->after('total_mensualidad');

            // Precio por la cuenta de Mercado Libre
            $table->decimal('precio_mercado_libre', 12, 2)->nullable()->after('precio_ecommerce');

            // Precio por la cuenta de Tienda Nube
            $table->decimal('precio_tienda_nube', 12, 2)->nullable()->after('precio_mercado_libre');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['precio_ecommerce', 'precio_mercado_libre', 'precio_tienda_nube']);
        });
    }
}
