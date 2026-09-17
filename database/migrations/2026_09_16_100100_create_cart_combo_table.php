<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cart_combo` — un combo adentro del carrito de la tienda
 * (misión combos-y-rangos-de-precio, 16/9/2026).
 *
 * El ecommerce ya sabe llevar dos colecciones en el carrito: `article_cart` y
 * `cart_promocion_vinoteca`. Los combos son la tercera, y se modelan igual que las promociones de
 * vinoteca porque en el carrito se comportan igual: una línea con su cantidad y el precio
 * congelado al momento de agregarla.
 *
 * Las cuatro columnas de datos son EXACTAMENTE las de `cart_promocion_vinoteca` (amount, price,
 * cost, notes), y no es por simetría estética: `CartHelper::set_total()` de tienda-api suma las
 * colecciones del carrito con el mismo bucle sobre `pivot->price * pivot->amount`, y el SPA arma
 * el ítem local con la misma forma `pivot.{price, amount, notes}`. Una forma distinta obligaría a
 * un camino aparte en cada uno de esos puntos, que es justo donde el total se cayó el 14/9/2026
 * (el `total()` del invitado sumaba `articles` y se olvidaba de `promociones_vinoteca`).
 *
 * 🔴 Esta tabla la crea empresa-api y la escribe tienda-api. `tienda-api` no tiene
 * `database/migrations` y su deploy no corre `migrate`: TODO el esquema del ecommerce viaja por
 * este repo. El lector de la tienda consulta `Schema::hasTable` antes de tocarla, así que una
 * tienda desplegada antes de que llegue esta migración simplemente no ofrece combos.
 *
 * `cost` queda nullable y sin escribir por ahora, igual que en `cart_promocion_vinoteca`: la
 * columna existe para que el día que se quiera medir margen del pedido no haga falta otra
 * migración.
 */
class CreateCartComboTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('cart_combo')) {
            Schema::create('cart_combo', function (Blueprint $table) {
                $table->id();
                $table->integer('cart_id')->unsigned();
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
        Schema::dropIfExists('cart_combo');
    }
}
