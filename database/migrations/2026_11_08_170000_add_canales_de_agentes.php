<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ai_conversations', 'sistema_exclusivo_at')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                $table->dateTime('sistema_exclusivo_at')->nullable();
            });
        }

        if (!Schema::hasColumn('support_tickets', 'origen')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->string('origen', 20)->default('sistema');
                $table->dateTime('sistema_exclusivo_at')->nullable();
            });
        }

        if (!Schema::hasColumn('support_messages', 'agentes_version')) {
            Schema::table('support_messages', function (Blueprint $table) {
                $table->unsignedTinyInteger('agentes_version')->nullable();
            });
        }

        if (!Schema::hasColumn('support_message_attachments', 'remote_id')) {
            Schema::table('support_message_attachments', function (Blueprint $table) {
                $table->unsignedBigInteger('remote_id')->nullable();
                $table->text('public_url')->nullable();
                $table->unique(['support_message_id', 'remote_id'], 'support_attachments_remote_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('support_message_attachments', function (Blueprint $table) {
            $table->dropUnique('support_attachments_remote_unique');
            $table->dropColumn(['remote_id', 'public_url']);
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('agentes_version');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['origen', 'sistema_exclusivo_at']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('sistema_exclusivo_at');
        });
    }
};
