<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuentos del cliente con los que se priceó un pedido de la tienda (misión
 * descuentos-recargos-por-cliente, 23/9/2026).
 *
 * La escribe `tienda-api` al crear el pedido y la lee el ERP: la muestra en el pedido y, al
 * confirmarlo, se los pasa a la venta. `percentage` es la FOTO del porcentaje al momento del
 * pedido, igual que `discount_sale.percentage` (mismo tipo, double): si después alguien cambia o
 * borra el descuento, el pedido y su venta tienen que seguir cuadrando con lo que se cobró.
 *
 * Sin foreign keys físicas, como todo el esquema, y con guard `hasTable` para que sea segura de
 * re-ejecutar. El nombre sigue la convención de Laravel para `belongsToMany` (orden alfabético,
 * singular): así la relación no necesita segundo argumento. Ojo que `tienda-api` NO tiene
 * migraciones y lee esta tabla por nombre: renombrarla rompe la tienda en silencio.
 */
class CreateDiscountOrderTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('discount_order')) {
            return;
        }

        Schema::create('discount_order', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->unsigned()->index();
            $table->integer('discount_id')->unsigned();
            $table->double('percentage');
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('discount_order');
    }
}
