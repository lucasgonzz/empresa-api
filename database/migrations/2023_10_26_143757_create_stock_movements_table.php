<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStockMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // $table->string('temporal_id')->nullable();
            $table->integer('concepto_stock_movement_id')->nullable();
            $table->integer('article_id')->nullable();
            $table->integer('from_address_id')->nullable();
            $table->integer('to_address_id')->nullable();
            $table->integer('article_variant_id')->nullable();
            $table->integer('provider_id')->nullable();
            $table->integer('deposit_movement_id')->nullable();
            $table->integer('provider_order_id')->nullable();
            $table->integer('sale_id')->nullable();
            $table->integer('nota_credito_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->text('concepto')->nullable();
            $table->text('observations')->nullable();
            $table->decimal('amount', 12,2)->nullable();
            $table->decimal('stock_resultante', 12,2)->nullable();
            $table->integer('employee_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('stock_movements');
    }
}
