<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: "catálogo restringido en la tienda" de una lista de precios (misión
 * catalogo-por-lista-tienda, 5/10/2026, pedido de Ferretotal).
 *
 * Ferretotal vende a minoristas y a mayoristas con dos listas, pero solo una parte de sus
 * artículos quiere ofrecérselos a los mayoristas. Todos los artículos tienen precio en las dos
 * listas (el mayorista de un artículo "solo minorista" queda a costo), así que "tiene precio en la
 * lista" no sirve para decidir qué se muestra: hace falta una decisión explícita.
 *
 * Esta columna es el INTERRUPTOR de esa decisión, y vive en la lista:
 *
 *   NULL / 0  -> la lista NO está restringida. La tienda muestra a sus compradores todo el
 *                catálogo, exactamente como hasta hoy.
 *   1         -> la lista ESTÁ restringida. La tienda muestra a los compradores con esta lista
 *                SOLO los artículos habilitados para ella (`article_price_type.visible_en_tienda
 *                = 1`, ver la migración hermana).
 *
 * 🔴 Restringida significa `= 1`, y nada más. Los consumidores comparan con 1 y nunca con "!= 0":
 * el NULL (nunca se tocó) y el 0 (se destildó) tienen que dar el mismo comportamiento de siempre.
 *
 * La base es compartida con `tienda`: `tienda-api` lee esta columna para filtrar el catálogo, pero
 * detrás de una guarda de esquema (si la columna no existe, no filtra nada). Es aditiva y NULL por
 * defecto: ninguna lista existente cambia de comportamiento al migrar.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna (parche a mano) no puede tumbar la migración.
 */
class AddCatalogoRestringidoEnTiendaToPriceTypesTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('price_types', 'catalogo_restringido_en_tienda')) {
            return;
        }

        Schema::table('price_types', function (Blueprint $table) {
            // 1 = restringida; NULL o 0 = sin restricción (ver el docblock).
            $table->boolean('catalogo_restringido_en_tienda')->nullable()->default(null);
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('price_types', 'catalogo_restringido_en_tienda')) {
            return;
        }

        Schema::table('price_types', function (Blueprint $table) {
            $table->dropColumn('catalogo_restringido_en_tienda');
        });
    }
}
