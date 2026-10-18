<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El escaneo de una factura sabe de qué conversación de WhatsApp salió y si hay que avisar por ahí
 * cuando termina (misión asistente-aviso-escaneo-factura, 16/10/2026).
 *
 * Pedido de Lucas: cuando el dueño manda la foto de una factura por WhatsApp, el asistente da de
 * alta la compra (o se la adjunta a una ya cargada) y, cuando el escaneo termina, le avisa POR
 * WHATSAPP con el link que abre la revisión. El escaneo corre en segundo plano y puede tardar
 * segundos o minutos: para poder avisar después hace falta guardar, en la fila del escaneo, de qué
 * conversación vino y si ese aviso corresponde.
 *
 * Las tres columnas:
 *   - `ai_conversation_id`: la conversación del asistente donde se pidió la compra. Null para todo
 *     escaneo que se sube desde la pantalla. Es lo que permite que el aviso caiga en ESA
 *     conversación (el dueño contesta "dale, ¿qué trae?" y el asistente tiene el contexto) y que
 *     la herramienta consultar_escaneo_de_factura sepa de qué escaneo le hablan.
 *   - `avisar_por_whatsapp`: 1 solo si el escaneo nació por WhatsApp Y el admin de ese cliente
 *     anunció que consulta los avisos (`avisos = 1` en POST admin-sync/asistente/mensajes). Con un
 *     admin viejo queda en 0 y nadie promete un aviso que no va a llegar.
 *   - `avisado_por_whatsapp_at`: cuándo el admin confirmó que el WhatsApp salió
 *     (POST admin-sync/asistente/avisos/avisado). Null = todavía pendiente.
 *
 * 🔴 Aditiva y sin FK, como el resto del esquema: `tienda-api` comparte la base y no lee esta tabla,
 * y una pantalla vieja que crea escaneos sin estas columnas sigue andando por los defaults.
 */
class AddAvisoPorWhatsappToProviderOrderScansTable extends Migration
{
    /**
     * Agrega las tres columnas y sus índices, cada una con su guard hasColumn para que sea segura
     * de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('provider_order_scans')) {
            return;
        }

        if (!Schema::hasColumn('provider_order_scans', 'ai_conversation_id')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {

                /* La conversación del asistente de la que salió el escaneo; null desde la pantalla */
                $table->unsignedBigInteger('ai_conversation_id')->nullable()->after('provider_order_id');

                /* Para encontrar el último escaneo de una conversación sin recorrer la tabla */
                $table->index('ai_conversation_id', 'pos_ai_conv_idx');
            });
        }

        if (!Schema::hasColumn('provider_order_scans', 'avisar_por_whatsapp')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {

                /* 1 = cuando termine, el admin le avisa al dueño por WhatsApp con el link */
                $table->boolean('avisar_por_whatsapp')->default(0)->after('ai_conversation_id');
            });
        }

        if (!Schema::hasColumn('provider_order_scans', 'avisado_por_whatsapp_at')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {

                /* Cuándo el admin confirmó que el WhatsApp del aviso salió; null = pendiente */
                $table->timestamp('avisado_por_whatsapp_at')->nullable()->after('avisar_por_whatsapp');

                /*
                 * Lo que consulta el admin cada minuto (GET admin-sync/asistente/avisos-pendientes):
                 * los escaneos del dueño con aviso pedido y todavía sin avisar.
                 */
                $table->index(['user_id', 'avisar_por_whatsapp', 'avisado_por_whatsapp_at'], 'pos_aviso_wsp_idx');
            });
        }
    }

    /**
     * Quita las tres columnas con sus índices.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('provider_order_scans')) {
            return;
        }

        if (Schema::hasColumn('provider_order_scans', 'avisado_por_whatsapp_at')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {
                $table->dropIndex('pos_aviso_wsp_idx');
                $table->dropColumn('avisado_por_whatsapp_at');
            });
        }

        if (Schema::hasColumn('provider_order_scans', 'avisar_por_whatsapp')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {
                $table->dropColumn('avisar_por_whatsapp');
            });
        }

        if (Schema::hasColumn('provider_order_scans', 'ai_conversation_id')) {

            Schema::table('provider_order_scans', function (Blueprint $table) {
                $table->dropIndex('pos_ai_conv_idx');
                $table->dropColumn('ai_conversation_id');
            });
        }
    }
}
