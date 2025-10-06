<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleMeliAttributeTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_meli_attribute', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id')->nullable();
            $table->integer('meli_attribute_id')->nullable();
            $table->integer('meli_attribute_value_id')->nullable();
            $table->text('value_id')->nullable();
            $table->text('value_name')->nullable();

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
        Schema::dropIfExists('article_meli_attribute');
    }
}
