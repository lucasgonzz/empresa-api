<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSaleArticleAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::create('sale_article_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sale_id');
            $table->integer('article_id');

            $table->string('file_path');
            $table->string('original_name');
            $table->text('observation')->nullable();

            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sale_article_attachments');
    }
}
