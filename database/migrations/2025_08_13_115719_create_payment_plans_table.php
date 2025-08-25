<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedInteger('cantidad_cuotas'); 
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->enum('frequency', ['monthly','weekly','biweekly','custom_days'])->default('monthly');
            $table->unsignedInteger('interval_in_days')->nullable();
            $table->date('start_date'); // primer vencimiento
            $table->decimal('interest_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
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
        Schema::dropIfExists('payment_plans');
    }
}
