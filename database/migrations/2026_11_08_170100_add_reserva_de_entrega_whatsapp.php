<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ai_messages', 'entrega_whatsapp_token')) {
            Schema::table('ai_messages', function (Blueprint $table) {
                $table->uuid('entrega_whatsapp_token')->nullable();
                $table->dateTime('entrega_whatsapp_hasta')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['entrega_whatsapp_token', 'entrega_whatsapp_hasta']);
        });
    }
};
