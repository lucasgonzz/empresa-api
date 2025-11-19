<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEcommerceToArticlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->integer('peso')->nullable();
            $table->integer('profundidad')->nullable();
            $table->integer('ancho')->nullable();
            $table->integer('alto')->nullable();

            $table->boolean('disponible_tienda_nube')->nullable();
            $table->decimal('precio_promocional', 22,2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            //
        });
    }
}
