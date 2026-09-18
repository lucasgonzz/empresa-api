<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asocia el tracking de progreso (`import_statuses`/`import_histories`, ya genéricos vía
 * `model_name`) a la compra concreta que se está importando. Sin esto, el frontend no tiene forma
 * de distinguir "esta actualización de status es de MI compra" cuando hay más de un tipo de import
 * en juego (misión `import-excel-compras-chunks`, 14/9/2026).
 *
 * Sin foreign(): regla del workspace para migraciones nuevas.
 */
class AddProviderOrderIdToImportTables extends Migration
{
    public function up()
    {
        Schema::table('import_statuses', function (Blueprint $table) {
            $table->integer('provider_order_id')->nullable();
        });

        Schema::table('import_histories', function (Blueprint $table) {
            $table->integer('provider_order_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('import_statuses', function (Blueprint $table) {
            $table->dropColumn('provider_order_id');
        });

        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn('provider_order_id');
        });
    }
}
