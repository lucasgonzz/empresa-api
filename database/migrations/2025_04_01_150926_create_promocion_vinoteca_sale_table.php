<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePromocionVinotecaSaleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('promocion_vinoteca_sale', function (Blueprint $table) {
            $table->id();

            $table->integer('promocion_vinoteca_id');
            $table->integer('sale_id');
            $table->integer('amount');
            $table->decimal('price', 20,2);
            
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
        Schema::dropIfExists('promocion_vinoteca_sale');
    }
}
