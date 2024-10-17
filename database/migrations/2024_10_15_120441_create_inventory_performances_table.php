<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInventoryPerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inventory_performances', function (Blueprint $table) {
            $table->id();

            $table->integer('cantidad_articulos')->nullable();
            $table->integer('stockeados')->nullable();
            $table->integer('sin_stockear')->nullable();
            $table->decimal('porcentaje_stockeado', 5,2)->nullable();


            $table->decimal('valor_inventario_en_costos', 30,2)->nullable();
            $table->decimal('valor_inventario_en_precios', 30,2)->nullable();

    
            $table->integer('articulos_con_costos')->nullable();
            $table->integer('articulos_sin_costos')->nullable();
            $table->integer('porcentaje_con_costos')->nullable();

    
            $table->integer('sin_stock')->nullable();
            $table->integer('stock_minimo')->nullable();

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
        Schema::dropIfExists('inventory_perfomances');
    }
}
