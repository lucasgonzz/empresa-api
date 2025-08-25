<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBudgetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            $table->integer('client_id')->unsigned();
            $table->integer('num')->nullable();
            $table->enum('status', ['unconfirmed', 'confirmed'])->default('unconfirmed');

            // $table->boolean('delivery_and_placement')->default(0);

            $table->timestamp('start_at')->nullable();
            $table->timestamp('finish_at')->nullable();

            $table->integer('budget_status_id')->unsigned()->default(1);
            $table->integer('price_type_id')->nullable();
            $table->integer('address_id')->nullable();

            // $table->integer('price_type_id')->unsigned()->default(1);

            $table->text('observations')->nullable();
            $table->decimal('total', 30,2)->nullable();

            
            $table->boolean('discounts_in_services')->unsigned()->default(1);
            $table->boolean('surchages_in_services')->unsigned()->default(1);

            $table->integer('employee_id')->nullable()->unsigned();
            $table->integer('user_id')->unsigned();
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
        Schema::dropIfExists('budgets');
    }
}
