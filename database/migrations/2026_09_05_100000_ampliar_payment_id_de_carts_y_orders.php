<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `carts.payment_id` y `orders.payment_id` pasan de INT a BIGINT.
 *
 * Ahi va el id del pago de Mercado Pago, que la tienda escribe cuando el comprador vuelve de
 * pagar (`tienda-api: CartController@update` -> `CartHelper::checkPaymentStatus`) y, desde la
 * mision `mercado-pago-cobro-demo`, tambien el webhook. Los ids de pago de Mercado Pago tienen hoy
 * 11-12 digitos (del orden de 1.2e11) y un INT con signo termina en 2.147.483.647: en MySQL 8 con
 * `STRICT_TRANS_TABLES` (el modo del VPS, medido el 5/9/2026) el UPDATE revienta con "Out of range
 * value", el `PUT /carts` post-pago responde 500 y el comprador ve "Recibimos tu pago, pero hubo un
 * problema al confirmar tu pedido" con la plata ya cobrada.
 *
 * Es aditivo y compatible en las dos direcciones: una tienda vieja sigue escribiendo enteros chicos
 * sin problema, y una base que ya tenga la columna en BIGINT (la demo, ampliada a mano el 5/9)
 * recibe un ALTER que la deja igual. `payments.payment_id` no se toca: ya es string.
 */
class AmpliarPaymentIdDeCartsYOrders extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->bigInteger('payment_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('payment_id')->nullable()->change();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->integer('payment_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->integer('payment_id')->nullable()->change();
        });
    }
}
