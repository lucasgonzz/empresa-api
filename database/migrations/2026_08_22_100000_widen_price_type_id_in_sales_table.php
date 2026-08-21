<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 BLOQUEANTE del sistema de puntos para clientes: ensancha `sales.price_type_id`
 * de TINYINT(1) a INT UNSIGNED.
 *
 * La columna nació como `$table->boolean('price_type_id')->nullable()` en
 * 2019_10_24_003026_create_sales_table.php:29, o sea tinyint(1): aguantaba hasta la
 * lista de precio N° 127 y a partir de ahí guardaba cualquier cosa (o 127, o 0, o un
 * error, según el sql_mode de la base del cliente).
 *
 * Es la clase de error "columna derivada con tipo más angosto que su fuente"
 * (contexto/APRENDER_NO_PARCHEAR.md:201). Acá la fuente son DOS, y las dos son más
 * anchas que la columna que las copia:
 *
 *   - `price_types.id`         -> bigint unsigned. Es el id que manda VENDER en el POST
 *                                 (SaleController@store:200).
 *   - `clients.price_type_id`  -> int unsigned. Es el fallback cuando la venta viene sin
 *                                 lista (SaleController@store:238-243), o sea que esta
 *                                 columna literalmente le copia el valor a aquella.
 *
 * POR QUÉ INT UNSIGNED Y NO BIGINT UNSIGNED: espeja exactamente `clients.price_type_id`,
 * que es la columna que le pasa el valor en el fallback, y su techo (4.294.967.295) está
 * fuera de cualquier rango que `price_types` pueda alcanzar en un comercio. La fuente
 * última es bigint unsigned; se para en INT UNSIGNED a propósito, para no duplicar bytes
 * en una columna de `sales`, que es la tabla más caliente y más grande del sistema. Si se
 * quisiera uniformidad total con la fuente última, es cambiar una palabra en este archivo.
 *
 * Consumidores de la columna, que también se arreglan con esto:
 *   - ContabilidadRepository::query_ventas_brutas():172 -> filtro por lista del Estado de
 *     Resultados. Los valores <= 127 quedan idénticos, así que el reporte no cambia.
 *   - Sale::price_type() (app/Models/Sale.php:92).
 *   - El motor de puntos, que usa esta columna para decidir si un renglón cobra o no
 *     (PuntosBaseHelper::lista_efectiva_del_renglon()).
 *
 * `sales` no tiene foreign keys físicas (ninguna tabla de este schema las tiene), así que
 * el ALTER no arrastra constraints. ⚠️ El ALTER bloquea escrituras mientras corre y en un
 * cliente real `sales` puede ser grande: va con el resto de las migraciones, en la ventana
 * que ya corre el pipeline.
 */
class WidenPriceTypeIdInSalesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /*
         * ALTER crudo y NO $table->unsignedInteger('price_type_id')->change(): doctrine/dbal
         * está instalado (composer.json:11), pero el ->change() de Laravel reconstruye la
         * definición completa de la columna a partir de lo que se le declare y se come lo que
         * no se repita. Es el mismo criterio ya escrito en
         * 2026_07_30_120000_change_iva_percentage_to_string_in_pivots.php:32-37, que es el
         * precedente directo de esta clase de arreglo en este repo.
         */
        DB::statement("ALTER TABLE `sales` MODIFY `price_type_id` INT UNSIGNED NULL");
    }

    /**
     * Reverse the migrations.
     *
     * ⚠️ Salida de emergencia, no operación rutinaria: la vuelta atrás PIERDE información.
     * Una venta con una lista de precio de id > 127 no tiene representación en tinyint(1),
     * así que se pasa a NULL antes de angostar, igual que hace
     * 2026_07_30_120000_change_iva_percentage_to_string_in_pivots.php con los IVA no numéricos.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("UPDATE `sales` SET `price_type_id` = NULL WHERE `price_type_id` > 127");
        DB::statement("ALTER TABLE `sales` MODIFY `price_type_id` TINYINT(1) NULL");
    }
}
