<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega estado explícito cuando el mensaje está guardado localmente pero no en el API remoto.
     */
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            // null = sin fallo de entrega remota; not_received = pendiente de sincronización fallida.
            $table->string('remote_delivery_status', 30)->nullable()->index();
        });
    }

    /**
     * Elimina la columna de estado de entrega remota.
     */
    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('remote_delivery_status');
        });
    }
};
