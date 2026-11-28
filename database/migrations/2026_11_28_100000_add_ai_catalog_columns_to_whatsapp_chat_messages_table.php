<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_chat_messages` dos columnas para el agente de WhatsApp (misión
 * ft-agente-fotos-alternativas-t172, ticket 172 de Ferretotal):
 *
 *  - `ai_catalog_article_ids`: ids (JSON) de los artículos del catálogo que vio el modelo al armar esa
 *    respuesta. El turno siguiente los relee para no perder de qué productos se venía hablando
 *    («¿me pasás la foto?» no trae el producto por sí solo).
 *  - `ai_extra_media_urls`: URLs (JSON) de las fotos 2 a 4 que acompañan a la respuesta del agente. La
 *    primera viaja en `media_url`; estas salen como imágenes sueltas cuando se envía el borrador.
 *
 * Las dos son `text` nullable sin default: una fila vieja (o una respuesta sin fotos ni catálogo) queda
 * exactamente como antes. Tabla usada sólo por empresa (tienda-api no la lee). Sin foreign keys, sin
 * índices y con guardas `Schema::hasColumn` en `up()` y `down()` para poder re-correrla.
 */
class AddAiCatalogColumnsToWhatsappChatMessagesTable extends Migration
{
    /**
     * Agrega las columnas nuevas que todavía no existan.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_catalog_article_ids')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->text('ai_catalog_article_ids')->nullable();
            });
        }

        if (! Schema::hasColumn('whatsapp_chat_messages', 'ai_extra_media_urls')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->text('ai_extra_media_urls')->nullable();
            });
        }
    }

    /**
     * Quita las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_extra_media_urls')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_extra_media_urls');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'ai_catalog_article_ids')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('ai_catalog_article_ids');
            });
        }
    }
}
