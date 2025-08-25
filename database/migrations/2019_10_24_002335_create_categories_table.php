<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->integer('num')->nullable();
            $table->string('name', 128);
            $table->string('image_url', 128)->nullable();
            $table->decimal('percentage_gain', 22,2)->nullable();
            $table->integer('user_id')->unsigned();
            $table->integer('provider_category_id')->nullable();
            $table->integer('tiendanube_category_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->foreign('user_id')
                    ->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('categories');
    }
}
