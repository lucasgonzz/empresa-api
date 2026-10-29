<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones fijadas (misión app-ia-y-memoria, 29/10/2026, §4 E1.8 del plan): el dueño fija una
 * conversación desde la barra lateral de la app y queda arriba de todo en el listado.
 *
 * Boolean con default false: las conversaciones que ya existen quedan sin fijar.
 */
class AddFijadaToAiConversationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_conversations') || Schema::hasColumn('ai_conversations', 'fijada')) {
            return;
        }

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->boolean('fijada')->default(false);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('ai_conversations') || !Schema::hasColumn('ai_conversations', 'fijada')) {
            return;
        }

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('fijada');
        });
    }
}
