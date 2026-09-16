<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_combo` — el combo comprado, ya en el pedido
 * (misión combos-y-rangos-de-precio, 16/9/2026).
 *
 * Gemela de `cart_combo`, y por el mismo motivo que `order_promocion_vinoteca` es gemela de
 * `cart_promocion_vinoteca`: al confirmar la compra, el carrito se vuelca al pedido y el precio
 * deja de ser "el vigente" para pasar a ser "el que se cobró". Por eso el precio se copia acá y no
 * se vuelve a calcular: un pedido viejo tiene que seguir mostrando lo que el comprador pagó, aunque
 * el dueño cambie el precio del combo al día siguiente.
 *
 * 🔴 Esta tabla es el punto donde el ecommerce y el ERP se cruzan de verdad: el pedido de la tienda
 * lo confirma después el dueño desde empresa, y ahí el combo tiene que poder viajar a la venta.
 * Mientras esta misión no toque esa confirmación, un pedido con combos se ve entero en la tienda y
 * en el listado de pedidos; lo que NO hace todavía es convertirse solo en una venta con combos.
 *
 * Misma forma que `cart_combo` y que las dos tablas de promociones: amount, price, cost nullable y
 * notes. Ver el comentario de `cart_combo` sobre por qué las columnas son literalmente las mismas.
 */
class CreateOrderComboTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('order_combo')) {
            Schema::create('order_combo', function (Blueprint $table) {
                $table->id();
                $table->integer('order_id')->unsigned();
                $table->integer('combo_id')->unsigned();
                $table->double('amount', 20,2);
                $table->double('price', 20,2);
                $table->double('cost', 20,2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
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
        Schema::dropIfExists('order_combo');
    }
}
