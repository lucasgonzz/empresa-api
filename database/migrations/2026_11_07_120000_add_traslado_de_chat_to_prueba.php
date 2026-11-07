<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddTrasladoDeChatToPrueba extends Migration
{
    public function up()
    {
        Schema::table('prueba_config', function (Blueprint $table) {
            // Se conserva el id si el dueño borra el hilo, para no recrearlo.
            $table->unsignedBigInteger('chat_trasladado_id')->nullable();
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('prueba_mensaje_id')->nullable();
            $table->unique(['ai_conversation_id', 'prueba_mensaje_id'], 'ai_messages_traslado_prueba_unique');
        });
    }

    public function down()
    {
        if (DB::table('prueba_config')->whereNotNull('chat_trasladado_id')->exists()) {
            throw new RuntimeException('Hay historiales de prueba trasladados. Se deben conservar sus referencias.');
        }

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropUnique('ai_messages_traslado_prueba_unique');
            $table->dropColumn('prueba_mensaje_id');
        });

        Schema::table('prueba_config', function (Blueprint $table) {
            $table->dropColumn('chat_trasladado_id');
        });
    }
}
