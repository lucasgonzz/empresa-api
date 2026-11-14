<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hay en una foto del asistente, en texto (misión agente-venta-por-planilla, parte 2, pedido de Lucas del
 * 02/11/2026): la foto se analiza una sola vez con Claude y de ahí en adelante DeepSeek trabaja con este texto, sin volver
 * a ver la imagen. Ver ContenidoDeFotosIaHelper.
 *
 * Aditiva, sin claves foráneas y con `hasColumn`: correrla dos veces no rompe. Mientras no corra, todo sigue como antes
 * (ColumnasDeLaAppIaHelper::hay_columna).
 */
class AddContenidoToAiMessageImagenesTable extends Migration
{
    /**
     * Suma las columnas si faltan.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('ai_message_imagenes', 'contenido')) {
            return;
        }

        Schema::table('ai_message_imagenes', function (Blueprint $table) {

            // Qué hay en la foto, en texto. Una vez escrito no se vuelve a analizar: no vence.
            $table->longText('contenido')->nullable();

            // Con qué modelo se leyó. «tabla:<modelo>» si es una planilla, que no se vuelca en el historial.
            $table->string('contenido_modelo', 40)->nullable();

            $table->timestamp('contenido_at')->nullable();
        });
    }

    /**
     * Saca las columnas.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('ai_message_imagenes', 'contenido')) {
            return;
        }

        Schema::table('ai_message_imagenes', function (Blueprint $table) {

            $table->dropColumn(['contenido', 'contenido_modelo', 'contenido_at']);
        });
    }
}
