<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_tours')) {
            Schema::create('support_tours', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid');
                // No usar user_id: el esquema consultable por IA descubre las tablas por ese campo.
                $table->unsignedBigInteger('owner_id');
                $table->unsignedBigInteger('recipient_id');
                $table->uuid('ticket_uuid');
                $table->uuid('origin_ticket_uuid');
                $table->uuid('previous_uuid')->nullable();
                $table->string('source', 15);
                $table->string('tema', 40);
                $table->string('titulo', 120);
                $table->json('textos');
                $table->unsignedTinyInteger('total_steps');
                $table->string('state', 20)->default('pending');
                $table->dateTime('shown_at')->nullable();
                $table->dateTime('accepted_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->json('steps_shown');
                $table->text('question')->nullable();
                $table->unsignedTinyInteger('question_step')->nullable();
                $table->dateTime('question_at')->nullable();
                $table->unsignedBigInteger('question_message_id')->nullable()->unique();
                $table->uuid('question_ticket_uuid')->nullable();
                $table->dateTime('reformulated_at')->nullable();
                $table->json('feedback')->nullable();
                $table->timestamps();

                $table->unique(['owner_id', 'uuid']);
                $table->index(['recipient_id', 'state']);
                $table->index(['owner_id', 'ticket_uuid']);
                $table->index(['owner_id', 'origin_ticket_uuid']);
                $table->index(['owner_id', 'question_ticket_uuid']);
            });
        }

        if (!Schema::hasColumn('support_messages', 'robotito_support_version')) {
            Schema::table('support_messages', function (Blueprint $table) {
                $table->unsignedTinyInteger('robotito_support_version')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_messages', 'robotito_support_version')) {
            Schema::table('support_messages', function (Blueprint $table) {
                $table->dropColumn('robotito_support_version');
            });
        }

        Schema::dropIfExists('support_tours');
    }
};
