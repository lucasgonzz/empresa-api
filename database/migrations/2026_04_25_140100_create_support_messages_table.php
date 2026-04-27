<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla de mensajes asociados a tickets de soporte.
     */
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            // Identificador interno autoincremental.
            $table->id();
            // UUID de referencia cruzada entre APIs.
            $table->uuid('uuid')->unique();
            // Ticket padre del mensaje.
            $table->unsignedBigInteger('support_ticket_id')->index();
            // Rol del emisor (user/admin).
            $table->string('sender_type', 20)->index();
            // Usuario interno de empresa-api que envía (si aplica).
            $table->unsignedBigInteger('sender_user_id')->nullable()->index();
            // UUID del admin emisor (si aplica).
            $table->uuid('sender_admin_uuid')->nullable()->index();
            // Tipo de contenido del mensaje.
            $table->string('kind', 20)->default('text')->index();
            // Texto del mensaje (null en audio/imagen).
            $table->longText('body')->nullable();
            // Metadatos de entrega y lectura.
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            // Momento en que se sincronizó hacia admin-api.
            $table->dateTime('synced_to_admin_at')->nullable()->index();
            // Metadatos estándar.
            $table->timestamps();
        });
    }

    /**
     * Revierte la creación de la tabla de mensajes de soporte.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};

