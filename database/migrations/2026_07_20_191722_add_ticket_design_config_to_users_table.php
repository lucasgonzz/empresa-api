<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columnas de configuración del diseño del ticket de venta a la tabla `users`.
 * Estos valores reproducen exactamente el diseño actual (nombre 12, precio 10, logo a la mitad izquierda),
 * por lo que ningún cliente de los 40 existentes experimenta cambio visual alguno.
 */
class AddTicketDesignConfigToUsersTable extends Migration
{
    /**
     * Agrega las 3 columnas de configuración de ticket con guards idempotentes.
     *
     * @return void
     */
    public function up()
    {
        // Columna: tamaño de fuente del nombre del artículo en el ticket (default 12).
        if (! Schema::hasColumn('users', 'sale_ticket_name_font_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('sale_ticket_name_font_size')->default(12);
            });
        }

        // Columna: tamaño de fuente de los precios en el ticket (default 10).
        if (! Schema::hasColumn('users', 'sale_ticket_price_font_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('sale_ticket_price_font_size')->default(10);
            });
        }

        // Columna: si el logo ocupa todo el ancho del ticket (default 0 = mitad izquierda).
        if (! Schema::hasColumn('users', 'sale_ticket_logo_full_width')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('sale_ticket_logo_full_width')->default(0);
            });
        }
    }

    /**
     * Revierte las columnas agregadas si existen (rollback seguro en entornos desalineados).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'sale_ticket_name_font_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sale_ticket_name_font_size');
            });
        }

        if (Schema::hasColumn('users', 'sale_ticket_price_font_size')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sale_ticket_price_font_size');
            });
        }

        if (Schema::hasColumn('users', 'sale_ticket_logo_full_width')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sale_ticket_logo_full_width');
            });
        }
    }
}
