<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotaCreditoDescriptionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nota_credito_descriptions', function (Blueprint $table) {
            $table->id();

            $table->text('notes')->nullable();
            $table->decimal('price', 30,2)->nullable();
            $table->integer('iva_id')->nullable();
            $table->integer('current_acount_id')->nullable();
            
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
        Schema::dropIfExists('nota_credito_descriptions');
    }
}
