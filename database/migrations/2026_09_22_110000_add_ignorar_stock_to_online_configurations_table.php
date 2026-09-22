<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decide si la tienda online ignora el stock real de los articulos: con el flag prendido, ningun
 * articulo se muestra como agotado ni tiene tope de cantidad al comprar, sea cual sea su stock
 * cargado (mismo criterio que ya existe hoy para un articulo con stock null, ver
 * `hasStock()` en `src/mixins/articles.js` de tienda-spa).
 *
 * Arranca APAGADO, al reves que mostrar_stock_disponible: ese toggle preservaba el comportamiento
 * de siempre (el texto de stock disponible ya se mostraba para todos). Este es al reves — un
 * comportamiento nuevo y mas agresivo (vender sin controlar stock) — asi que tiene que nacer en
 * false para que ninguno de los ~40-50 clientes existentes empiece a vender sin control de stock
 * solo por actualizarse.
 *
 * empresa-api es dueno de este esquema porque el deploy de tienda no corre `migrate`
 * (arquitectura_tecnica.md:470): tienda-api solo lee esta columna, nunca la crea.
 */
class AddIgnorarStockToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna si falta. La guarda `hasColumn` tolera que ya se haya agregado a mano en
     * produccion antes de que llegue el release (ver `parche-manual-en-produccion-de-truvari` en
     * el repo de conocimiento) y tambien el caso del cliente migrado con la tabla `migrations`
     * desalineada. Sin la guarda, la migracion corta con "Duplicate column name", deja el
     * ClientVersionUpgrade a medias y el reintento vuelve a morir en el mismo punto, con un error
     * que no nombra la causa.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('online_configurations', 'ignorar_stock')) {
                $table->boolean('ignorar_stock')->default(0);
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
            if (Schema::hasColumn('online_configurations', 'ignorar_stock')) {
                $table->dropColumn('ignorar_stock');
            }
        });
    }
}
