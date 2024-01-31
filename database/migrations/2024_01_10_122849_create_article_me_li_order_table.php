<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleMeLiOrderTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_me_li_order', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->integer('me_li_order_id');
            $table->decimal('price', 12,2);
            $table->decimal('amount', 12,2);
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
        Schema::dropIfExists('article_me_li_order');
    }
}
