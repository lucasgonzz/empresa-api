<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_bot_configs` el interruptor de visión del agente
 * (misión whatsapp-sidebar-multimedia): `ai_vision_enabled`.
 *
 * Qué prende: con el interruptor APAGADO, una imagen entrante llega al historial del agente
 * como texto (el epígrafe, o el literal `[Imagen recibida]`) — el modelo se entera de que
 * llegó una foto pero NO la mira, y no se gasta un solo token de visión. Con el interruptor
 * PRENDIDO, ese turno viaja a Anthropic como bloques, con la imagen en base64.
 *
 * 🔴 EL DEFAULT `false` PRESERVA EXACTAMENTE EL COMPORTAMIENTO PREVIO, y no es una decisión
 * estética. Antes de esta misión las imágenes entrantes se descartaban en silencio, así que
 * ningún agente jamás vio una. Con el default apagado, correr esta migración sola no le
 * cambia el comportamiento ni el costo a ningún negocio que ya tenga el bot andando: la
 * visión se prende de a uno, a mano, en Configuración → Agente. Prenderlo de fábrica sería
 * subirle la factura de tokens a todos los clientes sin que nadie lo pida. No cambiar
 * este default.
 *
 * Sin foreign keys (convención del repo) y con guards `Schema::hasColumn` en `up()` y en
 * `down()` para poder re-correrla sin romper nada.
 */
class AddAiVisionEnabledToWhatsappBotConfigsTable extends Migration
{
    /**
     * Agrega la columna nueva si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        // Si el agente interpreta las imágenes que manda el cliente. false = solo se entera de
        // que llegó una (comportamiento previo, sin costo de visión).
        if (! Schema::hasColumn('whatsapp_bot_configs', 'ai_vision_enabled')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->boolean('ai_vision_enabled')->default(false);
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
        if (Schema::hasColumn('whatsapp_bot_configs', 'ai_vision_enabled')) {
            Schema::table('whatsapp_bot_configs', function (Blueprint $table) {
                $table->dropColumn('ai_vision_enabled');
            });
        }
    }
}
