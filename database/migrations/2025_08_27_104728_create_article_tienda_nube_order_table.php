<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleTiendaNubeOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_tienda_nube_order', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->integer('tienda_nube_order_id');
            $table->decimal('price', 20,2)->nullable();
            $table->decimal('amount', 20,2)->nullable();

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
        Schema::dropIfExists('article_tienda_nube_order');
    }
}
