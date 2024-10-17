<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExpensesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->integer('num')->nullable();
            $table->integer('expense_concept_id')->nullable();
            $table->decimal('amount', 32,2)->nullable();
            $table->integer('current_acount_payment_method_id')->nullable();
            $table->text('observations')->nullable();
            $table->integer('caja_id')->nullable();
            
            $table->integer('user_id');
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
        Schema::dropIfExists('expenses');
    }
}
