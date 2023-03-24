<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleOrderProductionFinishedTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_order_production_finished', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->integer('order_production_id');
            $table->integer('amount')->nullable();
            $table->integer('order_production_status_id')->nullable();
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
        Schema::dropIfExists('article_order_production_finished');
    }
}
