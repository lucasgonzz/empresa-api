<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleRecipeRouteTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_recipe_route', function (Blueprint $table) {
            $table->id();


            $table->integer('recipe_route_id');
            $table->integer('article_id');

            $table->decimal('amount', 18, 4)->default(0);
            $table->integer('order_production_status_id')->nullable();
            $table->integer('address_id')->nullable();
            $table->text('notes')->nullable();

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
        Schema::dropIfExists('article_recipe_route');
    }
}
