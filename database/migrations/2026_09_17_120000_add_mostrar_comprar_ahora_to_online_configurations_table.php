<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decide si la ficha del articulo de la tienda online muestra el boton "Comprar ahora" (agrega
 * el articulo al carrito y lleva directo a terminar la compra) ademas de "Agregar al carrito".
 *
 * Arranca APAGADO por decision de Lucas (17/9/2026): la mayoria de las tiendas quiere una sola
 * accion en la ficha, y el atajo al pago se prende a pedido. Ojo que eso significa que toda
 * tienda que se actualice deja de mostrar el boton hasta que su dueno lo prenda.
 *
 * empresa-api es dueno de este esquema porque el deploy de tienda no corre `migrate`
 * (arquitectura_tecnica.md:470): tienda-api solo lee esta columna, nunca la crea.
 */
class AddMostrarComprarAhoraToOnlineConfigurationsTable extends Migration
{
    /**
     * Agrega la columna si falta. La guarda `hasColumn` tolera que ya se haya agregado a mano en
     * produccion antes de que llegue el release (pasó de verdad, ver
     * `parche-manual-en-produccion-de-truvari` en el repo de conocimiento) — y tambien el caso
     * del cliente migrado con la tabla `migrations` desalineada. Sin la guarda, la migracion
     * corta con "Duplicate column name", deja el ClientVersionUpgrade a medias y el reintento
     * vuelve a morir en el mismo punto, con un error que no nombra la causa.
     *
     * Mismo criterio que `2026_09_16_120000_add_envio_zipcode_to_buyers_table.php`. Las 16
     * migraciones mas viejas de esta misma tabla no la tienen, pero son todas anteriores a que
     * la convencion se adoptara (agosto de 2026): la vecina vieja no es un argumento.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('online_configurations', 'mostrar_comprar_ahora')) {
                $table->boolean('mostrar_comprar_ahora')->default(0)->nullable();
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
            if (Schema::hasColumn('online_configurations', 'mostrar_comprar_ahora')) {
                $table->dropColumn('mostrar_comprar_ahora');
            }
        });
    }
}
