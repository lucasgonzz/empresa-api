<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSuperBudgetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('super_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('client')->nullable();
            $table->integer('hour_price')->nullable();
            $table->text('delivery_time')->nullable();
            $table->timestamp('offer_validity')->nullable();
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
        Schema::dropIfExists('super_budgets');
    }
}
