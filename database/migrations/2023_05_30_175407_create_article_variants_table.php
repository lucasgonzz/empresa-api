<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleVariantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_variants', function (Blueprint $table) {
            $table->id();
            $table->text('variant_description')->nullable();
            $table->text('image_url')->nullable();
            $table->integer('price')->nullable();   
            $table->integer('stock')->nullable();
            $table->integer('article_id')->unsigned();
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
        Schema::dropIfExists('article_variants');
    }
}
