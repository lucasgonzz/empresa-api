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
            if (!Schema::hasColumn('support_tours', 'import_history_id')) {
                $table->unsignedBigInteger('import_history_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tours', function (Blueprint $table) {
            $table->dropColumn('import_history_id');
        });
    }
};
