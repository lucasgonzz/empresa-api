<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetPromocionVinotecaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('budget_promocion_vinoteca', function (Blueprint $table) {
            $table->id();

            $table->integer('promocion_vinoteca_id');
            $table->integer('budget_id');
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
        Schema::dropIfExists('budget_promocion_vinoteca');
    }
}
