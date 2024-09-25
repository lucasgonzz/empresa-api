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

            // $table->integer('price_type_id')->unsigned()->default(1);

            $table->text('observations')->nullable();

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
