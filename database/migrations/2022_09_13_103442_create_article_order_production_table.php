<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleOrderProductionTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_order_production', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10,2)->nullable();
            $table->decimal('price', 12,2)->nullable();
            $table->decimal('bonus', 10,2)->nullable();
            $table->string('location')->nullable();
            $table->integer('delivered')->nullable();
            $table->integer('employee_id')->unsigned()->nullable();

            $table->integer('article_id')->unsigned();
            $table->integer('order_production_id')->unsigned();
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
        Schema::dropIfExists('article_order_production');
    }
}
