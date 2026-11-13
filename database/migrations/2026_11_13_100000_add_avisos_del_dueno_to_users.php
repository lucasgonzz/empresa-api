<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avisos opcionales que el dueño le pide a su asistente por WhatsApp (misión
 * asistente-truvari-pedidos-fallidos, C2, 13/11/2026): «avisame cuando entre un pedido nuevo en la tienda»,
 * «revisá a las 8, 12, 16 y 22 si hay pedidos y avisame», «cuando un producto llegue a stock cero avisame
 * por este medio». Salen por el canal de RECORDATORIOS que el admin ya consulta cada 5 minutos
 * (AvisosDelDuenoIaHelper); esto guarda solo las preferencias.
 *
 * Columnas en `users` del DUEÑO, como `agente_confianza` y `agente_alta_bebidas`: son del comercio y no de
 * la persona. Todas APAGADAS por defecto: un negocio que no lo pidió no recibe nada. Los dos `*_desde` son el
 * cursor: al prender un aviso arranca desde ese momento, para no mandarle de golpe lo viejo.
 *
 * Aditiva, sin claves foráneas y con guard por columna (correrla dos veces no rompe).
 */
class AddAvisosDelDuenoToUsers extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'agente_aviso_pedidos')) {
                /* 'apagado' | 'al_toque' (uno por pedido) | 'horas' (un resumen en cada hora fija). */
                $table->string('agente_aviso_pedidos', 10)->default('apagado');
            }

            if (!Schema::hasColumn('users', 'agente_aviso_pedidos_horas')) {
                /* Las horas fijas, "08:00,12:00,16:00,22:00" (solo con el modo 'horas'). */
                $table->string('agente_aviso_pedidos_horas', 120)->nullable();
            }

            if (!Schema::hasColumn('users', 'agente_aviso_pedidos_desde')) {
                $table->timestamp('agente_aviso_pedidos_desde')->nullable();
            }

            if (!Schema::hasColumn('users', 'agente_aviso_stock_cero')) {
                $table->boolean('agente_aviso_stock_cero')->default(false);
            }

            if (!Schema::hasColumn('users', 'agente_aviso_stock_desde')) {
                $table->timestamp('agente_aviso_stock_desde')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['agente_aviso_pedidos', 'agente_aviso_pedidos_horas', 'agente_aviso_pedidos_desde', 'agente_aviso_stock_cero', 'agente_aviso_stock_desde'] as $columna) {
                if (Schema::hasColumn('users', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
