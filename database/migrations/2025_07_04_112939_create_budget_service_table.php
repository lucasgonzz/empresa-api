<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetServiceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('budget_service', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            
            $table->id();

            $table->integer('budget_id')->unsigned();
            $table->integer('service_id')->unsigned();
            $table->decimal('price', 12,2);
            $table->integer('amount');
            $table->integer('returned_amount')->nullable();
            $table->decimal('discount', 8,2)->nullable();

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
        Schema::dropIfExists('budget_service');
    }
}
