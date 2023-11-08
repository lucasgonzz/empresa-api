<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionMovementsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('production_movements', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->integer('employee_id')->nullable();
            $table->integer('article_id');
            $table->integer('order_production_status_id');
            $table->decimal('amount', 12,2);
            $table->decimal('current_amount', 12,2)->nullable();
            $table->integer('user_id');
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
        Schema::dropIfExists('production_movements');
    }
}
