<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` la opción `derivar_cotizacion_envio` (misión truvari-envio-coherente v2).
 *
 * Qué prende: con la opción PRENDIDA, el agente de WhatsApp no le informa al cliente un costo de envío, un
 * peaje, un «envío gratis» ni un total: esas oraciones se sacan del mensaje y el chat queda como consulta para
 * el equipo (`CotizacionEnvioGuardHelper`). Con la opción APAGADA, el texto y el prompt quedan como siempre.
 *
 * 🔴 EL DEFAULT `false` NO SE CAMBIA: correr esta migración sola no puede alterar lo que el agente le dice a
 * ningún cliente. La opción se prende de a un negocio, a mano.
 *
 * Tabla usada sólo por empresa (tienda no la lee). Sin foreign keys y con guards `Schema::hasColumn` en `up()`
 * y `down()` para poder re-correrla sin romper nada.
 */
class AddDerivarCotizacionEnvioToWhatsappBotConfigs extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_bot_configs', 'derivar_cotizacion_envio')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('derivar_cotizacion_envio')->default(false);
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'derivar_cotizacion_envio')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('derivar_cotizacion_envio');
            });
        }
    }
}
