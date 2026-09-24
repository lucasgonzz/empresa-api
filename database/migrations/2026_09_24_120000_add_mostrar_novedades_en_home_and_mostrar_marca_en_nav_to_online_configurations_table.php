<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decide si la tienda online muestra (1) la seccion "Novedades" del home y (2) el item "Marca" de
 * la barra de navegacion.
 *
 * Los dos arrancan PRENDIDOS: hoy en tienda no existe ningun toggle para esto, las dos cosas
 * siempre se ven. Las columnas nacen en true para que ningun comercio existente cambie de
 * comportamiento al actualizarse.
 *
 * empresa-api es dueno de este esquema porque el deploy de tienda no corre `migrate`
 * (arquitectura_tecnica.md:470): tienda-api solo lee estas columnas, nunca las crea.
 */
class AddMostrarNovedadesEnHomeAndMostrarMarcaEnNavToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega cada columna si falta. La guarda `hasColumn` tolera que ya se haya agregado a mano en
     * produccion antes de que llegue el release (pasó de verdad, ver
     * `parche-manual-en-produccion-de-truvari` en el repo de conocimiento) — y tambien el caso
     * del cliente migrado con la tabla `migrations` desalineada. Sin la guarda, la migracion
     * corta con "Duplicate column name", deja el ClientVersionUpgrade a medias y el reintento
     * vuelve a morir en el mismo punto, con un error que no nombra la causa.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('online_configurations', 'mostrar_novedades_en_home')) {
                $table->boolean('mostrar_novedades_en_home')->default(1);
            }
            if (!Schema::hasColumn('online_configurations', 'mostrar_marca_en_nav')) {
                $table->boolean('mostrar_marca_en_nav')->default(1);
            }
        });
    }

    /**
     * Saca cada columna solo si esta. Sin la guarda, un `down()` sobre una base donde la columna
     * no llego a crearse tira 1091 y deja el rollback a medias.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (Schema::hasColumn('online_configurations', 'mostrar_novedades_en_home')) {
                $table->dropColumn('mostrar_novedades_en_home');
            }
            if (Schema::hasColumn('online_configurations', 'mostrar_marca_en_nav')) {
                $table->dropColumn('mostrar_marca_en_nav');
            }
        });
    }
}
