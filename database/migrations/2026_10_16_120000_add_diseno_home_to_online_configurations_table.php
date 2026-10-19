<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diseño de la portada (Inicio) de la tienda online, que elige cada comercio desde Configuracion
 * Online. Dos valores:
 *
 * - 'ultimos_ingresos': el inicio de siempre (Destacados, Novedades y Ultimos ingresos). Es el
 *   default de la columna, asi ningun comercio existente cambia de portada al actualizarse.
 * - 'lista_categorias': debajo del banner, todas las categorias en una lista desplegable con
 *   sus productos.
 *
 * empresa-api es dueno de este esquema porque el deploy de tienda no corre `migrate`: tienda-spa
 * (Home.vue) lee la columna a traves de tienda-api, que nunca la crea.
 */
class AddDisenoHomeToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna si falta. Misma guarda `hasColumn` que las ultimas migraciones de esta
     * tabla: tolera que la columna ya se haya agregado a mano en produccion antes del release, sin
     * cortar el ClientVersionUpgrade con "Duplicate column name".
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('online_configurations', 'diseno_home')) {
                $table->string('diseno_home', 40)->nullable()->default('ultimos_ingresos');
            }
        });
    }

    /**
     * Saca la columna solo si esta, para que el rollback no tire 1091 sobre una base donde no
     * llego a crearse.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (Schema::hasColumn('online_configurations', 'diseno_home')) {
                $table->dropColumn('diseno_home');
            }
        });
    }
}
