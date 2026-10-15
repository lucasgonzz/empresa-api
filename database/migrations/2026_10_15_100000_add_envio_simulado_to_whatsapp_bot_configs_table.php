<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` la columna `envio_simulado`: el modo de ENVÍO SIMULADO de las
 * instancias de demo (misión whatsapp-envio-simulado-demos, 15/10/2026).
 *
 * Con la columna en 1 (y SOLO en una instancia de demo, ver
 * `WhatsappBotConfig::esta_en_modo_demostracion()`), `WhatsappBotSendService` no le habla a
 * Kapso: devuelve un `wa_message_id` ficticio y los mensajes quedan en la conversación como
 * enviados. Es lo que permite filmar el módulo de WhatsApp del centro de recursos sin mandarle
 * nada a un cliente real ni arriesgar la calificación del número de WhatsApp Business.
 *
 * 🔴 DEFAULT 0, Y NINGUNA PANTALLA NI ENDPOINT LA ESCRIBE. La siembra la hace
 * `WhatsappDemoHelper` (demo setup / `whatsapp:demo-simulado`) o se prende a mano en la base. Un
 * cliente real con esta columna en 1 tendría envíos que parecen salir y no salen, que es el peor
 * modo de falla de este módulo: por eso `update_config` no la acepta y por eso el service
 * además exige que la instancia sea de demo antes de simular.
 *
 * Guard idempotente con `Schema::hasColumn`, mismo criterio que
 * `2026_08_18_100200_add_chat_simulation_enabled_to_whatsapp_bot_configs_table`.
 */
class AddEnvioSimuladoToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_bot_configs', 'envio_simulado')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('envio_simulado')->default(false);
            });
        }
    }

    /**
     * Revierte la columna agregada si existe.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_bot_configs', 'envio_simulado')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('envio_simulado');
            });
        }
    }
}
