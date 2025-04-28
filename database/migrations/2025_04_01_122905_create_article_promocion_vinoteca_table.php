<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticlePromocionVinotecaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_promocion_vinoteca', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->integer('promocion_vinoteca_id');
            $table->decimal('amount', 10,2)->nullable();
            $table->decimal('unidades_por_promo', 10,2)->nullable();

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
        Schema::dropIfExists('article_promocion_vinoteca');
    }
}
