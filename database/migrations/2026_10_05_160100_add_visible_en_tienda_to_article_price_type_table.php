<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: "visible en la tienda" de un artículo para UNA lista de precios (misión
 * catalogo-por-lista-tienda, 5/10/2026, pedido de Ferretotal).
 *
 * Es la mitad por-artículo de la decisión que describe la migración hermana
 * (`price_types.catalogo_restringido_en_tienda`). Una fila del pivote es un par (artículo, lista):
 *
 *   1         -> el artículo está HABILITADO para esa lista: si la lista es restringida, los
 *                compradores con esa lista lo ven en la tienda.
 *   NULL / 0  -> el artículo NO está habilitado para esa lista. Si la lista es restringida, esos
 *                compradores no lo ven (ni en el listado, ni en la ficha, ni lo pueden comprar).
 *
 * Si la lista NO es restringida, esta columna se ignora por completo.
 *
 * 🔴 Habilitado significa `= 1`, y nada más. Los consumidores comparan con 1 y nunca con "!= 0".
 *
 * 🔴 NULL por defecto, a propósito y sin default 0: es lo que da "los artículos nacen sin
 * habilitar" (decisión de Lucas, 5/10/2026) en TODOS los caminos que crean filas del pivote
 * —alta desde la ficha, importación con INSERT crudo, recálculo de precios, MercadoLibre— sin
 * tener que tocar a ninguno. Un camino que no menciona la columna deja la fila deshabilitada.
 *
 * El pivote NO tiene índice único por (article_id, price_type_id): puede haber filas duplicadas
 * por par. La tienda pregunta "¿existe una fila habilitada?", así que un duplicado habilitado
 * alcanza para mostrar el artículo. El índice por `article_id` (migración del 28/9/2026) es el que
 * hace barata esa pregunta.
 *
 * La base es compartida con `tienda`: `tienda-api` lee esta columna para filtrar el catálogo, pero
 * detrás de una guarda de esquema (si la columna no existe, no filtra nada). Es aditiva y NULL por
 * defecto: ninguna fila existente cambia de comportamiento al migrar.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos y una que ya tenga
 * la columna (parche a mano) no puede tumbar la migración.
 */
class AddVisibleEnTiendaToArticlePriceTypeTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('article_price_type', 'visible_en_tienda')) {
            return;
        }

        Schema::table('article_price_type', function (Blueprint $table) {
            // 1 = habilitado para esa lista; NULL o 0 = no habilitado (ver el docblock).
            $table->boolean('visible_en_tienda')->nullable()->default(null);
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('article_price_type', 'visible_en_tienda')) {
            return;
        }

        Schema::table('article_price_type', function (Blueprint $table) {
            $table->dropColumn('visible_en_tienda');
        });
    }
}
