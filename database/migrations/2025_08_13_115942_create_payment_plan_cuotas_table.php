<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentPlanCuotasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_plan_cuotas', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('payment_plan_id');
            $table->unsignedInteger('numero_cuota'); // <- renombrado
            $table->date('fecha_vencimiento'); // <- renombrado (due_date)
            $table->decimal('amount', 12, 2);
            $table->enum('estado', ['pendiente','pagado','cancelado'])->default('pendiente');
            $table->dateTime('paid_at')->nullable();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('receipt_number')->nullable();
            $table->text('observations')->nullable();
            
            $table->integer('client_id')->nullable();
            $table->integer('sale_id')->nullable();
            $table->integer('user_id')->nullable();
            
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
        Schema::dropIfExists('payment_plan_cuotas');
    }
}
