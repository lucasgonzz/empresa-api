<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePromocionVinotecasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('promocion_vinotecas', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();
            $table->string('slug')->nullable();

            $table->integer('stock')->nullable();
            $table->decimal('cost', 20,2)->nullable();
            $table->decimal('final_price', 20,2)->nullable();
            // $table->text('image_url')->nullable();
            $table->text('description')->nullable();
            $table->integer('address_id')->nullable();
            $table->integer('user_id');
            $table->softDeletes();

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
        Schema::dropIfExists('promocion_vinotecas');
    }
}
