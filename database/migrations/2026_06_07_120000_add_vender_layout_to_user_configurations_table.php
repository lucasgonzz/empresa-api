<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columnas para persistir el ancho personalizado de los paneles
 * izquierdo y derecho del módulo de vender por usuario.
 * Ambas columnas son nullable para no romper registros existentes.
 */
class AddVenderLayoutToUserConfigurationsTable extends Migration
{
    /**
     * Agrega vender_left_width y vender_right_width a user_configurations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_configurations', function (Blueprint $table) {
            // Ancho en píxeles del panel izquierdo del módulo de vender
            $table->integer('vender_left_width')->nullable()->after('apply_price_type_in_services');

            // Ancho en píxeles del panel derecho del módulo de vender
            $table->integer('vender_right_width')->nullable()->after('vender_left_width');
        });
    }

    /**
     * Elimina las columnas de ancho de layout de vender.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_configurations', function (Blueprint $table) {
            $table->dropColumn(['vender_left_width', 'vender_right_width']);
        });
    }
}
