<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarda de reintento: cada columna se evalúa por separado (una vuelta atrás pudo dejar sólo una).
        Schema::table('ai_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_messages', 'entrega_whatsapp_token')) {
                $table->uuid('entrega_whatsapp_token')->nullable();
            }
            if (!Schema::hasColumn('ai_messages', 'entrega_whatsapp_hasta')) {
                $table->dateTime('entrega_whatsapp_hasta')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['entrega_whatsapp_token', 'entrega_whatsapp_hasta']);
        });
    }
};
