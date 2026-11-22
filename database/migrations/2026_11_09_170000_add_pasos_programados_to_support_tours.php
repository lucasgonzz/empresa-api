<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tours', function (Blueprint $table) {
            // Guarda de reintento: si la columna ya existe (vuelta atrás que dejó columnas) no se crea de nuevo.
            if (!Schema::hasColumn('support_tours', 'pasos_programados')) {
                $table->json('pasos_programados')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tours', function (Blueprint $table) {
            $table->dropColumn('pasos_programados');
        });
    }
};
