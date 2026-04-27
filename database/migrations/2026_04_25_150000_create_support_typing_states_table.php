<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea snapshots de estado "escribiendo" para soporte en empresa-api.
     */
    public function up(): void
    {
        Schema::create('support_typing_states', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Ticket donde ocurre la escritura.
            $table->unsignedBigInteger('support_ticket_id')->index();
            // Tipo de actor que escribe (user/admin).
            $table->string('actor_type', 20)->index();
            // ID local del actor cuando aplica.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            // Fecha de último estado "escribiendo".
            $table->dateTime('last_typing_at')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte tabla de typing states.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_typing_states');
    }
};

