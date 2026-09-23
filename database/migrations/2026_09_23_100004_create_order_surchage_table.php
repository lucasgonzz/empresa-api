<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recargos del cliente con los que se priceó un pedido de la tienda (misión
 * descuentos-recargos-por-cliente, 23/9/2026).
 *
 * Gemela de `discount_order`. `percentage` es la foto del porcentaje al crear el pedido, con el
 * mismo tipo que `sale_surchage.percentage` (decimal 12,2).
 *
 * Sin foreign keys físicas, como todo el esquema, y con guard `hasTable` para que sea segura de
 * re-ejecutar. El nombre sigue la convención de Laravel para `belongsToMany` (orden alfabético,
 * singular): así la relación no necesita segundo argumento. Ojo que `tienda-api` NO tiene
 * migraciones y lee esta tabla por nombre: renombrarla rompe la tienda en silencio.
 */
class CreateOrderSurchageTable extends Migration
{
    /**
     * Crea la tabla si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('order_surchage')) {
            return;
        }

        Schema::create('order_surchage', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->unsigned()->index();
            $table->integer('surchage_id')->unsigned();
            $table->decimal('percentage', 12, 2);
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
        Schema::dropIfExists('order_surchage');
    }
}
