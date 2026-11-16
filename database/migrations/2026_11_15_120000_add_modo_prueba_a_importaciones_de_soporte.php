<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes y proveedores de la prueba gratuita por el mismo motor de soporte (misión prueba-e2e-campana-imperio,
 * ronda 8, D1): una corrida con `modo_prueba` se carga sola, sin código de confirmación.
 */
class AddModoPruebaAImportacionesDeSoporte extends Migration
{
    public function up()
    {
        if (Schema::hasTable('importaciones_de_soporte') && !Schema::hasColumn('importaciones_de_soporte', 'modo_prueba')) {
            Schema::table('importaciones_de_soporte', function (Blueprint $table) {
                $table->boolean('modo_prueba')->default(false)->after('avisar_por_whatsapp');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('importaciones_de_soporte') && Schema::hasColumn('importaciones_de_soporte', 'modo_prueba')) {
            Schema::table('importaciones_de_soporte', function (Blueprint $table) {
                $table->dropColumn('modo_prueba');
            });
        }
    }
}
