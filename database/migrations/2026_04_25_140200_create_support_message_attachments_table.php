<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea adjuntos binarios para mensajes de soporte (audio/imagen).
     */
    public function up(): void
    {
        Schema::create('support_message_attachments', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();
            // Relación al mensaje del cual depende el adjunto.
            $table->unsignedBigInteger('support_message_id')->index();
            // Disco y ruta para resolver el archivo.
            $table->string('disk', 50)->default('public');
            $table->string('path');
            // Tipo mime y tamaño en bytes del archivo.
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte la creación de la tabla de adjuntos.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_message_attachments');
    }
};

