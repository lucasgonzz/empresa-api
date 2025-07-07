<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePdfObservationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_pdf_observations', function (Blueprint $table) {
            $table->id();

            $table->text('text')->nullable();
            $table->string('color')->nullable();
            $table->string('background')->nullable();
            $table->text('image_url')->nullable();
            $table->integer('position');
            $table->integer('user_id');            

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
        Schema::dropIfExists('article_pdf_observations');
    }
}
