<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleDepositMovementTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_deposit_movement', function (Blueprint $table) {
            $table->id();

            $table->integer('article_id');
            $table->integer('deposit_movement_id');

            $table->decimal('amount', 12,2)->nullable();
            $table->integer('article_variant_id')->nullable();

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
        Schema::dropIfExists('article_deposit_movement');
    }
}
