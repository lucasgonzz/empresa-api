<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompanyPerformanceExpenseConceptUsdTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('company_performance_expense_concept_usd', function (Blueprint $table) {
            $table->id();
            $table->integer('company_performance_id');
            $table->integer('expense_concept_id');
            $table->decimal('amount', 30,2);
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
        Schema::dropIfExists('company_performance_expense_concept_usd');
    }
}
