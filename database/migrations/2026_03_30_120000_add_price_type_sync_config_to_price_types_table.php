<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPriceTypeSyncConfigToPriceTypesTable extends Migration
{
    /**
     * Agrega configuración persistente para sincronización masiva.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('price_types', function (Blueprint $table) {
            // Define si al crear el tipo de precio se debe aplicar a artículos existentes.
            $table->boolean('apply_percentage_on_existing_articles')
                ->default(1)
                ->after('percentage');

            // Define la estrategia de actualización cuando cambia el porcentaje por defecto.
            $table->string('update_existing_articles_percentage_mode', 40)
                ->default('none')
                ->after('apply_percentage_on_existing_articles');
        });
    }

    /**
     * Revierte la configuración de sincronización masiva.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('price_types', function (Blueprint $table) {
            $table->dropColumn('apply_percentage_on_existing_articles');
            $table->dropColumn('update_existing_articles_percentage_mode');
        });
    }
}
