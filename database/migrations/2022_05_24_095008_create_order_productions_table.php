<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderProductionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_productions', function (Blueprint $table) {
            $table->id();
            // $table->integer('budget_id')->unsigned()->nullable();
            $table->integer('num')->nullable();
            $table->integer('client_id')->unsigned()->nullable();
            $table->integer('order_production_status_id')->unsigned();

            $table->string('pdf')->nullable();
            $table->text('observations')->nullable();
            
            $table->timestamp('start_at')->nullable();
            $table->timestamp('finish_at')->nullable();
            $table->boolean('finished')->default(0);
            $table->integer('budget_id')->nullable();
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('order_productions');
    }
}
