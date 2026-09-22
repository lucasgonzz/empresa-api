<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decide si la ficha del articulo de la tienda online muestra el texto "Stock disponible" y la
 * cantidad de unidades disponibles.
 *
 * Arranca PRENDIDO: hoy en tienda no existe ningun toggle para esto, el texto de stock disponible
 * siempre se muestra. La columna nace en true para que ningun comercio existente cambie de
 * comportamiento al actualizarse.
 *
 * empresa-api es dueno de este esquema porque el deploy de tienda no corre `migrate`
 * (arquitectura_tecnica.md:470): tienda-api solo lee esta columna, nunca la crea.
 */
class AddMostrarStockDisponibleToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna si falta. La guarda `hasColumn` tolera que ya se haya agregado a mano en
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
            if (!Schema::hasColumn('online_configurations', 'mostrar_stock_disponible')) {
                $table->boolean('mostrar_stock_disponible')->default(1);
            }
        });
    }

    /**
     * Saca la columna solo si esta. Sin la guarda, un `down()` sobre una base donde la columna no
     * llego a crearse tira 1091 y deja el rollback a medias.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (Schema::hasColumn('online_configurations', 'mostrar_stock_disponible')) {
                $table->dropColumn('mostrar_stock_disponible');
            }
        });
    }
}
