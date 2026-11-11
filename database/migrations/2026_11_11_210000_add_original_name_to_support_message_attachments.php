<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El nombre con el que el usuario subió el adjunto de un mensaje de Soporte (misión
 * prueba-e2e-campana-imperio, 11/11/2026).
 *
 * Hasta hoy el chat de Soporte solo aceptaba audios e imágenes, y el archivo se guardaba con un nombre al
 * azar: alcanzaba. Desde que acepta la lista de precios del negocio (Excel, CSV, PDF) hace falta el nombre
 * original, para mostrarlo en la burbuja y para que el admin sepa qué archivo recibió. Nullable: los
 * adjuntos anteriores quedan como están.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('support_message_attachments', 'original_name')) {
            Schema::table('support_message_attachments', function (Blueprint $table) {
                $table->string('original_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_message_attachments', 'original_name')) {
            Schema::table('support_message_attachments', function (Blueprint $table) {
                $table->dropColumn('original_name');
            });
        }
    }
};
