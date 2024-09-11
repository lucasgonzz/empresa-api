<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleDiscountBlancosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_discount_blancos', function (Blueprint $table) {
            $table->id();
            
            $table->integer('article_id')->unsigned()->nullable();
            $table->decimal('percentage');
            $table->string('temporal_id')->nullable();
            $table->boolean('show_in_online')->default(0);
            
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
        Schema::dropIfExists('article_discount_blancos');
    }
}
