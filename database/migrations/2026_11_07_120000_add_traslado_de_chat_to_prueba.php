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
            // Guarda de reintento: la columna y el índice se crean sólo si faltan (vuelta atrás que dejó restos).
            if (!Schema::hasColumn('prueba_config', 'chat_trasladado_id')) {
                $table->unsignedBigInteger('chat_trasladado_id')->nullable();
            }
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_messages', 'prueba_mensaje_id')) {
                $table->unsignedBigInteger('prueba_mensaje_id')->nullable();
            }
            if (!$this->existe_indice('ai_messages', 'ai_messages_traslado_prueba_unique')) {
                $table->unique(['ai_conversation_id', 'prueba_mensaje_id'], 'ai_messages_traslado_prueba_unique');
            }
        });
    }

    /**
     * Indica si la tabla ya tiene un índice con ese nombre (para no crearlo dos veces al reintentar).
     *
     * @param string $tabla
     * @param string $nombre
     * @return bool
     */
    private function existe_indice(string $tabla, string $nombre): bool
    {
        return count(DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre])) > 0;
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
