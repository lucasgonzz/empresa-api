<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: la VARIANTE de cada artículo devuelto en una nota de crédito (misión
 * variantes-mismo-articulo-en-vender, 8/10/2026).
 *
 * Hasta hoy el pivot de la NC (`article_current_acount`) guardaba artículo, cantidad y precio, pero
 * no la variante. En una venta con el mismo artículo en dos variantes (Remera M y Remera L), al
 * borrar la NC no había forma de saber QUÉ variante se había devuelto: se deshacía sobre el renglón
 * con más unidades devueltas, que podía ser el de la otra variante.
 *
 * NULL = sin variante (o una NC anterior a esta columna, que sigue con el criterio de siempre al
 * borrarse: ver NotaCreditoHelper::resetUnidadesDevueltas). Sin FK, como el resto de las columnas de
 * variante del sistema. El índice es corto a propósito: el nombre automático supera el límite de
 * MySQL en algunas bases.
 *
 * La base es compartida con `tienda`, pero `tienda-api` no lee `article_current_acount` (verificado
 * en el plan de la misión). Es aditiva y NULL por defecto: ninguna fila existente cambia.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna no puede tumbar la migración. El código que la escribe o la lee va detrás de
 * VarianteEnNotaCreditoEsquemaHelper, para la ventana del deploy en la que la columna todavía no
 * existe.
 */
class AddArticleVariantIdToArticleCurrentAcountTable extends Migration
{
    /**
     * Agrega la columna (y su índice) si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('article_current_acount', 'article_variant_id')) {
            return;
        }

        Schema::table('article_current_acount', function (Blueprint $table) {
            // La variante devuelta; NULL = sin variante o NC anterior a esta columna.
            $table->unsignedBigInteger('article_variant_id')->nullable()->default(null);
            $table->index('article_variant_id', 'aca_variant_idx');
        });
    }

    /**
     * Saca la columna (y su índice) si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('article_current_acount', 'article_variant_id')) {
            return;
        }

        Schema::table('article_current_acount', function (Blueprint $table) {
            $table->dropIndex('aca_variant_idx');
            $table->dropColumn('article_variant_id');
        });
    }
}
