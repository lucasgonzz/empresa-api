<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión tienda-cobro-tarjeta (24/11/2026): casilla de Configuración Online que prende, en la tienda,
 * la leyenda «Con Transferencia o Efectivo, 10 % de descuento» debajo del precio.
 *
 * Boolean con default 0 (apagada): ningún comercio la ve hasta que el dueño la prende. Sin foreign
 * keys ni índices. Con guarda `hasColumn` para poder correrse dos veces.
 *
 * 🔴 Esta migración NO inserta el tipo de método de pago de la tarjeta (ver el seeder suelto): ese tipo se siembra
 * por seeder y se activa base por base DESPUÉS de actualizar la tienda (si lo creara el `migrate`, la
 * tienda vieja lo trataría como un pago offline y el pedido quedaría sin cobro).
 */
class AddMostrarLeyendaDeDescuentoToOnlineConfigurations extends Migration
{
    /**
     * Agrega la columna `mostrar_leyenda_de_descuento` si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('online_configurations', 'mostrar_leyenda_de_descuento')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->boolean('mostrar_leyenda_de_descuento')->default(0);
            });
        }
    }

    /**
     * Quita la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('online_configurations', 'mostrar_leyenda_de_descuento')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->dropColumn('mostrar_leyenda_de_descuento');
            });
        }
    }
}
