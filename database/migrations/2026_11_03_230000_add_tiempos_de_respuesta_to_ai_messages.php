<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTiemposDeRespuestaToAiMessages extends Migration
{
    public function up()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dateTime('respuesta_publicada_at')->nullable();
            $table->unsignedInteger('demora_respuesta_seg')->nullable();
        });
    }

    public function down()
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['respuesta_publicada_at', 'demora_respuesta_seg']);
        });
    }
}
