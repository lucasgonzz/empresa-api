<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `whatsapp_chat_messages` las tres columnas de la copia local del adjunto
 * (misión whatsapp-sidebar-multimedia): `media_path`, `media_mime` y `media_size`.
 *
 * 🔴 POR QUÉ TRES COLUMNAS NUEVAS Y NO RECICLAR `media_url`:
 * `media_url` ya tiene un caller vivo — `store_outbound_document_message()` escribe ahí la
 * URL absoluta y pública del PDF del comprobante de venta — y su significado actual es
 * "URL absoluta de un archivo que NO es nuestro". Si le metiéramos adentro el path relativo
 * de un archivo privado, el front tendría que adivinar en cada mensaje qué de las dos cosas
 * le llegó, y cualquier lugar que hoy hace `<a :href="media_url">` quedaría apuntando a una
 * ruta que no existe. Con columnas separadas, ninguna fila existente cambia de significado y
 * el front lee un solo campo calculado (`media_src`, el accessor del modelo).
 *
 * 🔴 POR QUÉ `media_path` Y NO UNA URL:
 * El archivo NO vive en el disco `public`. `routes/web.php:199-207` sirve `/storage/{path}`
 * de forma pública, sin auth y con `->where('path', '.*')`, así que un audio o una foto de
 * una conversación privada quedaría abierto para siempre a cualquiera que tenga o adivine la
 * URL. Los medios de conversación van al disco `local` (`storage/app/whatsapp/{chat_id}/`) y
 * se sirven por una ruta autenticada. Por eso acá se guarda el path relativo al disco `local`
 * y no una URL: la URL se arma al momento de serializar y depende de quién pregunta.
 * Si alguien "simplifica" esto mandando los archivos a `Storage::disk('public')`, lo que se
 * rompe es la privacidad de todas las conversaciones, en silencio y sin ningún error.
 *
 * `media_size` es `unsignedInteger` (techo ~4 GB) y no `bigInteger` porque el límite real lo
 * pone la Cloud API de Meta: 16 MB el audio, 5 MB la imagen. Sobra por tres órdenes de magnitud.
 *
 * Sin foreign keys (convención del repo) y con guards `Schema::hasColumn` en `up()` y en
 * `down()` para poder re-correrla sin romper nada.
 */
class AddMediaColumnsToWhatsappChatMessagesTable extends Migration
{
    /**
     * Agrega las columnas nuevas si todavía no existen.
     *
     * @return void
     */
    public function up()
    {
        // Path del archivo RELATIVO al disco `local` (ej: 'whatsapp/12/wa_a1b2c3d4e5f6.ogg').
        // Nunca una URL: ver el docblock de arriba. Null cuando el mensaje no tiene copia local
        // (texto, o un medio cuya descarga falló: la fila se guarda igual).
        if (! Schema::hasColumn('whatsapp_chat_messages', 'media_path')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->string('media_path', 255)->nullable();
            });
        }

        // Mime real del archivo guardado. Es el que se devuelve como `Content-Type` al servirlo
        // por la ruta autenticada, y el que se declara en el multipart al subirlo a Meta (Meta
        // valida que el mime y la extensión sean coherentes). No se deriva del nombre de archivo
        // que manda el webhook: eso permitiría que un `.php` del payload termine ejecutándose.
        if (! Schema::hasColumn('whatsapp_chat_messages', 'media_mime')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->string('media_mime', 100)->nullable();
            });
        }

        // Tamaño en bytes de la copia local. Sirve para mostrarlo en la interfaz y para
        // detectar archivos truncados sin tener que ir al disco.
        if (! Schema::hasColumn('whatsapp_chat_messages', 'media_size')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->unsignedInteger('media_size')->nullable();
            });
        }
    }

    /**
     * Revierte las columnas agregadas si existen.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('whatsapp_chat_messages', 'media_path')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('media_path');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'media_mime')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('media_mime');
            });
        }

        if (Schema::hasColumn('whatsapp_chat_messages', 'media_size')) {
            Schema::table('whatsapp_chat_messages', function (Blueprint $table) {
                $table->dropColumn('media_size');
            });
        }
    }
}
