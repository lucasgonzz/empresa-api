<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La valoración del dueño sobre cada respuesta del agente personal (misión agente-mejoras-uso-real,
 * 29/10/2026): me gusta / no me gusta y, con no me gusta, el comentario de por qué o qué mejorar.
 *
 * La escriben el chat del sistema y la app ComercioCity IA con `PUT api/ai-mensajes/{id}/valoracion`
 * y la lee el análisis diario de las 22 h (herramientas/agente-analisis-diario del repo de
 * conocimiento), que lista cada no me gusta con su comentario para mejorar el agente.
 *
 * Todo nullable: un mensaje sin valorar queda en null, como todos los que ya existen. Con guard para
 * que sea segura de re-ejecutar.
 */
class AddValoracionToAiMessagesTable extends Migration
{
    /**
     * Agrega las tres columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_messages', 'valoracion')) {
                /* me_gusta | no_me_gusta | null (sin valorar) */
                $table->string('valoracion', 15)->nullable();
            }
            if (!Schema::hasColumn('ai_messages', 'valoracion_comentario')) {
                /* Por qué no le gustó o qué le gustaría mejorar (texto libre del dueño). */
                $table->text('valoracion_comentario')->nullable();
            }
            if (!Schema::hasColumn('ai_messages', 'valorado_at')) {
                /* Cuándo valoró por última vez. */
                $table->timestamp('valorado_at')->nullable();
            }
        });
    }

    /**
     * Elimina las columnas si existen.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            foreach (['valoracion', 'valoracion_comentario', 'valorado_at'] as $columna) {
                if (Schema::hasColumn('ai_messages', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
}
