<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `combo_price_type` — el precio de un combo calculado en cada lista de precios
 * (misión combos-calculados, 30/9/2026).
 *
 * Es el gemelo de `article_price_type` para los combos: una fila por (combo, lista) con el precio
 * de venta del combo en esa lista, YA con el descuento del combo aplicado. Solo se llena para los
 * combos con `calcular_desde_articulos = 1` en cuentas que usan listas de precio; un combo manual,
 * o una cuenta sin listas, no tiene filas acá.
 *
 * 🔴 `combos.price` SIGUE SIENDO EL PRECIO DE LA LISTA POR DEFECTO (la de mayor `position`, la
 * misma que elige `ArticlePricesHelper::resolver_precio_de_venta()`), y esta tabla es un agregado,
 * no un reemplazo. Es lo que hace que la convivencia funcione en las dos direcciones: una tienda
 * (o un SPA) que todavía no conoce `combo_price_type` lee `combos.price` como siempre y anda; una
 * que sí la conoce busca primero la fila de la lista del comprador, y si no hay fila cae al mismo
 * `combos.price`. Quien "simplifique" esto sacando `combos.price` o haciéndolo depender de esta
 * tabla rompe todas las tiendas que se despliegan a mano un tiempo después que el ERP.
 *
 * Sin foreign keys (regla del repo: las bases de los clientes se migran por SSH y una FK mal
 * ordenada frena un upgrade entero) y con índices de nombre corto por columna de búsqueda: por
 * combo (para leer sus precios) y por lista (para limpiar las filas de una lista que se borra).
 * Los tipos de `combo_id` y `price_type_id` son los del contrato con tienda-api, que lee esta
 * tabla sin tener migraciones propias.
 *
 * `price` DECIMAL(22,2) igual que `article_price_type.final_price`: el precio de un combo suma
 * varios artículos y en una cuenta con inflación se pasa rápido de los 10 dígitos de
 * `combos.price`.
 */
class CreateComboPriceTypeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('combo_price_type')) {
            Schema::create('combo_price_type', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->integer('combo_id')->unsigned();
                $table->integer('price_type_id')->unsigned();
                $table->decimal('price', 22, 2);
                $table->timestamps();

                $table->index('combo_id', 'cpt_combo_idx');
                $table->index('price_type_id', 'cpt_price_type_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('combo_price_type');
    }
}
