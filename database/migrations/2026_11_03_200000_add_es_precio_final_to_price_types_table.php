<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de precios «Precio final» (misión prueba-listas-siempre, M1 del programa «Prueba gratuita de
 * 7 días», 3/11/2026).
 *
 * Todo negocio pasa a trabajar con listas de precios (`users.listas_de_precio` siempre en 1). El que no
 * carga listas propias recibe UNA, «Precio final», que es el precio del artículo de siempre: su pivote
 * en `article_price_type` (y sus filas en `article_price_type_monedas`, en las cuentas con precios por
 * moneda) se escribe como ESPEJO de `articles.final_price` al final de `ArticleHelper::setFinalPrice()`,
 * así da el mismo número que daba el precio final sin listas: con el margen del proveedor, el de la
 * categoría, el del artículo, el precio manual, los recargos, los impuestos, el IVA y el redondeo.
 *
 * Esta columna es la MARCA de esa lista: 1 = es la lista «Precio final» del dueño (a lo sumo una por
 * dueño); NULL o 0 = una lista común, con la cadena de listas de siempre. Los consumidores comparan con 1
 * (`PriceTypeHelper::es_lista_precio_final()`), nunca con "!= 0".
 *
 * La base es compartida con `tienda`: `tienda-api` sigue leyendo `article_price_type.final_price` y no
 * mira esta columna. Es aditiva y con default: ninguna lista existente cambia de comportamiento al migrar.
 *
 * Guarda `hasColumn`: hay ~40 bases de clientes en estados de esquema distintos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class AddEsPrecioFinalToPriceTypesTable extends Migration
{
    /**
     * Agrega la columna si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('price_types') || Schema::hasColumn('price_types', 'es_precio_final')) {
            return;
        }

        Schema::table('price_types', function (Blueprint $table) {
            // 1 = la lista «Precio final» del dueño; NULL o 0 = lista común (ver el docblock).
            $table->boolean('es_precio_final')->nullable()->default(0);
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('price_types') || !Schema::hasColumn('price_types', 'es_precio_final')) {
            return;
        }

        Schema::table('price_types', function (Blueprint $table) {
            $table->dropColumn('es_precio_final');
        });
    }
}
