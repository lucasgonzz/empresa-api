<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddConfiguredAiModeToWhatsappChats extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_chats', 'configured_ai_mode')) {
            Schema::table('whatsapp_chats', function (Blueprint $table) {
                $table->string('configured_ai_mode', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->dropColumn('configured_ai_mode');
        });
    }
}
