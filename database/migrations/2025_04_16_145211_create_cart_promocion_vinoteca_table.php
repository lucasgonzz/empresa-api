<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCartPromocionVinotecaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cart_promocion_vinoteca', function (Blueprint $table) {
            $table->id();
            $table->integer('cart_id')->unsigned();
            $table->integer('promocion_vinoteca_id')->unsigned();
            $table->double('amount', 20,2);
            $table->double('price', 20,2);
            $table->double('cost', 20,2)->nullable();
            $table->text('notes')->nullable();
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
        Schema::dropIfExists('cart_promocion_vinoteca');
    }
}
