<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleBudgetTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_budget', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10,2)->nullable();
            $table->decimal('price', 12,2)->nullable();
            $table->decimal('cost', 12,2)->nullable();
            $table->decimal('bonus', 10,2)->nullable();
            $table->string('location')->nullable();
            $table->integer('price_type_personalizado_id')->nullable();

            $table->integer('article_id')->unsigned();
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
        Schema::dropIfExists('article_budget');
    }
}
