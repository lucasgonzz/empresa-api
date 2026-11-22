<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReservaStockWebLiberada extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // La cohorte de nacimiento permanece para reconocer reintentos del checkout.
            // Liberar disponibilidad es un estado distinto de haberla consumido físicamente.
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó restos) no se crea de nuevo.
            if (!Schema::hasColumn('orders', 'reserva_stock_web_liberada')) {
                $table->boolean('reserva_stock_web_liberada')->default(false);
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reserva_stock_web_liberada');
        });
    }
}
