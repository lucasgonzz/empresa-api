<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 382 - Notificaciones por mail configurables desde la interfaz.
 *
 * Hasta ahora el envio de mails se prendia/apagaba desde el .env de cada API (SEND_MAILS en
 * empresa-api, NO_ENVIAR_MAILS en tienda-api), lo que obligaba a entrar por SSH al servidor de cada
 * cliente para cambiarlo. Pasa a ser configuracion por cliente, editable desde la Configuracion
 * Online del ERP.
 */
class AddNotificacionesMailToOnlineConfigurationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            // Aviso al comercio cuando entra un pedido nuevo desde la tienda online.
            $table->boolean('notificar_pedido_al_negocio')->default(true)->after('mail_from_name');
            // Casilla(s) donde recibir ese aviso. Admite varios correos separados por coma.
            // Si queda vacio, se usa el email de la cuenta del usuario (comportamiento actual).
            $table->string('mail_notificacion_pedidos', 500)->nullable()->after('notificar_pedido_al_negocio');
            // Mail de confirmacion al comprador cuando su pedido se crea correctamente.
            $table->boolean('notificar_pedido_al_cliente')->default(true)->after('mail_notificacion_pedidos');
            // Reemplaza la variable de entorno SEND_MAILS: gate del aviso "ingreso stock del
            // articulo que estabas esperando". Ver prompt 383.
            $table->boolean('avisar_ingreso_stock_por_mail')->default(true)->after('notificar_pedido_al_cliente');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'notificar_pedido_al_negocio',
                'mail_notificacion_pedidos',
                'notificar_pedido_al_cliente',
                'avisar_ingreso_stock_por_mail',
            ]);
        });
    }
}
