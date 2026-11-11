<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AgregarDetalleDeListasAPriceChanges extends Migration
{
    public function up()
    {
        Schema::table('price_changes', function (Blueprint $table) {
            $table->decimal('precio_final_anterior', 22, 2)->nullable();
            $table->json('cambios_listas')->nullable();
            $table->string('origen', 100)->nullable();
            $table->uuid('operacion_uuid')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('price_changes', function (Blueprint $table) {
            $table->dropIndex(['operacion_uuid']);
            $table->dropColumn(['precio_final_anterior', 'cambios_listas', 'origen', 'operacion_uuid']);
        });
    }
}
