<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReservaStockPedidosWeb extends Migration
{
    public function up()
    {
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->boolean('reservar_stock_pedidos_web')->default(false);
            $table->unsignedTinyInteger('reserva_stock_erp_protocolo')->default(1);
            $table->unsignedTinyInteger('reserva_stock_tienda_protocolo')->default(0);
            $table->string('reserva_stock_tienda_url', 500)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            // Sin backfill: activar no compromete pedidos recibidos antes de esta opción.
            $table->boolean('reserva_stock_web')->default(false);
            $table->boolean('reserva_stock_web_consumida')->default(false);
            $table->json('reserva_stock_web_egreso')->nullable();
        });
        Schema::create('order_stock_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('article_variant_id')->nullable();
            $table->unsignedBigInteger('promocion_vinoteca_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->decimal('amount', 15, 4);
            $table->timestamps();
            $table->index('order_id');
            $table->index(['user_id', 'article_id', 'article_variant_id', 'address_id'], 'reserva_web_articulo');
            $table->index(['user_id', 'promocion_vinoteca_id'], 'reserva_web_promocion');
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_stock_reservations');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['reserva_stock_web', 'reserva_stock_web_consumida', 'reserva_stock_web_egreso']);
        });
        Schema::table('online_configurations', function (Blueprint $table) {
            $table->dropColumn(['reservar_stock_pedidos_web', 'reserva_stock_erp_protocolo', 'reserva_stock_tienda_protocolo', 'reserva_stock_tienda_url']);
        });
    }
}
