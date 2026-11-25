<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026): casilla de Configuración Online «Recibir por WhatsApp el aviso de
 * pedido nuevo de tu asistente personal».
 *
 * Boolean NOT NULL con default 1 (prendida): sólo tiene efecto en los comercios con la extensión Agentes de IA
 * (el modo efectivo lo decide AvisosDelDuenoIaHelper::preferencias()), así que el resto no cambia en nada.
 * Sin foreign keys ni índices. Con guarda `hasColumn` para poder correrse dos veces.
 *
 * tienda-api la lee (con `Schema::hasColumn`) para decidir si le avisa a empresa de un pedido nuevo.
 */
class AddNotificarPedidoPorWhatsappToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna `notificar_pedido_por_whatsapp` si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->boolean('notificar_pedido_por_whatsapp')->default(1);
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
        if (Schema::hasColumn('online_configurations', 'notificar_pedido_por_whatsapp')) {
            Schema::table('online_configurations', function (Blueprint $table) {
                $table->dropColumn('notificar_pedido_por_whatsapp');
            });
        }
    }
}
