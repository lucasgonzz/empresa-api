<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTitlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->text('header')->nullable();
            $table->text('lead')->nullable();
            $table->string('image_url')->nullable();
            $table->string('hosting_image_url')->nullable();
            $table->string('crop_image_url')->nullable();
            $table->string('text_color')->nullable();
            $table->string('color')->nullable();
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('titles');
    }
}
