<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTiemposDeRespuestaToAiMessages extends Migration
{
    public function up()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('ai_messages', 'respuesta_publicada_at')) {
                $table->dateTime('respuesta_publicada_at')->nullable();
            }
            if (!Schema::hasColumn('ai_messages', 'demora_respuesta_seg')) {
                $table->unsignedInteger('demora_respuesta_seg')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['respuesta_publicada_at', 'demora_respuesta_seg']);
        });
    }
}
