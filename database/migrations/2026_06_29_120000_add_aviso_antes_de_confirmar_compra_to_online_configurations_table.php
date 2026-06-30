<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto enriquecido configurable desde empresa para mostrar en tienda
 * antes de confirmar la compra (pantalla confirmar-compra).
 */
class AddAvisoAntesDeConfirmarCompraToOnlineConfigurationsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->text('aviso_antes_de_confirmar_compra')->nullable()->after('quienes_somos');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn('aviso_antes_de_confirmar_compra');
        });
    }
}
