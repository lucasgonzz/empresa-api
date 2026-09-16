<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las fotos que el dueño le manda al asistente por WhatsApp (misión asistente-por-whatsapp,
 * 16/9/2026).
 *
 * El caso que la motiva es el que dictó Lucas: la foto de la factura de un proveedor con un
 * "esto es la compra de tal proveedor". La foto llega al admin desde Kapso, el admin la empuja a
 * este API junto con el texto y acá queda una fila por imagen, colgada del AiMessage 'user' que
 * la trajo.
 *
 * `user_id` está desnormalizado a propósito (mismo criterio que provider_order_scan_images): el
 * endpoint que sirve el binario y la herramienta que junta las fotos sin gestionar filtran por
 * tenencia sin tener que joinear hasta ai_conversations.
 *
 * `gestionada_at` es lo que separa "una foto que todavía está esperando que alguien diga de qué
 * es" de una ya usada. proponer_compra_con_factura toma las que están en null de los últimos
 * mensajes de la conversación, y al confirmar la carga las sella: así la foto de un turno y el
 * nombre del proveedor del turno siguiente se encuentran, y una foto no se cuelga de dos compras.
 *
 * Sin foreign keys físicas, siguiendo el estilo del resto del schema.
 */
class CreateAiMessageImagenesTable extends Migration
{
    /**
     * Crea la tabla, con guard hasTable para que sea segura de re-ejecutar.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ai_message_imagenes')) {
            return;
        }

        Schema::create('ai_message_imagenes', function (Blueprint $table) {

            /* Clave primaria autoincremental */
            $table->bigIncrements('id');

            /* Mensaje 'user' que trajo la foto (ai_messages.id) */
            $table->unsignedBigInteger('ai_message_id');

            /* Dueño de la cuenta (users.id con owner_id null), para filtrar sin join */
            $table->unsignedBigInteger('user_id');

            /* Orden de la foto dentro del mensaje, empezando en 1 */
            $table->tinyInteger('orden')->unsigned()->default(1);

            /* Ruta en el disco 'local' (privado): asistente_imagenes/{user_id}/{ai_message_id}/{orden}.webp */
            $table->string('path', 191);

            /* Tipo del binario guardado; hoy siempre image/webp (se redimensiona al guardar) */
            $table->string('mime', 60)->default('image/webp');

            /* Peso del binario ya redimensionado */
            $table->unsignedInteger('bytes')->default(0);

            /* Cuándo la foto quedó usada por una carga (hoy: la compra con factura). Null = libre */
            $table->timestamp('gestionada_at')->nullable();

            $table->timestamps();

            /* Las fotos de un mensaje, en orden, para armar el payload a Anthropic */
            $table->index(['ai_message_id', 'orden'], 'ami_message_orden_idx');

            /* Las fotos todavía sin gestionar de un dueño (lo que busca proponer_compra_con_factura) */
            $table->index(['user_id', 'gestionada_at'], 'ami_user_gestionada_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_message_imagenes');
    }
}
