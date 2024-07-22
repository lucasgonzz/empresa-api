<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateArticleSaleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('article_sale', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->integer('article_id');
            $table->integer('sale_id');

            $table->integer('article_variant_id')->nullable();
            $table->string('variant_description')->nullable();

            $table->decimal('amount', 8,2);
            $table->decimal('returned_amount', 25,2)->nullable();
            $table->decimal('delivered_amount', 25,2)->nullable();
            $table->decimal('discount', 8,2)->nullable();
            $table->integer('iva_id')->nullable();
            // $table->enum('measurement', ['gramo', 'kilo'])->nullable();
            // $table->enum('measurement_original', ['gramo', 'kilo'])->nullable();
            $table->decimal('cost', 25,2)->nullable();
            $table->decimal('price', 25,2)->nullable();
            $table->decimal('with_dolar')->nullable();
            $table->decimal('checked_amount', 12,2)->nullable();
            $table->decimal('unidades_individuales', 12,2)->nullable();

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
        Schema::dropIfExists('article_sale');
    }
}
