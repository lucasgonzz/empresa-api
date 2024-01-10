<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePerformancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_performances', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->string('article_name')->nullable();
            $table->decimal('amount', 12,2)->nullable();
            $table->decimal('cost', 12,2)->nullable();
            $table->decimal('price', 12,2)->nullable();
            $table->integer('provider_id')->nullable();
            $table->integer('category_id')->nullable();
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
        Schema::dropIfExists('article_performances');
    }
}
