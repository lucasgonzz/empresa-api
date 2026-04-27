<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla principal de tickets de soporte para usuarios de empresa.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();
            // UUID público para sincronización con admin-api.
            $table->uuid('uuid')->unique();
            // Usuario de empresa-api dueño del ticket.
            $table->unsignedBigInteger('user_id')->index();
            // Nombre final del ticket (se suele completar al cerrar).
            $table->string('name')->nullable();
            // Estado operativo del ticket.
            $table->string('status', 20)->default('open')->index();
            // Fecha de apertura y cierre para trazabilidad.
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte la creación de la tabla de tickets de soporte.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};

