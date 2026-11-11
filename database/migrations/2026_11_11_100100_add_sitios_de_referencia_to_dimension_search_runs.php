<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suma `sitios_de_referencia` a `dimension_search_runs` (misión medidas-sitios-de-referencia, 11/11/2026): la
 * FOTO de la lista de sitios de referencia del comercio con la que se lanzó la corrida (lista JSON de dominios).
 *
 * Es una foto a propósito: si el comercio cambia su lista a mitad de una corrida, la corrida sigue buscando con la
 * que tenía al lanzarse, y el detalle muestra con cuáles se buscó. Null = se lanzó sin sitios (o con una versión
 * vieja): el motor no arma el plan de sitios.
 *
 * Nullable: compatible con el código anterior, que no la escribe ni la lee.
 */
class AddSitiosDeReferenciaToDimensionSearchRuns extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('dimension_search_runs') || Schema::hasColumn('dimension_search_runs', 'sitios_de_referencia')) {
            return;
        }

        Schema::table('dimension_search_runs', function (Blueprint $table) {
            $table->json('sitios_de_referencia')->nullable();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('dimension_search_runs') && Schema::hasColumn('dimension_search_runs', 'sitios_de_referencia')) {
            Schema::table('dimension_search_runs', function (Blueprint $table) {
                $table->dropColumn('sitios_de_referencia');
            });
        }
    }
}
