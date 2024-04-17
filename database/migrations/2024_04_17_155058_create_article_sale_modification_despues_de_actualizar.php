<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleSaleModificationDespuesDeActualizar extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_sale_modification_despues_de_actualizar', function (Blueprint $table) {
            $table->id();
            $table->integer('article_id');
            $table->integer('sale_modification_id');
            $table->decimal('amount')->nullable();
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
        Schema::dropIfExists('article_sale_modification_despues_de_actualizar');
    }
}
