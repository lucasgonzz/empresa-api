<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('budget_products', function (Blueprint $table) {
            $table->id();
            $table->string('bar_code')->nullable();
            $table->integer('amount');
            $table->text('name');
            $table->decimal('price', 8,2);
            $table->decimal('bonus', 8,2)->nullable();
            $table->text('location')->nullable();
            $table->integer('budget_id')->unsigned();
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
        Schema::dropIfExists('budget_products');
    }
}
